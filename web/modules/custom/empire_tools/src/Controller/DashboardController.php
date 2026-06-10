<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\empire_tools\Service\EmpireSetupStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Empire dashboard at /admin/empire (SPEC §11).
 *
 * Gin-native admin surface, matching Empire Admin.html: ready banner, catalog,
 * connected channel, collections w/ counts, and the §13 artwork nudge.
 * Reads data only — the empire_tools services own ingestion.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly EmpireSetupStatus $setupStatus,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('empire_tools.setup_status'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the dashboard.
   */
  public function dashboard(): array {
    $status = $this->setupStatus->getStatus();

    // First-run empty state (SPEC §25).
    if (!$status['connected']) {
      return [
        '#attached' => ['library' => ['empire_tools/dashboard']],
        'empty' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['empire-dash__card', 'empire-dash__empty']],
          'h' => ['#markup' => '<h2>' . $this->t('Connect your YouTube channel') . '</h2>'],
          'p' => ['#markup' => '<p>' . $this->t('Paste your channel and Empire builds your video site — videos, collections, and a cinematic home, automatically.') . '</p>'],
          'cta' => [
            '#type' => 'link',
            '#title' => $this->t('Set up Empire'),
            '#url' => Url::fromRoute('empire_tools.setup'),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ],
      ];
    }

    $last = $status['last_import']
      ? $this->dateFormatter->format($status['last_import'], 'short')
      : $this->t('Never');

    $node_storage = $this->entityTypeManager()->getStorage('node');
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');

    // Collections with their published-video counts.
    $collections = [];
    foreach ($term_storage->loadByProperties(['vid' => 'empire_collection']) as $term) {
      $collections[$term->label()] = (int) $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'empire_video')
        ->condition('status', 1)
        ->condition('field_collection', $term->id())
        ->count()
        ->execute();
    }
    $collection_total = count(array_filter($collections));

    // Videos still on their YouTube thumbnail (no custom artwork) — §13.
    $needs_artwork = (int) $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'empire_video')
      ->condition('status', 1)
      ->notExists('field_artwork')
      ->count()
      ->execute();

    $build = [
      '#attached' => ['library' => ['empire_tools/dashboard']],
      '#prefix' => '<div class="empire-dash">',
      '#suffix' => '</div>',
    ];

    // Ready banner — the site is live.
    $build['banner'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card', 'empire-dash__banner']],
      'text' => [
        '#markup' => '<div><h2>' . $this->t('Your video site is live') . '</h2><p>'
        . $this->t('Connected to %name · last import @when', [
          '%name' => $status['channel_name'],
          '@when' => $last,
        ]) . '</p></div>',
      ],
      'view' => [
        '#type' => 'link',
        '#title' => $this->t('View site'),
        '#url' => Url::fromUri('internal:/'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    // Catalog stats.
    $build['catalog'] = $this->statCard($this->t('Catalog'), [
      [$this->t('Videos'), $status['video_count']],
      [$this->t('Featured'), $status['featured_count']],
      [$this->t('Playback media'), $status['media_count']],
      [$this->t('Collections in use'), $collection_total],
    ]);

    // Connected channel + actions.
    $build['channel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card']],
      'h' => ['#markup' => '<h3>' . $this->t('Connected channel') . '</h3>'],
      'name' => ['#markup' => '<p class="empire-dash__stat">' . Html::escape((string) $status['channel_name']) . '</p>'],
      'meta' => ['#markup' => '<p class="empire-dash__muted">' . $this->t('Last import: @when', ['@when' => $last]) . '</p>'],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['empire-dash__actions']],
        'refresh' => $this->actionLink($this->t('Refresh now'), 'empire_tools.refresh', TRUE),
        'reconfigure' => $this->actionLink($this->t('Connect a different channel'), 'empire_tools.setup', FALSE),
      ],
    ];

    // Collections with counts.
    $items = [];
    foreach ($collections as $name => $count) {
      $items[] = [
        '#markup' => '<li><span>' . Html::escape((string) $name) . '</span><b>'
        . $this->formatPlural($count, '1 video', '@count videos') . '</b></li>',
      ];
    }
    $build['collections'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card']],
      'h' => ['#markup' => '<h3>' . $this->t('Collections') . '</h3>'],
      'list' => [
        '#prefix' => '<ul class="empire-dash__list">',
        '#suffix' => '</ul>',
        'items' => $items,
      ],
    ];

    // Artwork-to-improve (§13).
    $build['artwork'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card', 'empire-dash__nudge']],
      'h' => ['#markup' => '<h3>' . $this->t('Artwork to improve') . '</h3>'],
      'body' => [
        '#markup' => $needs_artwork > 0
          ? '<p>' . $this->formatPlural(
            $needs_artwork,
            '<b>1 video</b> uses its YouTube thumbnail. Upload a clean poster for a more polished wall.',
            '<b>@count videos</b> use their YouTube thumbnail. Upload clean posters for a more polished wall.'
        ) . '</p>'
          : '<p>' . $this->t('Every video has custom artwork. Nice.') . '</p>',
      ],
    ];

    return $build;
  }

  /**
   * A card showing a list of label → number stats.
   */
  private function statCard(string|object $title, array $stats): array {
    $items = [];
    foreach ($stats as [$label, $value]) {
      $items[] = ['#markup' => '<li><span>' . $label . '</span><b>' . (int) $value . '</b></li>'];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card']],
      'h' => ['#markup' => '<h3>' . $title . '</h3>'],
      'list' => [
        '#prefix' => '<ul class="empire-dash__list">',
        '#suffix' => '</ul>',
        'items' => $items,
      ],
    ];
  }

  /**
   * Builds a button link to a route.
   */
  private function actionLink(string|object $title, string $route, bool $primary): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute($route),
      '#attributes' => ['class' => array_filter(['button', $primary ? 'button--primary' : ''])],
    ];
  }

}
