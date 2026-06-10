<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\empire_tools\Service\HomepageBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the Canvas component tree HomepageBuilder composes.
 *
 * The tree builders are pure (only the injected UUID service), so they are
 * exercised directly — no Canvas entity setup required.
 */
#[CoversClass(HomepageBuilder::class)]
#[Group('empire_tools')]
final class HomepageBuilderTest extends UnitTestCase {

  /**
   * Builds the service with a deterministic UUID generator.
   */
  private function builder(
    ?EntityTypeManagerInterface $etm = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?PathValidatorInterface $pathValidator = NULL,
  ): HomepageBuilder {
    $uuid = $this->createMock(UuidInterface::class);
    $counter = 0;
    $uuid->method('generate')->willReturnCallback(
      static function () use (&$counter): string {
        return 'uuid-' . (++$counter);
      },
    );
    return new HomepageBuilder(
      $etm ?? $this->createMock(EntityTypeManagerInterface::class),
      $uuid,
      new NullLogger(),
      $configFactory ?? $this->createMock(ConfigFactoryInterface::class),
      $pathValidator ?? $this->createMock(PathValidatorInterface::class),
    );
  }

  /**
   * Invokes the private loadHomePage() and returns the resolved page (or NULL).
   */
  private function loadHomePage(HomepageBuilder $builder): ?object {
    $method = new \ReflectionMethod($builder, 'loadHomePage');
    $method->setAccessible(TRUE);
    return $method->invoke($builder);
  }

  /**
   * Invokes a private tree-building method and returns the component array.
   */
  private function invokePrivate(HomepageBuilder $builder, string $method, mixed ...$args): array {
    $reflection = new \ReflectionMethod($builder, $method);
    $reflection->setAccessible(TRUE);
    return (array) $reflection->invoke($builder, ...$args);
  }

  /**
   * Collects the button labels in a flat component tree.
   *
   * @return string[]
   *   The label of every empire_theme.button in the tree.
   */
  private function buttonLabels(array $tree): array {
    $labels = [];
    foreach ($tree as $component) {
      if (($component['component_id'] ?? '') === 'sdc.empire_theme.button') {
        $labels[] = $component['inputs']['label'];
      }
    }
    return $labels;
  }

  /**
   * The homepage tree places the hero, latest row, rails, and two CTAs.
   */
  public function testHomepageTreeStructure(): void {
    $tree = $this->invokePrivate($this->builder(), 'tree');
    $ids = array_column($tree, 'component_id');
    $views = [
      'empire_featured_video-block_featured',
      'empire_latest_videos-block_latest',
      'empire_homepage_rails-block_rails',
    ];
    foreach ($views as $view) {
      $this->assertContains("block.views_block.$view", $ids);
    }
    $this->assertContains('sdc.empire_theme.heading', $ids);
    $this->assertContains('sdc.empire_theme.section', $ids);
    $this->assertContains('sdc.empire_theme.button', $ids);
  }

  /**
   * A CTA nests its button in the section's content slot with the right inputs.
   */
  public function testCtaNestsButtonInSectionSlot(): void {
    $cta = $this->invokePrivate($this->builder(), 'cta', 'Eyebrow', 'Heading', 'Go', '/path');
    $this->assertSame('sdc.empire_theme.section', $cta[0]['component_id']);
    $this->assertSame('Heading', $cta[0]['inputs']['heading']);
    $this->assertSame('Eyebrow', $cta[0]['inputs']['eyebrow']);
    $this->assertSame('sdc.empire_theme.button', $cta[1]['component_id']);
    $this->assertSame($cta[0]['uuid'], $cta[1]['parent_uuid']);
    $this->assertSame('content', $cta[1]['slot']);
    $this->assertSame('Go', $cta[1]['inputs']['label']);
    $this->assertSame('/path', $cta[1]['inputs']['url']);
  }

  /**
   * The newsletter CTA reads "Sign up", never the YouTube "Subscribe".
   */
  public function testNewsletterCtaUsesSignUpLabel(): void {
    $labels = $this->buttonLabels($this->invokePrivate($this->builder(), 'tree'));
    $this->assertContains('Sign up', $labels);
    $this->assertNotContains('Subscribe', $labels);
  }

  /**
   * The campaign tree carries the rails and the "Sign up" newsletter CTA.
   */
  public function testCampaignTreeStructure(): void {
    $tree = $this->invokePrivate($this->builder(), 'campaignTree');
    $ids = array_column($tree, 'component_id');
    $this->assertContains('block.views_block.empire_homepage_rails-block_rails', $ids);
    $this->assertContains('Sign up', $this->buttonLabels($tree));
  }

  /**
   * A heading component carries its text at level 2.
   */
  public function testHeadingComponent(): void {
    $heading = $this->invokePrivate($this->builder(), 'heading', 'Latest videos');
    $this->assertSame('sdc.empire_theme.heading', $heading['component_id']);
    $this->assertSame('Latest videos', $heading['inputs']['text']);
    $this->assertSame(2, $heading['inputs']['level']);
  }

  /**
   * A block component maps to the right Views block plugin id.
   */
  public function testBlockComponent(): void {
    $block = $this->invokePrivate($this->builder(), 'block', 'some_view-block_x');
    $this->assertSame('block.views_block.some_view-block_x', $block['component_id']);
  }

  /**
   * Resolves the configured front page (not the hard-coded baseline UUID).
   */
  public function testLoadHomePageResolvesConfiguredFrontPage(): void {
    $page = $this->createMock(ContentEntityInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($page);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('canvas_page')->willReturn($storage);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('page.front')->willReturn('/page/1');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('system.site')->willReturn($config);

    $url = $this->createMock(Url::class);
    $url->method('isRouted')->willReturn(TRUE);
    $url->method('getRouteName')->willReturn('entity.canvas_page.canonical');
    $url->method('getRouteParameters')->willReturn(['canvas_page' => '1']);
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->method('getUrlIfValid')->with('/page/1')->willReturn($url);

    $this->assertSame($page, $this->loadHomePage($this->builder($etm, $factory, $validator)));
  }

  /**
   * Falls back to the baseline UUID when page.front isn't a canvas page.
   */
  public function testLoadHomePageFallsBackToBaselineUuid(): void {
    $fallback = $this->createMock(ContentEntityInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$fallback]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('/not-a-canvas-page');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    // page.front does not resolve to a canvas_page route.
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->method('getUrlIfValid')->willReturn(NULL);

    $this->assertSame($fallback, $this->loadHomePage($this->builder($etm, $factory, $validator)));
  }

}
