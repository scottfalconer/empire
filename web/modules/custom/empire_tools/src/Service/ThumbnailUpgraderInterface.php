<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\media\MediaInterface;

/**
 * Upgrades a remote-video media's thumbnail to a locally-stored maxres image.
 *
 * An interface so the import orchestrator depends on the seam (and stays
 * unit-testable in isolation) rather than the concrete fetch/file impl.
 */
interface ThumbnailUpgraderInterface {

  /**
   * Upgrades one remote-video media's thumbnail to maxres.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The remote_video media to upgrade.
   *
   * @return bool
   *   TRUE if the thumbnail was replaced with a higher-resolution image.
   */
  public function upgrade(MediaInterface $media): bool;

}
