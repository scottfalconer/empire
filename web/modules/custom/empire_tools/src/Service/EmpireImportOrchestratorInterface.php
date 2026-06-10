<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\taxonomy\TermInterface;

/**
 * Imports a channel's videos + runs Empire's post-processing.
 *
 * An interface so form/cron consumers depend on the seam, not the concrete
 * orchestrator, keeping them unit-testable in isolation.
 */
interface EmpireImportOrchestratorInterface {

  /**
   * Imports the channel's media + video feeds, then post-processes the result.
   *
   * @param \Drupal\taxonomy\TermInterface $channel
   *   The empire_channel term to import.
   *
   * @return array
   *   ['media' => ['count' => int, 'error' => ?string],
   *    'videos' => ['count' => int, 'error' => ?string]].
   */
  public function import(TermInterface $channel): array;

  /**
   * Runs post-import processing (channel stamp, hero, maxres thumbnails).
   *
   * @param \Drupal\taxonomy\TermInterface $channel
   *   The empire_channel term.
   * @param int $node_feed_id
   *   The video feed instance id.
   * @param bool $success
   *   Whether the import completed without a feed error; the "last imported"
   *   timestamp only advances when TRUE.
   */
  public function postProcess(TermInterface $channel, int $node_feed_id, bool $success = TRUE): void;

}
