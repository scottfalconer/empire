<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\taxonomy\TermInterface;

/**
 * Provisions + resolves the per-channel Feeds importers.
 *
 * An interface so the orchestrator + onboarding forms depend on the seam, not
 * the concrete provisioning implementation, keeping them unit-testable.
 */
interface FeedInstanceManagerInterface {

  /**
   * Ensures the empire_channel term for a resolved channel exists.
   *
   * @param array $info
   *   The resolver output (channel_id, channel_url, feed_url, label).
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The channel term.
   */
  public function ensureChannel(array $info): TermInterface;

  /**
   * Ensures the channel's two feed instances exist.
   *
   * @param \Drupal\taxonomy\TermInterface $channel
   *   The channel term.
   *
   * @return array
   *   ['media' => FeedInterface, 'videos' => FeedInterface].
   */
  public function ensureFeeds(TermInterface $channel): array;

  /**
   * Loads the channel's two feed instances.
   *
   * @param \Drupal\taxonomy\TermInterface $channel
   *   The channel term.
   *
   * @return array
   *   ['media' => FeedInterface, 'videos' => FeedInterface].
   */
  public function getFeeds(TermInterface $channel): array;

}
