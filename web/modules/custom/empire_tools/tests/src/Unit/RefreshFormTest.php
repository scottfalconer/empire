<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\empire_tools\Form\RefreshForm;
use Drupal\empire_tools\Service\EmpireImportOrchestratorInterface;
use Drupal\empire_tools\Service\EmpireSetupStatus;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the "Refresh now" form.
 *
 * Covers the no-channel guard (build links to setup, submit no-ops) and the two
 * import outcomes (success status vs. could-not-reach warning).
 */
#[CoversClass(RefreshForm::class)]
#[Group('empire_tools')]
final class RefreshFormTest extends UnitTestCase {

  /**
   * The build links to setup when no channel is connected.
   */
  public function testBuildLinksToSetupWhenNoChannel(): void {
    $build = $this->form([])->buildForm([], new FormState());
    $this->assertArrayHasKey('setup', $build);
    $this->assertArrayNotHasKey('actions', $build);
  }

  /**
   * The build offers a Refresh button when a channel is connected.
   */
  public function testBuildOffersRefreshWhenConnected(): void {
    $build = $this->form([$this->channel()])->buildForm([], new FormState());
    $this->assertArrayHasKey('actions', $build);
    $this->assertArrayNotHasKey('setup', $build);
  }

  /**
   * Submitting with no channel must not import.
   */
  public function testSubmitNoOpsWhenNoChannel(): void {
    $orchestrator = $this->createMock(EmpireImportOrchestratorInterface::class);
    $orchestrator->expects($this->never())->method('import');

    $build = [];
    $this->form([], $orchestrator)->submitForm($build, new FormState());
  }

  /**
   * A clean import reports success, not a warning.
   */
  public function testSubmitReportsSuccess(): void {
    $orchestrator = $this->createMock(EmpireImportOrchestratorInterface::class);
    $orchestrator->method('import')->willReturn([
      'media' => ['count' => 1, 'error' => NULL],
      'videos' => ['count' => 15, 'error' => NULL],
    ]);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $form = $this->form([$this->channel()], $orchestrator);
    $form->setMessenger($messenger);
    $build = [];
    $form->submitForm($build, new FormState());
  }

  /**
   * An import error reports a "could not reach YouTube" warning.
   */
  public function testSubmitWarnsOnImportError(): void {
    $orchestrator = $this->createMock(EmpireImportOrchestratorInterface::class);
    $orchestrator->method('import')->willReturn([
      'media' => ['count' => 0, 'error' => 'network down'],
      'videos' => ['count' => 0, 'error' => NULL],
    ]);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $messenger->expects($this->never())->method('addStatus');

    $form = $this->form([$this->channel()], $orchestrator);
    $form->setMessenger($messenger);
    $build = [];
    $form->submitForm($build, new FormState());
  }

  /**
   * A busy (locked) import says so, not "could not reach YouTube".
   */
  public function testSubmitWarnsWhenBusy(): void {
    $orchestrator = $this->createMock(EmpireImportOrchestratorInterface::class);
    $orchestrator->method('import')->willReturn([
      'busy' => TRUE,
      'media' => ['count' => 0, 'error' => 'busy'],
      'videos' => ['count' => 0, 'error' => 'busy'],
    ]);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning')
      ->with($this->callback(static fn ($m): bool => str_contains((string) $m, 'already running')));
    $messenger->expects($this->never())->method('addStatus');

    $form = $this->form([$this->channel()], $orchestrator);
    $form->setMessenger($messenger);
    $build = [];
    $form->submitForm($build, new FormState());
  }

  /**
   * A thrown exception from import() is caught: error + redirect, no WSOD.
   *
   * The orchestrator's import() re-provisions feeds via getFeeds(), which can
   * throw EntityStorageException; RefreshForm must guard it the way SetupForm
   * already guards onboarding (review finding R4).
   */
  public function testSubmitGuardsAgainstThrowingImport(): void {
    $orchestrator = $this->createMock(EmpireImportOrchestratorInterface::class);
    $orchestrator->method('import')
      ->willThrowException(new \RuntimeException('storage boom'));
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');
    $messenger->expects($this->never())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $form = $this->form([$this->channel()], $orchestrator, $logger);
    $form->setMessenger($messenger);
    $build = [];
    $form_state = new FormState();
    // Must not throw — the guard catches it and lands on the dashboard.
    $form->submitForm($build, $form_state);
    $this->assertSame('empire_tools.dashboard', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Builds a RefreshForm whose setup status returns the given channels.
   */
  private function form(
    array $channels,
    ?EmpireImportOrchestratorInterface $orchestrator = NULL,
    ?LoggerInterface $logger = NULL,
  ): RefreshForm {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn($channels);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);
    $form = new RefreshForm(
      new EmpireSetupStatus($etm),
      $orchestrator ?? $this->createMock(EmpireImportOrchestratorInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /**
   * A mock empire_channel term with a label.
   */
  private function channel(): TermInterface {
    $channel = $this->createMock(TermInterface::class);
    $channel->method('label')->willReturn('Tom Scott');
    return $channel;
  }

}
