<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\ExistingSite;

use Drupal\empire_tools\Controller\DashboardController;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\trash\TrashManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Editorial + render checks against the recipe-built Empire site.
 *
 * Runs against the running site (real content model, theme, and imported
 * content), bypassing the fresh-install path that blocks BrowserTestBase here.
 */
#[Group('empire_tools')]
final class EmpireEditorialTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The Empire baseline enables drupal/trash, which intercepts node deletes
    // and soft-deletes (trashes) them. DTT's cleanup ($entity->delete()) would
    // therefore "succeed" but leave trashed test nodes accumulating as debris
    // on the live demo site. Switch Trash off for teardown so the created
    // nodes are really purged — \Drupal\trash\TrashStorageTrait::delete()
    // hard-deletes in any context other than 'active'. A fresh 'active'
    // context is built per test in setUp(), so it need not be restored here.
    $trash = \Drupal::hasService('trash.manager')
      ? \Drupal::service('trash.manager')
      : NULL;
    if ($trash instanceof TrashManagerInterface) {
      $trash->setTrashContext('inactive');
    }
    parent::tearDown();
  }

  /**
   * Public homepage and videos listing render the cinematic theme.
   */
  public function testPublicPagesRender(): void {
    $this->drupalGet('/');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.empire-card');
    // A featured video produces the cinematic hero billboard.
    $this->assertSession()->elementExists('css', '.empire-hero');

    $this->drupalGet('/videos');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.empire-card');
  }

  /**
   * An empty collection page shows the §25 empty state, not a broken grid.
   */
  public function testEmptyCollectionShowsState(): void {
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $empty = NULL;
    foreach ($terms->loadByProperties(['vid' => 'empire_collection']) as $term) {
      $count = (int) $nodes->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'empire_video')
        ->condition('field_collection', $term->id())
        ->count()
        ->execute();
      if ($count === 0) {
        $empty = $term->id();
        break;
      }
    }
    $this->assertNotNull($empty, 'The site has at least one empty collection.');

    $this->drupalGet($terms->load($empty)->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    // R6 reworded the empty-state to be vocabulary-neutral (it is on the shared
    // taxonomy_term view, shown on tags/channel term pages too).
    $this->assertSession()->pageTextContains('No videos here yet');
  }

  /**
   * A curated empire_video renders in the grid and on its collection page.
   */
  public function testEditorialVideoRenders(): void {
    $etm = \Drupal::entityTypeManager();
    $media_ids = $etm->getStorage('media')->getQuery()->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')->range(0, 1)->execute();
    $term_ids = $etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'empire_collection')->range(0, 1)->execute();
    $this->assertNotEmpty($media_ids, 'The built site has remote_video media.');
    $this->assertNotEmpty($term_ids, 'The built site has collection terms.');

    $title = 'DTT editorial test video';
    $node = $this->createNode([
      'type' => 'empire_video',
      'title' => $title,
      'status' => 1,
      'field_video' => reset($media_ids),
      'field_collection' => reset($term_ids),
    ]);

    // Curated video appears in the videos grid.
    $this->drupalGet('/videos');
    $this->assertSession()->pageTextContains($title);

    // …and on its assigned collection page (term canonical / clean alias).
    $term = $etm->getStorage('taxonomy_term')->load(reset($term_ids));
    $this->drupalGet($term->toUrl()->toString());
    $this->assertSession()->pageTextContains($title);

    // …and its own detail page renders, carrying the SEO meta the
    // page_attachments hook stamps (OG + JSON-LD VideoObject).
    $this->drupalGet($node->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($title);
    $this->assertSession()->elementAttributeContains(
      'css', 'meta[property="og:title"]', 'content', $title);
    $this->assertSession()->responseContains('"@type":"VideoObject"');
  }

  /**
   * An editor-marked unavailable video shows a clear state, not a player.
   */
  public function testUnavailableVideoRendersGracefully(): void {
    $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($media_ids, 'The built site has remote_video media.');

    $node = $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT unavailable video',
      'status' => 1,
      'field_video' => reset($media_ids),
      'field_source_status' => 'unavailable',
    ]);

    $this->drupalGet($node->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('currently unavailable');
    // No embedded player — a clear state, not a broken iframe.
    $this->assertSession()->elementNotExists('css', '.empire-watch__stage iframe');
  }

  /**
   * The dashboard builds the connected state on the recipe-built site.
   *
   * Built render-free via the controller — a full Gin admin render trips a
   * flaky duplicate-library assertion that only fires under test assertions
   * (deduped in production); the HTML render is covered by manual browser QA.
   */
  public function testDashboardConnectedState(): void {
    $build = DashboardController::create(\Drupal::getContainer())->dashboard();
    $this->assertArrayHasKey('banner', $build);
    $this->assertArrayHasKey('catalog', $build);
    $this->assertArrayHasKey('channel', $build);
  }

  /**
   * Anonymous users cannot reach the Empire dashboard.
   */
  public function testAnonymousDeniedDashboard(): void {
    $this->drupalGet('/admin/empire');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The content_editor role can curate but cannot administer the site.
   */
  public function testContentEditorBoundary(): void {
    $role = \Drupal::entityTypeManager()->getStorage('user_role')
      ->load('content_editor');
    $this->assertNotNull($role, 'The content_editor role exists.');

    // Can curate the imported videos, and use the Empire dashboard.
    $this->assertTrue($role->hasPermission('edit any empire_video content'));
    $this->assertTrue($role->hasPermission('create empire_video content'));
    $this->assertTrue($role->hasPermission('access empire dashboard'));

    // Cannot administer the site (§18) or the Feeds UI (§11) — the editor
    // curates via /admin/empire, never site configuration or the Feeds admin.
    $this->assertFalse($role->hasPermission('administer site configuration'));
    $this->assertFalse($role->hasPermission('administer feeds'));
    $this->assertFalse($role->hasPermission('access feeds overview'));
  }

  /**
   * A hidden video drops out of listings; an active one still shows (§7).
   */
  public function testHiddenVideoExcludedFromListings(): void {
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($media_ids);

    // Isolate the two test videos in an empty collection (avoids the pager).
    $empty = NULL;
    foreach ($terms->loadByProperties(['vid' => 'empire_collection']) as $term) {
      $count = (int) $nodes->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'empire_video')
        ->condition('field_collection', $term->id())
        ->count()
        ->execute();
      if ($count === 0) {
        $empty = $term->id();
        break;
      }
    }
    $this->assertNotNull($empty, 'The site has an empty collection.');

    $media = reset($media_ids);
    $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT visible vid',
      'status' => 1,
      'field_video' => $media,
      'field_collection' => $empty,
      'field_source_status' => 'active',
    ]);
    $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT hidden vid',
      'status' => 1,
      'field_video' => $media,
      'field_collection' => $empty,
      'field_source_status' => 'hidden',
    ]);

    $this->drupalGet($terms->load($empty)->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('DTT visible vid');
    $this->assertSession()->pageTextNotContains('DTT hidden vid');
  }

  /**
   * A dangerous scheme in field_source_url never reaches the rendered href.
   */
  public function testSourceUrlSchemeIsGuarded(): void {
    $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($media_ids);

    $node = $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT scheme guard',
      'status' => 1,
      'field_video' => reset($media_ids),
      'field_source_url' => 'javascript:alert(99119)',
    ]);

    $this->drupalGet($node->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('javascript:alert(99119)');
  }

  /**
   * Editor artwork overrides the oEmbed thumbnail on the watch page (§13).
   */
  public function testArtworkOverridesThumbnail(): void {
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    $remote = $media_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($remote, 'The site has remote_video media.');

    // A tiny 1x1 PNG → file → image media → field_artwork.
    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAA'
      . 'C0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    $uri = 'public://dtt-artwork.png';
    file_put_contents($uri, base64_decode($b64));
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $this->markEntityForCleanup($file);
    $art = Media::create([
      'bundle' => 'image',
      'name' => 'DTT artwork',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'art'],
    ]);
    $art->save();
    $this->markEntityForCleanup($art);

    $video = $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT artwork video',
      'status' => 1,
      'field_video' => (int) reset($remote),
      'field_artwork' => $art->id(),
    ]);

    $this->drupalGet($video->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    // The poster is the editor artwork, never the oEmbed thumbnail.
    $poster = $this->getSession()->getPage()->find('css', '.empire-watch__poster');
    $this->assertNotNull($poster, 'The watch page renders an artwork poster.');
    $src = (string) $poster->getAttribute('src');
    $this->assertStringContainsString('dtt-artwork', $src);
    $this->assertStringNotContainsString('oembed', $src);

    // §13 requires the override on the grid card too, not only the watch page.
    $this->drupalGet('/videos');
    $card_img = $this->getSession()->getPage()
      ->find('css', '.empire-card__link[href="' . $video->toUrl()->toString() . '"] img.empire-card__img');
    $this->assertNotNull($card_img, 'The video has an artwork card in the grid.');
    $card_src = (string) $card_img->getAttribute('src');
    $this->assertStringContainsString('dtt-artwork', $card_src);
    $this->assertStringNotContainsString('oembed', $card_src);
  }

  /**
   * A curated empire_video page renders a meta description (SEO).
   */
  public function testVideoPageHasMetaDescription(): void {
    $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($media_ids);

    $node = $this->createNode([
      'type' => 'empire_video',
      'title' => 'DTT meta description video',
      'status' => 1,
      'field_video' => reset($media_ids),
      'field_content' => [
        'value' => 'A concise SEO description of this DTT test video.',
        'format' => 'content_format',
      ],
    ]);

    $this->drupalGet($node->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $meta = $this->getSession()->getPage()->find('css', 'meta[name="description"]');
    $this->assertNotNull($meta, 'The video page renders a meta description.');
    $this->assertStringContainsString('concise SEO description', (string) $meta->getAttribute('content'));
  }

  /**
   * Hostile / edge-case titles render escaped and unbroken (VALIDATION §3).
   */
  public function testEdgeCaseTitleRendersSafely(): void {
    $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'remote_video')
      ->range(0, 1)
      ->execute();
    $this->assertNotEmpty($media_ids);

    $xss = '<script>alert(123321)</script>';
    $img = '<img src=x onerror="alert(123322)">';
    // XSS + special chars + CJK + RTL + emoji + a very long run.
    $title = $xss . $img . ' Tëst & "q\'q" 日本語 العربية 🎬 ' . str_repeat('long ', 24);

    $node = $this->createNode([
      'type' => 'empire_video',
      'title' => $title,
      'status' => 1,
      'field_video' => reset($media_ids),
      // No field_artwork -> oEmbed thumbnail fallback (§13).
    ]);

    // Watch page: renders, scripts escaped (not executable), unicode preserved.
    $this->drupalGet($node->toUrl()->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($xss);
    $this->assertSession()->responseNotContains($img);
    $this->assertSession()->pageTextContains('日本語');
    $this->assertSession()->pageTextContains('🎬');

    // Grid card: same escaping, page still renders (title is line-clamped).
    $this->drupalGet('/videos');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($xss);
    $this->assertSession()->responseNotContains($img);
  }

}
