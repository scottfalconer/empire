<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\State\StateInterface;
use Drupal\empire_tools\Service\EmpireImportOrchestrator;
use Drupal\empire_tools\Service\FeedInstanceManager;
use Drupal\empire_tools\Service\ThumbnailUpgrader;
use Drupal\feeds\FeedInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the two-feed import orchestration.
 *
 * Drives import() with mocked Feeds/entity collaborators (no network, no DB) to
 * lock in the orchestration contracts: media feed before video feed (SPEC §8),
 * the channel-stamp no-overwrite guard (only fills an empty field_channel), and
 * the best-effort error handling that must never white-screen setup/refresh.
 */
#[CoversClass(EmpireImportOrchestrator::class)]
#[Group('empire_tools')]
final class EmpireImportOrchestratorTest extends UnitTestCase {

  /**
   * The mock media + node feed instance IDs (returned by the state map).
   */
  private const MEDIA_FEED_ID = 10;
  private const NODE_FEED_ID = 20;

  /**
   * The mock channel term ID.
   */
  private const CHANNEL_ID = 99;

  /**
   * Media feed imports before the video feed (SPEC §8 media-first ordering).
   */
  public function testImportRunsMediaFeedBeforeVideoFeed(): void {
    $order = [];
    $media = $this->createMock(FeedInterface::class);
    $media->method('id')->willReturn(self::MEDIA_FEED_ID);
    $media->method('getItemCount')->willReturn(15);
    $media->method('import')->willReturnCallback(function () use (&$order): void {
      $order[] = 'media';
    });

    $videos = $this->createMock(FeedInterface::class);
    $videos->method('id')->willReturn(self::NODE_FEED_ID);
    $videos->method('getItemCount')->willReturn(15);
    $videos->method('import')->willReturnCallback(function () use (&$order): void {
      $order[] = 'videos';
    });

    $orchestrator = $this->orchestrator($media, $videos, [], [], $this->createMock(LoggerInterface::class), $this->channel());
    $report = $orchestrator->import($this->channel());

    $this->assertSame(['media', 'videos'], $order, 'The media feed imports before the video feed.');
    $this->assertSame(15, $report['media']['count']);
    $this->assertNull($report['media']['error']);
    $this->assertSame(15, $report['videos']['count']);
    $this->assertNull($report['videos']['error']);
  }

  /**
   * Fills field_channel only when empty — editor curation is kept (SPEC §8).
   */
  public function testStampChannelFillsOnlyEmptyChannelField(): void {
    $emptyField = $this->createMock(FieldItemListInterface::class);
    $emptyField->method('isEmpty')->willReturn(TRUE);
    $emptyNode = $this->createMock(NodeInterface::class);
    $emptyNode->method('get')->willReturn($emptyField);
    // The freshly-imported, uncurated node gets stamped exactly once.
    $emptyNode->expects($this->once())->method('set')
      ->with('field_channel', ['target_id' => self::CHANNEL_ID]);
    $emptyNode->expects($this->once())->method('save');

    $curatedField = $this->createMock(FieldItemListInterface::class);
    $curatedField->method('isEmpty')->willReturn(FALSE);
    $curatedNode = $this->createMock(NodeInterface::class);
    $curatedNode->method('get')->willReturn($curatedField);
    // The editor-curated node is never touched.
    $curatedNode->expects($this->never())->method('set');
    $curatedNode->expects($this->never())->method('save');

    $orchestrator = $this->orchestrator(
      $this->feed(self::MEDIA_FEED_ID),
      $this->feed(self::NODE_FEED_ID),
      [1, 2],
      [1 => $emptyNode, 2 => $curatedNode],
      $this->createMock(LoggerInterface::class),
      $this->channel(),
    );
    $orchestrator->import($this->channel());
  }

  /**
   * A post-import stamping failure is logged, not thrown (no WSOD; SPEC §6).
   */
  public function testPostImportStampingFailureIsLoggedNotThrown(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())->method('error');

    // The last-imported-at save throws — the import has already run, so the
    // orchestrator must swallow + log it rather than white-screening setup.
    $channel = $this->channel();
    $channel->method('save')->willThrowException(new \RuntimeException('storage down'));

    $orchestrator = $this->orchestrator(
      $this->feed(self::MEDIA_FEED_ID),
      $this->feed(self::NODE_FEED_ID),
      [],
      [],
      $logger,
      $channel,
    );

    $report = $orchestrator->import($channel);
    $this->assertSame(15, $report['media']['count'], 'The import result still returns after a stamping failure.');
  }

  /**
   * A feed import error is captured in the report, not thrown.
   */
  public function testFeedImportFailureIsCapturedNotThrown(): void {
    $media = $this->createMock(FeedInterface::class);
    $media->method('id')->willReturn(self::MEDIA_FEED_ID);
    $media->method('getItemCount')->willReturn(0);
    $media->method('import')->willThrowException(new \RuntimeException('network down'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())->method('error');

    $orchestrator = $this->orchestrator(
      $media,
      $this->feed(self::NODE_FEED_ID),
      [],
      [],
      $logger,
      $this->channel(),
    );
    $report = $orchestrator->import($this->channel());

    $this->assertSame('network down', $report['media']['error']);
    $this->assertNull($report['videos']['error'], 'The video feed still imports after a media-feed error.');
  }

  /**
   * Builds a minimal Feeds feed mock with an ID and item count.
   */
  private function feed(int $id, int $count = 15): FeedInterface {
    $feed = $this->createMock(FeedInterface::class);
    $feed->method('id')->willReturn($id);
    $feed->method('getItemCount')->willReturn($count);
    return $feed;
  }

  /**
   * Builds a channel term mock (id + chainable set()).
   */
  private function channel(): TermInterface&MockObject {
    $channel = $this->createMock(TermInterface::class);
    $channel->method('id')->willReturn(self::CHANNEL_ID);
    $channel->method('label')->willReturn('Test Channel');
    $channel->method('set')->willReturnSelf();
    return $channel;
  }

  /**
   * Wires a real FeedInstanceManager (mocked deps) into the orchestrator.
   *
   * State returns the media/node feed IDs; the feeds_feed storage loads them as
   * the given feed mocks; the node query/loadMultiple return the given stamp
   * targets — so import() drives the real orchestration logic end to end.
   */
  private function orchestrator(FeedInterface $media, FeedInterface $videos, array $node_ids, array $nodes, LoggerInterface $logger, TermInterface $channel): EmpireImportOrchestrator {
    $feed_storage = $this->createMock(EntityStorageInterface::class);
    $feed_storage->method('load')->willReturnMap([
      [self::MEDIA_FEED_ID, $media],
      [self::NODE_FEED_ID, $videos],
    ]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($node_ids);
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('getQuery')->willReturn($query);
    $node_storage->method('loadMultiple')->willReturn($nodes);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
      ['feeds_feed', $feed_storage],
      ['node', $node_storage],
    ]);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([
      'media' => self::MEDIA_FEED_ID,
      'videos' => self::NODE_FEED_ID,
    ]);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $feed_instance_manager = new FeedInstanceManager($entity_type_manager, $state);
    $thumbnail_upgrader = $this->createMock(ThumbnailUpgrader::class);
    return new EmpireImportOrchestrator($entity_type_manager, $feed_instance_manager, $thumbnail_upgrader, $time, $logger);
  }

}
