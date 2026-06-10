<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

/**
 * Lays out the Empire homepage + campaign Canvas pages.
 *
 * An interface so consumers (e.g. the recipe subscriber) depend on the seam,
 * not the concrete builder, which keeps them unit-testable in isolation.
 */
interface HomepageBuilderInterface {

  /**
   * Composes the streaming homepage layout into the empty Home canvas page.
   */
  public function compose(): void;

  /**
   * Composes the campaign/featured canvas page layout.
   */
  public function composeCampaignPage(): void;

}
