<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds the Empire dashboard status (connected channel, counts, last import).
 */
final class EmpireSetupStatus {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the single connected channel, or NULL before setup.
   *
   * V1 is single-channel in the UI; the first channel wins.
   */
  public function getConnectedChannel(): ?TermInterface {
    $channels = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'empire_channel',
    ]);
    return $channels ? reset($channels) : NULL;
  }

  /**
   * Builds a status summary for the dashboard.
   *
   * @return array
   *   Keys: connected (bool), channel_name, channel_url,
   *   last_import (int|null), video_count, media_count, featured_count.
   */
  public function getStatus(): array {
    $channel = $this->getConnectedChannel();
    $node_storage = $this->entityTypeManager->getStorage('node');

    $video_count = (int) $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->count()
      ->execute();
    $featured_count = (int) $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('field_featured', 1)
      ->count()
      ->execute();
    $media_count = (int) $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->count()
      ->execute();

    $last_import = NULL;
    if ($channel && !$channel->get('field_last_imported_at')->isEmpty()) {
      $last_import = (int) $channel->get('field_last_imported_at')->value;
    }

    return [
      'connected' => $channel !== NULL,
      'channel_name' => $channel?->label(),
      'channel_url' => $channel && !$channel->get('field_youtube_channel_url')->isEmpty()
        ? $channel->get('field_youtube_channel_url')->uri
        : NULL,
      'last_import' => $last_import,
      'video_count' => $video_count,
      'media_count' => $media_count,
      'featured_count' => $featured_count,
    ];
  }

}
