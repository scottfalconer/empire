<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\empire_tools\Service\EmpireImportOrchestratorInterface;
use Drupal\empire_tools\Service\EmpireSetupStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-runs the import for the connected channel ("Refresh now").
 */
final class RefreshForm extends FormBase {

  public function __construct(
    private readonly EmpireSetupStatus $setupStatus,
    private readonly EmpireImportOrchestratorInterface $orchestrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('empire_tools.setup_status'),
      $container->get('empire_tools.import_orchestrator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'empire_tools_refresh';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $channel = $this->setupStatus->getConnectedChannel();
    if ($channel === NULL) {
      $form['none'] = [
        '#markup' => '<p>' . $this->t('Connect a YouTube channel first, then you can refresh for new videos.') . '</p>',
      ];
      $form['setup'] = [
        '#type' => 'link',
        '#title' => $this->t('Set up your video site'),
        '#url' => Url::fromRoute('empire_tools.setup'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
      return $form;
    }
    $form['info'] = [
      '#markup' => '<p>' . $this->t('Check %channel for new uploads and import them. Existing videos and your edits are kept.', ['%channel' => $channel->label()]) . '</p>',
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh now'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $channel = $this->setupStatus->getConnectedChannel();
    if ($channel === NULL) {
      return;
    }
    $report = $this->orchestrator->import($channel);
    if ($report['media']['error'] || $report['videos']['error']) {
      $this->messenger()->addWarning($this->t('Refresh could not reach YouTube right now. Please try again shortly.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Refreshed %channel — @count videos in your library.', [
        '%channel' => $channel->label(),
        '@count' => $report['videos']['count'],
      ]));
    }
    $form_state->setRedirect('empire_tools.dashboard');
  }

}
