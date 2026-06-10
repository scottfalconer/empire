<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Composes the Empire homepage (a Canvas page) from Views blocks + SDC.
 *
 * The Drupal CMS baseline ships an empty "Home" canvas page and sets it as the
 * front page. Core's recipe content importer uses Existing::Skip, so the empire
 * recipe cannot populate that page via content. Instead this runs after the
 * empire recipe applies and composes the page in place (SPEC §14/§16: hero +
 * rows, via Views blocks + SDC — no hardcoded markup).
 */
final class HomepageBuilder {

  /**
   * The baseline "Home" canvas page UUID (drupal_cms_site_template_base).
   */
  private const HOME_PAGE_UUID = 'ff94a20d-4eee-42f8-9ec7-48ccf940d5ac';

  /**
   * A stable UUID for Empire's demo campaign landing page (so it's idempotent).
   */
  private const CAMPAIGN_PAGE_UUID = 'd1e2f3a4-b5c6-4d7e-8f90-a1b2c3d4e5f6';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UuidInterface $uuid,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Composes the homepage if it has not been laid out yet.
   *
   * Idempotent: only composes an empty page, so editor customizations and
   * re-applying the recipe never clobber an existing layout.
   */
  public function compose(): void {
    $page = $this->loadHomePage();
    if ($page === NULL) {
      $this->logger->warning('Empire homepage: no Home canvas page found to compose.');
      return;
    }
    if (!$page->get('components')->isEmpty()) {
      return;
    }
    $page->set('components', $this->tree());
    $page->save();
    $this->logger->info('Empire homepage composed (@count components).', ['@count' => $page->get('components')->count()]);
  }

  /**
   * Creates + composes Empire's demo campaign landing page (Canvas-editable).
   *
   * A standalone canvas_page at /featured, built from the same SDC palette as
   * the homepage, so a builder can open it in Canvas and recompose it: the
   * Canvas-editable page the marketplace asks templates to showcase. Idempotent
   * (keyed on a stable UUID), so editor changes and recipe re-applies never
   * clobber it; the Views rails populate once videos are imported.
   */
  public function composeCampaignPage(): void {
    $storage = $this->entityTypeManager->getStorage('canvas_page');
    if ($storage->loadByProperties(['uuid' => self::CAMPAIGN_PAGE_UUID])) {
      return;
    }
    $page = $storage->create([
      'uuid' => self::CAMPAIGN_PAGE_UUID,
      'title' => 'Featured',
      'status' => TRUE,
      'path' => ['alias' => '/featured'],
      'components' => $this->campaignTree(),
    ]);
    $page->save();
    $this->logger->info('Empire campaign landing page created at /featured.');
  }

  /**
   * The campaign page component tree (accent band + curated rails + CTA).
   */
  private function campaignTree(): array {
    return array_merge(
      $this->cta('Featured collection', 'The best of the channel', 'Browse all videos', '/videos'),
      [
        $this->heading('Latest uploads'),
        $this->block('empire_latest_videos-block_latest'),
        $this->heading('Browse by collection'),
        $this->block('empire_homepage_rails-block_rails'),
      ],
      $this->cta('Newsletter', 'Never miss a video', 'Subscribe', '/form/empire-newsletter'),
    );
  }

  /**
   * Loads the front "Home" canvas page, or NULL if unavailable.
   */
  private function loadHomePage(): ?object {
    $storage = $this->entityTypeManager->getStorage('canvas_page');
    $matches = $storage->loadByProperties(['uuid' => self::HOME_PAGE_UUID]);
    return $matches ? reset($matches) : NULL;
  }

  /**
   * Builds the homepage component tree.
   *
   * @return array
   *   A Canvas component tree: cinematic hero, latest row, collection rails.
   */
  private function tree(): array {
    return array_merge(
      [
        $this->block('empire_featured_video-block_featured'),
        $this->heading('Latest videos'),
        $this->block('empire_latest_videos-block_latest'),
        $this->block('empire_homepage_rails-block_rails'),
      ],
      // Audience-capture + about CTAs (SPEC §16).
      $this->cta('Newsletter', 'Never miss a video', 'Subscribe', '/form/empire-newsletter'),
      $this->cta('Get in touch', 'Questions or collaborations?', 'Contact us', '/form/contact-form'),
    );
  }

  /**
   * A CTA: a surface section (heading + eyebrow) with a button in its slot.
   */
  private function cta(string $eyebrow, string $heading, string $label, string $url): array {
    $section_uuid = $this->uuid->generate();
    return [
      [
        'uuid' => $section_uuid,
        'component_id' => 'sdc.empire_theme.section',
        'inputs' => ['heading' => $heading, 'eyebrow' => $eyebrow, 'tone' => 'surface'],
      ],
      [
        'parent_uuid' => $section_uuid,
        'slot' => 'content',
        'uuid' => $this->uuid->generate(),
        'component_id' => 'sdc.empire_theme.button',
        'inputs' => ['label' => $label, 'url' => $url, 'variant' => 'primary'],
      ],
    ];
  }

  /**
   * A placed Views block component.
   */
  private function block(string $id): array {
    return [
      'uuid' => $this->uuid->generate(),
      'component_id' => "block.views_block.$id",
      'inputs' => ['label' => '', 'label_display' => '0'],
    ];
  }

  /**
   * A placed heading SDC component.
   */
  private function heading(string $text): array {
    return [
      'uuid' => $this->uuid->generate(),
      'component_id' => 'sdc.empire_theme.heading',
      'inputs' => ['text' => $text, 'level' => 2, 'eyebrow' => ''],
    ];
  }

}
