<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\feeds\FeedInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Provisions the empire_channel term and the two Feeds instances per channel.
 *
 * One empire_channel term per YouTube channel; two feed instances (media then
 * node) of the exported feed types, both pointing at the channel feed URL. The
 * term ↔ feed-instance-id association is kept in state (multi-channel-ready).
 */
final class FeedInstanceManager {

  private const MEDIA_FEED_TYPE = 'empire_youtube_media';
  private const NODE_FEED_TYPE = 'empire_youtube_videos';
  private const STATE_PREFIX = 'empire_tools.channel_feeds.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
  ) {}

  /**
   * Creates or updates the empire_channel term for a resolved channel.
   *
   * @param array $info
   *   Output of ChannelInputResolver::resolve().
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The saved channel term.
   */
  public function ensureChannel(array $info): TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties([
      'vid' => 'empire_channel',
      'field_youtube_channel_id' => $info['channel_id'],
    ]);
    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $existing ? reset($existing) : $storage->create(['vid' => 'empire_channel']);

    $term->setName($info['label'] ?: ('YouTube channel ' . $info['channel_id']));
    $term->set('field_youtube_channel_id', $info['channel_id']);
    $term->set('field_youtube_channel_url', ['uri' => $info['channel_url']]);
    $term->set('field_youtube_feed_url', $info['feed_url']);
    $term->set('field_import_enabled', TRUE);
    if (!empty($info['handle'])) {
      $term->set('field_youtube_handle', $info['handle']);
    }
    $term->save();
    return $term;
  }

  /**
   * Creates (or loads) the media + node feed instances for a channel.
   *
   * @return array{media: \Drupal\feeds\FeedInterface, videos: \Drupal\feeds\FeedInterface}
   *   The media and node feed instances, keyed by role.
   */
  public function ensureFeeds(TermInterface $channel): array {
    $feed_url = (string) $channel->get('field_youtube_feed_url')->value;
    $media = $this->ensureFeed(self::MEDIA_FEED_TYPE, $channel, $feed_url, 'media');
    $videos = $this->ensureFeed(self::NODE_FEED_TYPE, $channel, $feed_url, 'videos');
    $this->state->set(self::STATE_PREFIX . $channel->id(), [
      'media' => (int) $media->id(),
      'videos' => (int) $videos->id(),
    ]);
    return ['media' => $media, 'videos' => $videos];
  }

  /**
   * Loads the feed instances for a channel, provisioning them if missing.
   *
   * @return array{media: \Drupal\feeds\FeedInterface, videos: \Drupal\feeds\FeedInterface}
   *   The media and node feed instances, keyed by role.
   */
  public function getFeeds(TermInterface $channel): array {
    $map = $this->state->get(self::STATE_PREFIX . $channel->id());
    if ($map) {
      $storage = $this->entityTypeManager->getStorage('feeds_feed');
      $media = $storage->load($map['media']);
      $videos = $storage->load($map['videos']);
      if ($media instanceof FeedInterface && $videos instanceof FeedInterface) {
        return ['media' => $media, 'videos' => $videos];
      }
    }
    return $this->ensureFeeds($channel);
  }

  /**
   * Finds or creates a single feed instance of a given type for a channel.
   */
  private function ensureFeed(string $type, TermInterface $channel, string $feed_url, string $role): FeedInterface {
    $storage = $this->entityTypeManager->getStorage('feeds_feed');
    $existing = $storage->loadByProperties(['type' => $type, 'source' => $feed_url]);
    if ($existing) {
      /** @var \Drupal\feeds\FeedInterface $first */
      $first = reset($existing);
      return $first;
    }
    /** @var \Drupal\feeds\FeedInterface $feed */
    $feed = $storage->create([
      'type' => $type,
      'title' => $channel->label() . ' (' . $role . ')',
      'source' => $feed_url,
      'uid' => 1,
      'status' => 1,
    ]);
    $feed->save();
    return $feed;
  }

}
