<?php

declare(strict_types=1);

namespace Drupal\empire_tools\EventSubscriber;

use Drupal\Core\Recipe\RecipeAppliedEvent;
use Drupal\empire_tools\Service\HomepageBuilderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Composes the Empire homepage after the Empire recipe is applied.
 */
final class RecipeSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly HomepageBuilderInterface $homepageBuilder,
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
    //
    // $event->recipe is core's @internal/experimental Recipe object; guard the
    // property read so a future core refactor degrades gracefully (we skip)
    // instead of fataling the recipe apply. RecipeSubscriberTest has a canary
    // that fails loudly if core ever drops the $name property.
    $recipe = $event->recipe;
    // @phpstan-ignore-next-line (always-true today; guards a future core drop)
    if (property_exists($recipe, 'name') && $recipe->name === 'Empire') {
      $this->homepageBuilder->compose();
      $this->homepageBuilder->composeCampaignPage();
    }
  }

}
