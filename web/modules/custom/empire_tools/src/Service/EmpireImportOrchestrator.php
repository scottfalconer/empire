<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
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

  /**
   * Max nodes a single post-process pass scans.
   *
   * A YouTube channel-feed fetch returns at most a feed page (~15 items), so an
   * import's delta is small; capping the post-process rescan keeps stamping +
   * thumbnail-upgrading proportional to that delta, not the whole catalogue.
   */
  private const POST_PROCESS_LIMIT = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FeedInstanceManagerInterface $feedInstanceManager,
    private readonly ThumbnailUpgraderInterface $thumbnailUpgrader,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly MessengerInterface $messenger,
    private readonly StateInterface $state,
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
      // Load the import's recent nodes ONCE (bounded), then stamp + upgrade in
      // one pass instead of rescanning the channel's whole history twice.
      $nodes = $this->recentFeedNodes($node_feed_id);
      $this->stampChannel($channel, $nodes);
      if ($success) {
        $channel->set('field_last_imported_at', $this->time->getRequestTime())->save();
      }
      $this->ensureFeaturedVideo();
      $this->upgradeThumbnails($nodes);
    }
    catch (\Throwable $e) {
      $this->logger->error('Empire post-import stamping failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Loads this channel's most-recently-imported empire_video nodes (bounded).
   *
   * The node feed uses Skip + no-expire, so feeds_item.target_id matches the
   * channel's cumulative node history; ordering by the changed timestamp and
   * capping the result keeps post-processing proportional to one import's delta
   * (a feed page) rather than the whole catalogue, even under the time cap.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The loaded nodes, newest first.
   */
  private function recentFeedNodes(int $node_feed_id): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('feeds_item.target_id', $node_feed_id)
      ->sort('changed', 'DESC')
      ->range(0, self::POST_PROCESS_LIMIT)
      ->execute();
    return $node_storage->loadMultiple($ids);
  }

  /**
   * Runs Empire's post-import processing for every channel, on cron.
   *
   * For each channel whose video feed has imported something new since the last
   * run (tracked by a persisted per-channel state key), stamps + upgrades the
   * new videos. Idempotent, with a per-channel try/catch so one channel's
   * failure never aborts the rest, and it honours the field_import_enabled
   * toggle. Extracted from hook_cron() so the gate + isolation are testable.
   */
  public function cronPostProcess(): void {
    try {
      $channels = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['vid' => 'empire_channel']);
    }
    catch (\Throwable $e) {
      return;
    }
    foreach ($channels as $channel) {
      // A paused channel is not auto-imported (manual Refresh still works).
      if ($channel->hasField('field_import_enabled') && !$channel->get('field_import_enabled')->value) {
        continue;
      }
      try {
        $feeds = $this->feedInstanceManager->getFeeds($channel);
        $imported = $feeds['videos']->getImportedTime();
        $key = 'empire_tools.cron_postprocess.' . $channel->id();
        if ($imported > (int) $this->state->get($key, 0)) {
          $this->postProcess($channel, (int) $feeds['videos']->id());
          $this->state->set($key, $imported);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Empire cron post-process failed for @id: @m', [
          '@id' => $channel->id(),
          '@m' => $e->getMessage(),
        ]);
      }
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
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   The recently-imported nodes (loaded once by recentFeedNodes()).
   */
  private function upgradeThumbnails(array $nodes): void {
    foreach ($nodes as $node) {
      $media = $node->get('field_video')->entity;
      if ($media instanceof MediaInterface) {
        $this->thumbnailUpgrader->upgrade($media);
      }
    }
  }

  /**
   * Runs one feed import synchronously, capturing the count and any error.
   *
   * Feeds does NOT throw on the common import failures: a fetch failure, empty
   * feed, or lock contention (FeedsRuntimeException subclasses) is caught by
   * FeedsExecutable::handleException, which queues a messenger error/warning
   * and returns. So inferring success from "no exception thrown" would falsely
   * report a failed refresh as a clean import (and stamp last-imported). Detect
   * failure from the messages Feeds queues during THIS import: snapshot + clear
   * the messenger first so only this import's messages are read, then restore
   * the snapshot — suppressing Feeds' raw message in favour of the caller's
   * friendly one (the caller logs/shows the failure from the returned 'error').
   *
   * @return array{count: int, error: ?string}
   *   The feed's item count and an error message if the run failed.
   */
  private function runImport(FeedInterface $feed): array {
    // Snapshot + clear so only THIS import's messages are read afterwards.
    /** @var array<string, list<\Drupal\Component\Render\MarkupInterface|string>> $snapshot */
    $snapshot = $this->messenger->all();
    $this->messenger->deleteAll();
    $error = NULL;
    try {
      $feed->import();
    }
    catch (\Throwable $e) {
      $error = $e->getMessage();
    }
    /** @var array<string, list<\Drupal\Component\Render\MarkupInterface|string>> $queued */
    $queued = $this->messenger->all();
    $this->messenger->deleteAll();
    // Restore the caller's pre-existing messages; Feeds' own raw message is
    // dropped in favour of the friendly one the caller derives from 'error'.
    foreach ($snapshot as $type => $messages) {
      foreach ($messages as $message) {
        $this->messenger->addMessage($message, $type);
      }
    }
    if ($error === NULL) {
      $feeds_message = $queued[MessengerInterface::TYPE_ERROR][0]
        ?? $queued[MessengerInterface::TYPE_WARNING][0]
        ?? NULL;
      $error = $feeds_message !== NULL ? (string) $feeds_message : NULL;
    }
    if ($error !== NULL) {
      $this->logger->error('Empire import failed for feed @id: @msg', [
        '@id' => $feed->id(),
        '@msg' => $error,
      ]);
    }
    return ['count' => (int) $feed->getItemCount(), 'error' => $error];
  }

  /**
   * Stamps field_channel on this channel's freshly-imported nodes.
   *
   * Only fills empty values, so editor curation and re-imports are never
   * clobbered. Operates on the nodes loaded once by recentFeedNodes().
   *
   * @param \Drupal\taxonomy\TermInterface $channel
   *   The channel term to stamp onto the nodes.
   * @param \Drupal\node\NodeInterface[] $nodes
   *   The recently-imported nodes.
   */
  private function stampChannel(TermInterface $channel, array $nodes): void {
    foreach ($nodes as $node) {
      if ($node->get('field_channel')->isEmpty()) {
        $node->set('field_channel', ['target_id' => $channel->id()]);
        $node->save();
      }
    }
  }

}
