<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FeedInstanceManager $feedInstanceManager,
    private readonly ThumbnailUpgrader $thumbnailUpgrader,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Imports a channel: media, then nodes, then channel stamping.
   *
   * @return array
   *   ['media' => ['count' => int, 'error' => ?string],
   *    'videos' => ['count' => int, 'error' => ?string]].
   */
  public function import(TermInterface $channel): array {
    // The import drives two Feeds imports + a maxres thumbnail fetch per video,
    // run synchronously from the setup/refresh submit. The work self-bounds via
    // the feed + per-request HTTP timeouts; lift PHP's max_execution_time so a
    // slow channel does not hit a mid-import timeout (the synchronous design is
    // accepted — a batch is a possible future enhancement).
    @set_time_limit(0);
    $feeds = $this->feedInstanceManager->getFeeds($channel);

    $report = [
      'media' => $this->runImport($feeds['media']),
      'videos' => $this->runImport($feeds['videos']),
    ];

    $this->postProcess($channel, (int) $feeds['videos']->id());

    return $report;
  }

  /**
   * Stamps the channel, ensures a hero, and upgrades thumbnails post-import.
   *
   * Best-effort: a storage/validation error here must not white-screen
   * setup/refresh — the import itself has already run. Idempotent (fills-only,
   * features-only-if-none, skips already-hi-res), so it is safe after every
   * import — wizard, refresh, or the hourly cron run (empire_tools_cron) — so
   * cron-imported videos get stamped + upgraded too.
   */
  public function postProcess(TermInterface $channel, int $node_feed_id): void {
    try {
      $this->stampChannel($channel, $node_feed_id);
      $channel->set('field_last_imported_at', $this->time->getRequestTime())->save();
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
