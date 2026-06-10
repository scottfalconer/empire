<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Branded 404 / 403 pages.
 *
 * The Empire app shell (page.html.twig) wraps every route, so these render
 * inside the branded nav/footer for free. Drupal sets the 404/403 HTTP status
 * around the rendered content (system.site page.404 / page.403).
 */
final class ErrorPageController extends ControllerBase {

  /**
   * The "page not found" (404) page.
   */
  public function page404(): array {
    return $this->errorPage(
      $this->t('Page not found'),
      $this->t('Sorry, we could not find that page. The link may be wrong.'),
      TRUE,
    );
  }

  /**
   * The "access denied" (403) page.
   */
  public function page403(): array {
    return $this->errorPage(
      $this->t('Access denied'),
      $this->t('You do not have permission to view this page.'),
      FALSE,
    );
  }

  /**
   * Builds a centered, branded error render array.
   */
  private function errorPage(
    string|object $heading,
    string|object $message,
    bool $offer_browse,
  ): array {
    $actions = [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-error__actions']],
      'home' => [
        '#type' => 'link',
        '#title' => $this->t('Back to home'),
        '#url' => Url::fromRoute('<front>'),
        '#attributes' => ['class' => ['btn', 'btn-accent']],
      ],
    ];
    if ($offer_browse) {
      $actions['videos'] = [
        '#type' => 'link',
        '#title' => $this->t('Browse all videos'),
        '#url' => Url::fromUri('internal:/videos'),
        '#attributes' => ['class' => ['btn', 'btn-ghost']],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['empire-error']],
      'title' => [
        '#markup' => '<h1 class="empire-error__title">' . $heading . '</h1>',
      ],
      'body' => [
        '#markup' => '<p class="empire-error__body">' . $message . '</p>',
      ],
      'actions' => $actions,
    ];
  }

}
