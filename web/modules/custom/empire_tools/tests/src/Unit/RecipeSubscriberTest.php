<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeAppliedEvent;
use Drupal\empire_tools\EventSubscriber\RecipeSubscriber;
use Drupal\empire_tools\Service\HomepageBuilderInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the recipe subscriber's "only Empire" guard.
 *
 * The subscriber composes the homepage after a recipe applies, but ONLY for the
 * Empire recipe — the guard stops an unrelated recipe (applied in the same
 * request, or a re-apply) from triggering a homepage recompose. That guard is
 * the safety mechanism, so it is worth pinning.
 */
#[CoversClass(RecipeSubscriber::class)]
#[Group('empire_tools')]
final class RecipeSubscriberTest extends UnitTestCase {

  /**
   * The Empire recipe triggers compose() + composeCampaignPage().
   */
  public function testRecomposesForEmpireRecipe(): void {
    $builder = $this->createMock(HomepageBuilderInterface::class);
    $builder->expects($this->once())->method('compose');
    $builder->expects($this->once())->method('composeCampaignPage');

    (new RecipeSubscriber($builder))
      ->onRecipeApplied($this->eventForRecipe('Empire'));
  }

  /**
   * An unrelated recipe must NOT recompose the homepage.
   */
  public function testIgnoresNonEmpireRecipes(): void {
    $builder = $this->createMock(HomepageBuilderInterface::class);
    $builder->expects($this->never())->method('compose');
    $builder->expects($this->never())->method('composeCampaignPage');

    (new RecipeSubscriber($builder))
      ->onRecipeApplied($this->eventForRecipe('drupal_cms_blog'));
  }

  /**
   * Builds a RecipeAppliedEvent whose recipe has the given name.
   *
   * The Recipe and event are final with heavy constructors; the subscriber only
   * reads ->recipe->name, so build a bare Recipe via reflection and set that.
   */
  private function eventForRecipe(string $name): RecipeAppliedEvent {
    $recipe = (new \ReflectionClass(Recipe::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(Recipe::class, 'name'))->setValue($recipe, $name);
    return new RecipeAppliedEvent($recipe);
  }

}
