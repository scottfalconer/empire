<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\empire_tools\Service\EmpireSetupStatusInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Empire dashboard at /admin/empire.
 *
 * Gin-native admin surface, matching Empire Admin.html: ready banner, catalog,
 * connected channel, collections w/ counts, and the §13 artwork nudge.
 * Reads data only — the empire_tools services own ingestion.
 */
final class DashboardController extends ControllerBase {

  /**
   * Auto-import cadence hint in seconds.
   *
   * Mirrors the empire_youtube_* feed types' import_period; the real cadence is
   * driven by Feeds, not this value. Used only for the dashboard "next check".
   */
  private const AUTO_IMPORT_PERIOD = 3600;

  public function __construct(
    private readonly EmpireSetupStatusInterface $setupStatus,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('empire_tools.setup_status'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the dashboard.
   */
  public function dashboard(): array {
    $status = $this->setupStatus->getStatus();

    // First-run empty state.
    if (!$status['connected']) {
      return [
        '#attached' => ['library' => ['empire_tools/dashboard']],
        // Re-render once a channel is connected (empire_channel term added).
        '#cache' => [
          'tags' => ['taxonomy_term_list:empire_channel'],
          'contexts' => ['user.permissions'],
        ],
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

    // Auto-import cadence (see self::AUTO_IMPORT_PERIOD). Show the next check
    // time only while it's still in the future, else just the hourly cadence.
    $next_check = (int) ($status['last_import'] ?? 0) + self::AUTO_IMPORT_PERIOD;
    $cadence = $next_check > $this->time->getRequestTime()
      ? $this->t('Checked automatically every hour — next check around @when.', [
        '@when' => $this->dateFormatter->format($next_check, 'short'),
      ])
      : $this->t('New uploads are checked automatically every hour.');

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
      // The counts read live entities — invalidate when any of them change.
      '#cache' => [
        'tags' => [
          'node_list:empire_video',
          'media_list',
          'taxonomy_term_list:empire_collection',
          'taxonomy_term_list:empire_channel',
        ],
        'contexts' => ['user.permissions'],
      ],
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
    // The catalog count is otherwise a dead end — link out to bulk curation.
    $build['catalog']['manage'] = $this->videoListLink($this->t('Manage videos'));

    // Connected channel + actions.
    $build['channel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-dash__card']],
      'h' => ['#markup' => '<h3>' . $this->t('Connected channel') . '</h3>'],
      'name' => ['#markup' => '<p class="empire-dash__stat">' . Html::escape((string) $status['channel_name']) . '</p>'],
      'meta' => ['#markup' => '<p class="empire-dash__muted">' . $this->t('Last import: @when', ['@when' => $last]) . '</p>'],
      'cadence' => ['#markup' => '<p class="empire-dash__muted">' . $cadence . '</p>'],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['empire-dash__actions']],
        'refresh' => $this->actionLink($this->t('Refresh now'), 'empire_tools.refresh', TRUE),
        'reconfigure' => $this->actionLink($this->t('Connect a different channel'), 'empire_tools.setup', FALSE),
      ],
    ];

    // Link out to the live YouTube channel (channel_url is a resolved https
    // youtube.com URL; guard the scheme before building an external link).
    if (is_string($status['channel_url']) && str_starts_with($status['channel_url'], 'https://')) {
      $build['channel']['actions']['visit'] = [
        '#type' => 'link',
        '#title' => $this->t('Visit channel on YouTube'),
        '#url' => Url::fromUri($status['channel_url']),
        '#attributes' => [
          'class' => ['button'],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ];
    }

    // Quiet link to the contextual help (hook_help) page — only for users who
    // can reach it (the core help page needs 'access administration pages').
    if ($this->currentUser()->hasPermission('access administration pages')) {
      $build['channel']['help'] = [
        '#type' => 'link',
        '#title' => $this->t('How Empire works'),
        '#url' => Url::fromRoute('help.page', ['name' => 'empire_tools']),
        '#attributes' => ['class' => ['empire-dash__link']],
      ];
    }

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
    if ($needs_artwork > 0) {
      $build['artwork']['link'] = $this->videoListLink($this->t('Review videos'));
    }

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

  /**
   * A quiet link to the empire_video content list for bulk curation.
   */
  private function videoListLink(string|object $title): array {
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('system.admin_content', [], ['query' => ['type' => 'empire_video']]),
      '#attributes' => ['class' => ['empire-dash__link']],
    ];
  }

}
