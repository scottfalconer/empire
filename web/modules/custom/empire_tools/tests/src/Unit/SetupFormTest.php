<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\empire_tools\Form\SetupForm;
use Drupal\empire_tools\Service\ChannelInputResolver;
use Drupal\empire_tools\Service\EmpireImportOrchestrator;
use Drupal\empire_tools\Service\EmpireSetupStatus;
use Drupal\empire_tools\Service\FeedInstanceManager;
use Drupal\empire_tools\Service\ThumbnailUpgrader;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the Empire setup form's failure handling.
 *
 * The setup wizard provisions entities (the channel term + the two feed
 * instances) synchronously on submit. A storage/validation error there must be
 * caught + logged + surfaced as a plain-language message, never bubble to a
 * white screen (never hard-crash setup; error states).
 */
#[CoversClass(SetupForm::class)]
#[Group('empire_tools')]
final class SetupFormTest extends UnitTestCase {

  /**
   * A valid bare UC… channel ID — resolves offline, with no HTTP fetch.
   */
  private const CHANNEL_ID = 'UCBJycsmduvYEL83R_U4JriQ';

  /**
   * A provisioning exception is caught + logged, not surfaced as a WSOD.
   */
  public function testProvisioningFailureIsCaughtAndLogged(): void {
    // A term whose save() throws — i.e. entity storage fails mid-provision.
    $term = $this->createMock(TermInterface::class);
    $term->method('setName')->willReturnSelf();
    $term->method('set')->willReturnSelf();
    $term->method('save')->willThrowException(new \RuntimeException('storage down'));

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('loadByProperties')->willReturn([]);
    $term_storage->method('create')->willReturn($term);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($term_storage);

    // Real resolver: a bare UC… ID is parsed offline, so the HTTP client is
    // never touched and resolve() returns a non-null info array.
    $resolver = new ChannelInputResolver(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $feed_instance_manager = new FeedInstanceManager($entity_type_manager, $this->createMock(StateInterface::class));
    $orchestrator = new EmpireImportOrchestrator(
      $entity_type_manager,
      $feed_instance_manager,
      $this->createMock(ThumbnailUpgrader::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(LockBackendInterface::class),
    );

    // The form's logger must record the failure.
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')
      ->with($this->stringContains('provision'), $this->anything());

    // The user must see a plain-language error, and we must NOT redirect away.
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addError');

    // submitForm() now reads the channel info that validateForm() stashed; a
    // bare UC… ID resolves offline to a non-null info array, so provide it.
    $info = $resolver->resolve(self::CHANNEL_ID);
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->willReturn($info);
    $form_state->expects($this->never())->method('setRedirect');

    // EmpireSetupStatus is final (not mockable); construct a real one — it is
    // unused on this submit path, only needed to satisfy the constructor.
    $setup_status = new EmpireSetupStatus($entity_type_manager);
    $form = new SetupForm($resolver, $feed_instance_manager, $orchestrator, $setup_status, $logger);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($messenger);

    // Must not throw — the WSOD is exactly what the guard prevents.
    $build = [];
    $form->submitForm($build, $form_state);
  }

  /**
   * An unresolvable channel is rejected during validation, before provisioning.
   */
  public function testValidateRejectsUnresolvableChannel(): void {
    $resolver = new ChannelInputResolver(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $feed_instance_manager = new FeedInstanceManager($entity_type_manager, $this->createMock(StateInterface::class));
    $orchestrator = new EmpireImportOrchestrator(
      $entity_type_manager,
      $feed_instance_manager,
      $this->createMock(ThumbnailUpgrader::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(LockBackendInterface::class),
    );
    $setup_status = new EmpireSetupStatus($entity_type_manager);
    $form = new SetupForm($resolver, $feed_instance_manager, $orchestrator, $setup_status, $this->createMock(LoggerInterface::class));
    $form->setStringTranslation($this->getStringTranslationStub());

    // A blank input cannot resolve (offline, no HTTP) → a field error, and
    // nothing is stashed for submit.
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturn('   ');
    $form_state->expects($this->once())->method('setErrorByName')
      ->with('channel', $this->anything());
    $form_state->expects($this->never())->method('set');

    $build = [];
    $form->validateForm($build, $form_state);
  }

}
