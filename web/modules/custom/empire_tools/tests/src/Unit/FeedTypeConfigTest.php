<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the §8 feed-update behavior shipped in the recipe's feed types.
 *
 * The headline §8/§28 guarantees — editor edits survive re-import, aged-out
 * videos are kept, and items dedupe — live entirely in the two
 * `feeds.feed_type.*` config files. A config edit flipping `update_existing`,
 * `update_non_existent`, or the `unique` mapping would silently break those
 * guarantees with no other failing test. These assertions fail CI if the
 * shipped values drift.
 */
#[Group('empire_tools')]
final class FeedTypeConfigTest extends UnitTestCase {

  /**
   * Editor edits survive re-import + aged-out nodes are kept.
   */
  public function testVideoFeedPreservesEditsAndKeepsAgedOut(): void {
    $config = $this->loadFeedType('empire_youtube_videos');
    $processor = (array) ($config['processor_configuration'] ?? []);
    $this->assertSame(0, $processor['update_existing'] ?? NULL, 'Video feed must not overwrite editor-curated nodes.');
    $this->assertSame('_keep', $processor['update_non_existent'] ?? NULL, 'Aged-out videos must be kept, not deleted.');
    $this->assertSame(['field_source_guid'], $this->uniqueTargets($config), 'Video nodes dedupe on field_source_guid.');
  }

  /**
   * Media refreshes metadata, keeps aged-out media, and dedupes.
   */
  public function testMediaFeedRefreshesKeepsAndDedupes(): void {
    $config = $this->loadFeedType('empire_youtube_media');
    $processor = (array) ($config['processor_configuration'] ?? []);
    $this->assertSame(2, $processor['update_existing'] ?? NULL, 'Media feed refreshes existing media metadata.');
    $this->assertSame('_keep', $processor['update_non_existent'] ?? NULL, 'Aged-out media must be kept.');
    $this->assertSame(['field_media_oembed_video'], $this->uniqueTargets($config), 'Media dedupe on field_media_oembed_video.');
  }

  /**
   * Loads a shipped recipe feed-type config as an array.
   */
  private function loadFeedType(string $id): array {
    $path = dirname(__DIR__, 7) . '/recipes/empire/config/feeds.feed_type.' . $id . '.yml';
    $this->assertFileExists($path, "The recipe ships the {$id} feed type.");
    return (array) Yaml::parseFile($path);
  }

  /**
   * Returns the mapping targets that carry a non-empty unique key.
   */
  private function uniqueTargets(array $config): array {
    $targets = [];
    foreach ((array) ($config['mappings'] ?? []) as $mapping) {
      $mapping = (array) $mapping;
      if (!empty($mapping['unique'])) {
        $targets[] = $mapping['target'];
      }
    }
    return $targets;
  }

}
