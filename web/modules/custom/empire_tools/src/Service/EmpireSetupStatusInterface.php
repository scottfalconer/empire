<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\taxonomy\TermInterface;

/**
 * Reports Empire's connection + import status for the dashboard and forms.
 *
 * An interface so the dashboard + forms depend on the seam, keeping them
 * unit-testable in isolation.
 */
interface EmpireSetupStatusInterface {

  /**
   * Returns the active (most-recently-connected) channel term, if any.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The channel term, or NULL if none is connected.
   */
  public function getConnectedChannel(): ?TermInterface;

  /**
   * Returns the dashboard status summary.
   *
   * @return array
   *   The connection + import status fields.
   */
  public function getStatus(): array;

}
