<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\empire_tools\Service\ThumbnailUpgrader;
use Drupal\file\FileRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the hardened maxres thumbnail fetch + validation.
 *
 * Covers the security-critical fetchMaxres(): no redirects, image content-type
 * required, streamed size cap, and a real-JPEG/hi-res validation before the
 * bytes are ever persisted.
 */
#[CoversClass(ThumbnailUpgrader::class)]
#[Group('empire_tools')]
final class ThumbnailUpgraderTest extends UnitTestCase {

  private const ID = 'dQw4w9WgXcQ';

  /**
   * Builds the service with a queue of mocked HTTP responses.
   */
  private function upgrader(Response|\Throwable ...$responses): ThumbnailUpgrader {
    $stack = HandlerStack::create(new MockHandler($responses));
    return new ThumbnailUpgrader(
      new Client(['handler' => $stack]),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(ImageFactory::class),
      new NullLogger(),
    );
  }

  /**
   * Invokes the protected fetchMaxres() for the canonical test video ID.
   */
  private function fetch(ThumbnailUpgrader $upgrader): string {
    $method = new \ReflectionMethod($upgrader, 'fetchMaxres');
    $method->setAccessible(TRUE);
    return $method->invoke($upgrader, self::ID);
  }

  /**
   * Encodes a blank image of the given dimensions in the given format.
   */
  private function image(int $width, int $height, string $format): string {
    $img = imagecreatetruecolor($width, $height);
    assert($img instanceof \GdImage);
    ob_start();
    $format === 'png' ? imagepng($img) : imagejpeg($img);
    return (string) ob_get_clean();
  }

  /**
   * A valid hi-res JPEG is accepted and returned verbatim.
   */
  public function testAcceptsValidMaxresJpeg(): void {
    $jpeg = $this->image(1280, 720, 'jpeg');
    $upgrader = $this->upgrader(new Response(200, ['Content-Type' => 'image/jpeg'], $jpeg));
    $this->assertSame($jpeg, $this->fetch($upgrader));
  }

  /**
   * A 404 (no maxres for older videos) yields no bytes.
   */
  public function testRejects404(): void {
    $upgrader = $this->upgrader(new Response(404, [], 'Not Found'));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * A 5xx is swallowed (no upgrade), not surfaced as an error.
   */
  public function testRejectsServerError(): void {
    $upgrader = $this->upgrader(new Response(500, [], 'oops'));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * A non-image content-type is refused.
   */
  public function testRejectsNonImageContentType(): void {
    $upgrader = $this->upgrader(new Response(200, ['Content-Type' => 'text/html'], '<html>no</html>'));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * A low-resolution image is rejected (not the maxres we want).
   */
  public function testRejectsLowResImage(): void {
    $upgrader = $this->upgrader(new Response(200, ['Content-Type' => 'image/jpeg'], $this->image(100, 100, 'jpeg')));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * A non-JPEG image is rejected even at hi-res — the file is stored as .jpg.
   */
  public function testRejectsNonJpeg(): void {
    $upgrader = $this->upgrader(new Response(200, ['Content-Type' => 'image/png'], $this->image(1280, 720, 'png')));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * An oversized body trips the streamed size cap.
   */
  public function testRejectsOversizedBody(): void {
    $big = str_repeat('x', 5 * 1024 * 1024 + 4096);
    $upgrader = $this->upgrader(new Response(200, ['Content-Type' => 'image/jpeg'], $big));
    $this->assertSame('', $this->fetch($upgrader));
  }

  /**
   * A transport exception is caught, not thrown.
   */
  public function testCatchesTransportException(): void {
    $upgrader = $this->upgrader(new \RuntimeException('connection reset'));
    $this->assertSame('', $this->fetch($upgrader));
  }

}
