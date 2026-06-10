<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Kernel;

use Drupal\empire_tools\Service\EmpireSetupStatus;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the dashboard status service against real entities.
 */
#[CoversClass(EmpireSetupStatus::class)]
#[Group('empire_tools')]
#[RunTestsInSeparateProcesses]
final class EmpireSetupStatusTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'node', 'taxonomy', 'media', 'file', 'image', 'link',
  ];

  /**
   * The service under test.
   */
  private EmpireSetupStatus $status;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');

    // The empire_video node type + the boolean the status counts on.
    NodeType::create(['type' => 'empire_video', 'name' => 'Video'])->save();
    $this->addField('node', 'empire_video', 'field_featured', 'boolean');

    // The empire_channel vocabulary + the two fields the status reads.
    Vocabulary::create(['vid' => 'empire_channel', 'name' => 'Channel'])->save();
    $this->addField('taxonomy_term', 'empire_channel', 'field_last_imported_at', 'integer');
    $this->addField('taxonomy_term', 'empire_channel', 'field_youtube_channel_url', 'link');

    $this->status = new EmpireSetupStatus($this->container->get('entity_type.manager'));
  }

  /**
   * Creates a single-cardinality field on a bundle.
   */
  private function addField(string $entity_type, string $bundle, string $name, string $type): void {
    FieldStorageConfig::create([
      'entity_type' => $entity_type,
      'field_name' => $name,
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $name,
    ])->save();
  }

  /**
   * Reports the empty, not-yet-connected state cleanly.
   */
  public function testEmptyBeforeSetup(): void {
    $this->assertNull($this->status->getConnectedChannel());
    $status = $this->status->getStatus();
    $this->assertFalse($status['connected']);
    $this->assertNull($status['channel_name']);
    $this->assertNull($status['channel_url']);
    $this->assertNull($status['last_import']);
    $this->assertSame(0, $status['video_count']);
    $this->assertSame(0, $status['media_count']);
    $this->assertSame(0, $status['featured_count']);
  }

  /**
   * Reports the connected channel, counts, and last import.
   */
  public function testConnectedChannelAndCounts(): void {
    $channel = Term::create([
      'vid' => 'empire_channel',
      'name' => 'Tom Scott',
      'field_youtube_channel_url' => ['uri' => 'https://www.youtube.com/channel/UCBJycsmduvYEL83R_U4JriQ'],
      'field_last_imported_at' => 1781000000,
    ]);
    $channel->save();

    // Three videos, one featured.
    foreach ([TRUE, FALSE, FALSE] as $i => $featured) {
      Node::create([
        'type' => 'empire_video',
        'title' => 'Video ' . $i,
        'field_featured' => $featured,
      ])->save();
    }

    $this->assertSame('Tom Scott', $this->status->getConnectedChannel()->label());

    $status = $this->status->getStatus();
    $this->assertTrue($status['connected']);
    $this->assertSame('Tom Scott', $status['channel_name']);
    $this->assertSame('https://www.youtube.com/channel/UCBJycsmduvYEL83R_U4JriQ', $status['channel_url']);
    $this->assertSame(1781000000, $status['last_import']);
    $this->assertSame(3, $status['video_count']);
    $this->assertSame(1, $status['featured_count']);
    // Real remote_video (oembed) media require network to save, so media_count
    // with media present is covered by the functional editorial test.
    $this->assertSame(0, $status['media_count']);
  }

  /**
   * After connecting a different channel, the newest term is the active one.
   *
   * "Connect a different channel" creates a second empire_channel term; the
   * dashboard/Refresh/Subscribe must follow the most-recently-connected channel
   * (highest id), not the first one created (review finding R2).
   */
  public function testMostRecentlyConnectedChannelWins(): void {
    $first = Term::create(['vid' => 'empire_channel', 'name' => 'Channel A']);
    $first->save();
    $second = Term::create(['vid' => 'empire_channel', 'name' => 'Channel B']);
    $second->save();

    $this->assertGreaterThan((int) $first->id(), (int) $second->id());
    $this->assertSame('Channel B', $this->status->getConnectedChannel()->label());
    $this->assertSame('Channel B', $this->status->getStatus()['channel_name']);
  }

}
