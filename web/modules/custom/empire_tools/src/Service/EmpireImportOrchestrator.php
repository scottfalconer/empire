<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\feeds\FeedInterface;
use Drupal\media\MediaInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the two-feed import in order and reports the result.
 *
 * Media feed first (creates/dedupes Remote Video media), node feed second
 * (creates empire_video nodes + attaches the media). Never bypasses Feeds
 * mapping — it just drives Feeds. Afterwards it stamps the channel reference
 * that the syndication parser cannot map, without overwriting
 * editor-curated values.
 */
final class EmpireImportOrchestrator implements EmpireImportOrchestratorInterface {

  /**
   * Seconds the per-channel import lock is held before it auto-expires.
   *
   * Comfortably above the worst-case synchronous import (bounded by the feed +
   * per-request HTTP timeouts and the execution cap below), so a real import
   * never loses its lock, while a crashed worker's stale lock clears in minutes
   * rather than blocking Refresh indefinitely.
   */
  private const IMPORT_LOCK_TIMEOUT = 600.0;

  /**
   * Max seconds a synchronous import may run before PHP aborts it.
   */
  private const IMPORT_TIME_LIMIT = 300;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FeedInstanceManager $feedInstanceManager,
    private readonly ThumbnailUpgrader $thumbnailUpgrader,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Imports a channel: media, then nodes, then channel stamping.
   *
   * @return array
   *   ['media' => ['count' => int, 'error' => ?string],
   *    'videos' => ['count' => int, 'error' => ?string]].
   *   A concurrent import that cannot acquire the lock returns the same shape
   *   plus ['busy' => TRUE].
   */
  public function import(TermInterface $channel): array {
    // Guard against a double-click, or an overlapping Refresh + hourly cron,
    // running two imports against the same channel's feeds at once. A second
    // caller gets a "busy" report (surfaced to the user as a warning) instead.
    $lock_id = 'empire_tools.import.' . $channel->id();
    if (!$this->lock->acquire($lock_id, self::IMPORT_LOCK_TIMEOUT)) {
      $busy = 'An import is already running for this channel — please wait a moment and try again.';
      return [
        'busy' => TRUE,
        'media' => ['count' => 0, 'error' => $busy],
        'videos' => ['count' => 0, 'error' => $busy],
      ];
    }

    try {
      // The import drives two Feeds imports + a maxres thumbnail fetch per
      // video, run synchronously from the setup/refresh submit. The work
      // self-bounds via the feed + per-request HTTP timeouts; raise PHP's
      // max_execution_time to a finite cap so a slow channel does not hit a
      // mid-import timeout, yet a hung fetch cannot pin a worker forever (the
      // synchronous design is accepted — a batch is a possible future
      // enhancement).
      @set_time_limit(self::IMPORT_TIME_LIMIT);
      $feeds = $this->feedInstanceManager->getFeeds($channel);

      $report = [
        'media' => $this->runImport($feeds['media']),
        'videos' => $this->runImport($feeds['videos']),
      ];

      // Stamp "last imported" only on a clean run (see postProcess()).
      $success = empty($report['media']['error']) && empty($report['videos']['error']);
      $this->postProcess($channel, (int) $feeds['videos']->id(), $success);

      return $report;
    }
    finally {
      $this->lock->release($lock_id);
    }
  }

  /**
   * Stamps the channel, ensures a hero, and upgrades thumbnails post-import.
   *
   * Best-effort: a storage/validation error here must not white-screen
   * setup/refresh — the import itself has already run. Idempotent (fills-only,
   * features-only-if-none, skips already-hi-res), so it is safe after every
   * import — wizard, refresh, or the hourly cron run (empire_tools_cron) — so
   * cron-imported videos get stamped + upgraded too.
   *
   * The "last imported" timestamp advances only when $success is TRUE, so a run
   * where a feed errored (YouTube unreachable, zero items) does not make the
   * dashboard claim a fresh import that brought in nothing. The stamp/hero/
   * thumbnail steps for whatever DID import still run regardless.
   */
  public function postProcess(TermInterface $channel, int $node_feed_id, bool $success = TRUE): void {
    try {
      $this->stampChannel($channel, $node_feed_id);
      if ($success) {
        $channel->set('field_last_imported_at', $this->time->getRequestTime())->save();
      }
      $this->ensureFeaturedVideo();
      $this->upgradeThumbnails($node_feed_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('Empire post-import stamping failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Marks the most-recent video as featured if none is featured yet.
   *
   * Gives a freshly-onboarded site a hero billboard out of the box (the hero
   * renders field_featured). Idempotent: runs only when zero videos are
   * featured. Non-destructive: once any video is featured (by this method or an
   * editor) it never runs again, so an editor's choice is never clobbered.
   */
  private function ensureFeaturedVideo(): void {
    $node_storage = $this->entityTypeManager->getStorage('node');

    $already_featured = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('field_featured', 1)
      ->range(0, 1)
      ->execute();
    if ($already_featured) {
      return;
    }

    $latest = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('status', 1)
      ->sort('field_source_published', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$latest) {
      return;
    }

    $node = $node_storage->load((int) reset($latest));
    if ($node !== NULL) {
      $node->set('field_featured', TRUE)->save();
    }
  }

  /**
   * Upgrades this channel's freshly-imported video thumbnails to maxres.
   *
   * Best-effort + idempotent (the upgrader skips media already hi-res and older
   * videos that have no maxres). Keeps the cinematic layout sharp without
   * hotlinking YouTube (the images are stored locally — consent-clean, §19).
   */
  private function upgradeThumbnails(int $node_feed_id): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('feeds_item.target_id', $node_feed_id)
      ->execute();
    foreach ($node_storage->loadMultiple($ids) as $node) {
      $media = $node->get('field_video')->entity;
      if ($media instanceof MediaInterface) {
        $this->thumbnailUpgrader->upgrade($media);
      }
    }
  }

  /**
   * Runs one feed import synchronously, capturing the count and any error.
   *
   * @return array{count: int, error: ?string}
   *   The feed's item count and an error message if the import failed.
   */
  private function runImport(FeedInterface $feed): array {
    try {
      $feed->import();
      return ['count' => (int) $feed->getItemCount(), 'error' => NULL];
    }
    catch (\Throwable $e) {
      $this->logger->error('Empire import failed for feed @id: @msg', [
        '@id' => $feed->id(),
        '@msg' => $e->getMessage(),
      ]);
      return ['count' => (int) $feed->getItemCount(), 'error' => $e->getMessage()];
    }
  }

  /**
   * Stamps field_channel on this channel's freshly-imported nodes.
   *
   * Only fills empty values, so editor curation and re-imports are never
   * clobbered. The node feed is matched via its feeds_item tracking field.
   */
  private function stampChannel(TermInterface $channel, int $node_feed_id): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('feeds_item.target_id', $node_feed_id)
      ->execute();
    if (!$ids) {
      return;
    }
    foreach ($node_storage->loadMultiple($ids) as $node) {
      if ($node->get('field_channel')->isEmpty()) {
        $node->set('field_channel', ['target_id' => $channel->id()]);
        $node->save();
      }
    }
  }

}
