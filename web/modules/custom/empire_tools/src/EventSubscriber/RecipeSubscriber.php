<?php

declare(strict_types=1);

namespace Drupal\empire_tools\EventSubscriber;

use Drupal\Core\Recipe\RecipeAppliedEvent;
use Drupal\empire_tools\Service\HomepageBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Composes the Empire homepage after the Empire recipe is applied.
 */
final class RecipeSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly HomepageBuilder $homepageBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [RecipeAppliedEvent::class => 'onRecipeApplied'];
  }

  /**
   * Reacts to a recipe being applied.
   */
  public function onRecipeApplied(RecipeAppliedEvent $event): void {
    // Core's RecipeAppliedEvent guidance discourages writing content from a
    // subscriber. We do so deliberately: core's content importer (Skip mode)
    // cannot populate the baseline's empty Home canvas page, and there is no
    // recipe-native hook for it. Safe: it runs only for the Empire recipe
    // (applied standalone/last) and compose() is idempotent (lays out only an
    // empty page), so a re-apply or chained recipe cannot clobber a layout.
    if ($event->recipe->name === 'Empire') {
      $this->homepageBuilder->compose();
      $this->homepageBuilder->composeCampaignPage();
    }
  }

}
