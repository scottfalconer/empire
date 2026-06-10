<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Kernel;

use Drupal\empire_tools\Service\FeedInstanceManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests channel-term provisioning.
 *
 * Feed-instance provisioning (ensureFeeds/getFeeds) requires feeds + node +
 * media + the recipe feed types and their processors, so it is covered by the
 * functional editorial test rather than in isolation here.
 */
#[CoversClass(FeedInstanceManager::class)]
#[Group('empire_tools')]
#[RunTestsInSeparateProcesses]
final class FeedInstanceManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'text', 'taxonomy', 'link'];

  /**
   * The service under test.
   */
  private FeedInstanceManager $manager;

  /**
   * A resolved-channel info array, as ChannelInputResolver::resolve() returns.
   */
  private const INFO = [
    'channel_id' => 'UCBJycsmduvYEL83R_U4JriQ',
    'channel_url' => 'https://www.youtube.com/channel/UCBJycsmduvYEL83R_U4JriQ',
    'feed_url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCBJycsmduvYEL83R_U4JriQ',
    'label' => 'Tom Scott',
    'handle' => '@TomScottGo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');

    Vocabulary::create(['vid' => 'empire_channel', 'name' => 'Channel'])->save();
    $this->addField('field_youtube_channel_id', 'string');
    $this->addField('field_youtube_channel_url', 'link');
    $this->addField('field_youtube_feed_url', 'string');
    $this->addField('field_import_enabled', 'boolean');
    $this->addField('field_youtube_handle', 'string');

    $this->manager = new FeedInstanceManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
    );
  }

  /**
   * Creates a single-cardinality field on the empire_channel vocabulary.
   */
  private function addField(string $name, string $type): void {
    FieldStorageConfig::create([
      'entity_type' => 'taxonomy_term',
      'field_name' => $name,
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'taxonomy_term',
      'bundle' => 'empire_channel',
      'field_name' => $name,
    ])->save();
  }

  /**
   * Creates a channel term with the resolved details populated.
   */
  public function testEnsureChannelCreates(): void {
    $term = $this->manager->ensureChannel(self::INFO);
    $this->assertSame('empire_channel', $term->bundle());
    $this->assertSame('Tom Scott', $term->label());
    $this->assertSame('UCBJycsmduvYEL83R_U4JriQ', $term->get('field_youtube_channel_id')->value);
    $this->assertSame(self::INFO['channel_url'], $term->get('field_youtube_channel_url')->uri);
    $this->assertSame(self::INFO['feed_url'], $term->get('field_youtube_feed_url')->value);
    $this->assertTrue((bool) $term->get('field_import_enabled')->value);
    $this->assertSame('@TomScottGo', $term->get('field_youtube_handle')->value);
  }

  /**
   * Re-running for the same channel id updates the term, never duplicates it.
   */
  public function testEnsureChannelIsIdempotent(): void {
    $first = $this->manager->ensureChannel(self::INFO);
    $second = $this->manager->ensureChannel(['label' => 'Tom Scott Renamed'] + self::INFO);
    $this->assertSame($first->id(), $second->id());

    $count = (int) $this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->getQuery()->accessCheck(FALSE)->condition('vid', 'empire_channel')->count()->execute();
    $this->assertSame(1, $count);
    $this->assertSame('Tom Scott Renamed', $second->label());
  }

  /**
   * Falls back to a readable name when the channel has no resolved label.
   */
  public function testEnsureChannelNameFallback(): void {
    $info = self::INFO;
    $info['label'] = NULL;
    $term = $this->manager->ensureChannel($info);
    $this->assertSame('YouTube channel UCBJycsmduvYEL83R_U4JriQ', $term->label());
  }

}
