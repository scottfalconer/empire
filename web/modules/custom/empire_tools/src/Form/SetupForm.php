<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\empire_tools\Service\ChannelInputResolver;
use Drupal\empire_tools\Service\EmpireImportOrchestrator;
use Drupal\empire_tools\Service\EmpireSetupStatus;
use Drupal\empire_tools\Service\FeedInstanceManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Onboarding form — paste a YouTube channel, Empire builds the site (SPEC §10).
 */
final class SetupForm extends FormBase {

  public function __construct(
    private readonly ChannelInputResolver $resolver,
    private readonly FeedInstanceManager $feedInstanceManager,
    private readonly EmpireImportOrchestrator $orchestrator,
    private readonly EmpireSetupStatus $setupStatus,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('empire_tools.channel_resolver'),
      $container->get('empire_tools.feed_instance_manager'),
      $container->get('empire_tools.import_orchestrator'),
      $container->get('empire_tools.setup_status'),
      $container->get('logger.channel.empire_tools'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'empire_tools_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'empire-setup';
    $form['#attached']['library'][] = 'empire_tools/setup';

    $status = $this->setupStatus->getStatus();
    $connected = !empty($status['connected']);

    // Already connected: lead with a clear "all set up" panel (live site +
    // dashboard links), so returning here isn't a blank first-run form. The
    // channel form drops into a collapsed "connect a different channel" panel.
    if ($connected) {
      $form['done'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['empire-setup__done']],
        'eyebrow' => ['#markup' => '<p class="empire-setup__eyebrow">' . $this->t('All set up') . '</p>'],
        'lead' => ['#markup' => '<h2 class="empire-setup__lead">' . $this->t('Your video site is live') . '</h2>'],
        'sub' => ['#markup' => '<p class="empire-setup__sub">' . $this->t('Connected to @channel. New uploads are checked automatically — feature videos, organise collections, and upload artwork any time.', ['@channel' => $status['channel_name']]) . '</p>'],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['empire-setup__actions']],
          'view' => [
            '#type' => 'link',
            '#title' => $this->t('View your site'),
            '#url' => Url::fromRoute('<front>'),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
          'dashboard' => [
            '#type' => 'link',
            '#title' => $this->t('Go to the dashboard'),
            '#url' => Url::fromRoute('empire_tools.dashboard'),
            '#attributes' => ['class' => ['button']],
          ],
        ],
      ];
    }

    // The channel form: front-and-centre on first run; a collapsed
    // "connect a different channel" panel once a channel is connected. A
    // container/details wrapper doesn't nest the value tree, so the submit
    // handler still reads `channel` at the top level.
    $form['change'] = [
      '#type' => $connected ? 'details' : 'container',
      '#title' => $connected ? $this->t('Connect a different channel') : NULL,
      '#open' => !$connected,
    ];
    if (!$connected) {
      $form['change']['welcome'] = [
        '#markup' => '<p class="empire-setup__eyebrow">' . $this->t('Welcome to Empire') . '</p>'
        . '<h2 class="empire-setup__lead">' . $this->t('Let’s build your video site') . '</h2>'
        . '<p class="empire-setup__sub">' . $this->t('Paste your YouTube channel and Empire brings in your videos, builds a cinematic homepage, and sorts them into collections. Takes about a minute — no YouTube login or API key needed.') . '</p>',
      ];
    }
    $form['change']['channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your YouTube channel'),
      '#placeholder' => 'dangertv, @yourchannel, a channel URL, or a channel ID',
      '#description' => $this->t('Accepts a handle (with or without the @), a channel URL, a UC… channel ID, or a feed URL.'),
      '#required' => TRUE,
      '#maxlength' => 512,
    ];
    $form['change']['actions']['#type'] = 'actions';
    $form['change']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $connected ? $this->t('Rebuild from this channel') : $this->t('Build my site'),
      '#button_type' => 'primary',
    ];

    // "What happens next" — warm, jargon-free reassurance (first run only).
    if (!$connected) {
      $form['change']['next'] = [
        '#prefix' => '<ol class="empire-setup__steps">',
        '#suffix' => '</ol>',
        '#weight' => 100,
        'step1' => ['#markup' => '<li><span>' . $this->t('Paste your channel') . '</span></li>'],
        'step2' => ['#markup' => '<li><span>' . $this->t('We bring in your recent videos') . '</span></li>'],
        'step3' => ['#markup' => '<li><span>' . $this->t('Your site is ready to share') . '</span></li>'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $input = trim((string) $form_state->getValue('channel'));
    $info = $this->resolver->resolve($input);

    if ($info === NULL) {
      $this->messenger()->addError($this->t("We couldn't find that YouTube channel. Check the handle, or paste the full channel URL."));
      return;
    }

    // Provisioning saves the channel term and the feed instances; an entity
    // storage/validation error here must not white-screen the wizard (SPEC §6,
    // §25). The import itself is already internally guarded.
    try {
      $channel = $this->feedInstanceManager->ensureChannel($info);
      $this->feedInstanceManager->ensureFeeds($channel);
      $report = $this->orchestrator->import($channel);
    }
    catch (\Throwable $e) {
      $this->logger->error('Empire setup failed to provision the channel: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Something went wrong while building your site. Please try again in a moment.'));
      return;
    }

    $count = $report['videos']['count'];
    $name = $channel->label();
    $had_error = !empty($report['media']['error']) || !empty($report['videos']['error']);

    // Full success → "ta-da", straight to the live site.
    if ($count > 0 && !$had_error) {
      $this->messenger()->addStatus($this->t('Your site is ready — we brought in @count videos from @channel. New uploads are checked automatically; feature videos, organise collections, and upload custom artwork any time.', [
        '@count' => $count,
        '@channel' => $name,
      ]));
      $form_state->setRedirect('<front>');
      return;
    }

    // Anything less than a clean import lands on the dashboard — warn before
    // celebrating (SPEC §25), with Refresh available to try again.
    if ($count > 0) {
      $this->messenger()->addWarning($this->t('@count videos from @channel are in, but a few could not be fetched right now — they will be picked up on the next automatic check, or use Refresh to try again.', [
        '@count' => $count,
        '@channel' => $name,
      ]));
    }
    elseif ($had_error) {
      $this->messenger()->addWarning($this->t('We connected @channel, but could not fetch videos right now. Some videos may be private, removed, or temporarily unavailable — try Refresh in a moment.', [
        '@channel' => $name,
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('We found @channel, but YouTube did not return any recent public videos.', [
        '@channel' => $name,
      ]));
    }

    $form_state->setRedirect('empire_tools.dashboard');
  }

}
