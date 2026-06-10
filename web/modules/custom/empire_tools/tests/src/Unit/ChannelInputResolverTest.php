<?php

declare(strict_types=1);

namespace Drupal\Tests\empire_tools\Unit;

use Drupal\empire_tools\Service\ChannelInputResolver;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the YouTube channel input resolver.
 */
#[CoversClass(ChannelInputResolver::class)]
#[Group('empire_tools')]
final class ChannelInputResolverTest extends UnitTestCase {

  private const ID = 'UCBJycsmduvYEL83R_U4JriQ';

  /**
   * Builds a resolver, optionally with a specific HTTP client.
   */
  private function resolver(?ClientInterface $client = NULL): ChannelInputResolver {
    return new ChannelInputResolver(
      $client ?? $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a resolver whose client replays a fixed queue of responses.
   */
  private function resolverWithResponses(Response ...$responses): ChannelInputResolver {
    $stack = HandlerStack::create(new MockHandler($responses));
    return $this->resolver(new Client(['handler' => $stack]));
  }

  /**
   * Extracts a channel id from bare ids, channel URLs, and feed URLs.
   */
  public function testExtractChannelId(): void {
    $r = $this->resolver();
    $this->assertSame(self::ID, $r->extractChannelId(self::ID));
    $this->assertSame(self::ID, $r->extractChannelId('https://www.youtube.com/channel/' . self::ID));
    $this->assertSame(self::ID, $r->extractChannelId('https://www.youtube.com/feeds/videos.xml?channel_id=' . self::ID));
    $this->assertNull($r->extractChannelId('@mkbhd'));
    $this->assertNull($r->extractChannelId('definitely not a channel'));
  }

  /**
   * Builds only allow-listed youtube.com URLs (rejects other hosts).
   */
  public function testBuildResolvableUrl(): void {
    $r = $this->resolver();
    $this->assertSame('https://www.youtube.com/@mkbhd', $r->buildResolvableUrl('@mkbhd'));
    // A bare handle (no @) — users often omit it — normalises to @handle.
    $this->assertSame('https://www.youtube.com/@dangertv', $r->buildResolvableUrl('dangertv'));
    $this->assertSame('https://www.youtube.com/@mkbhd', $r->buildResolvableUrl('https://www.youtube.com/@mkbhd'));
    $this->assertSame('https://www.youtube.com/c/MKBHD', $r->buildResolvableUrl('c/MKBHD'));
    $this->assertSame('https://www.youtube.com/user/marquesbrownlee', $r->buildResolvableUrl('https://youtube.com/user/marquesbrownlee'));
    // The mobile host is allow-listed; the fetch URL is normalised to www.
    $this->assertSame('https://www.youtube.com/@mkbhd', $r->buildResolvableUrl('https://m.youtube.com/@mkbhd'));
    // Host comparison is case-insensitive.
    $this->assertSame('https://www.youtube.com/@mkbhd', $r->buildResolvableUrl('https://WWW.YouTube.com/@mkbhd'));
    // Security: non-YouTube hosts are never resolvable.
    $this->assertNull($r->buildResolvableUrl('https://evil.example.com/@mkbhd'));
    $this->assertNull($r->buildResolvableUrl('https://youtube.evil.com/@mkbhd'));
    $this->assertNull($r->buildResolvableUrl('plain words'));
  }

  /**
   * Prefers the page's own canonical channel over recommended ones.
   */
  public function testParseChannelIdFromHtml(): void {
    $r = $this->resolver();
    $this->assertSame(self::ID, $r->parseChannelIdFromHtml('foo {"channelId":"' . self::ID . '"} bar'));
    $this->assertSame(self::ID, $r->parseChannelIdFromHtml('<link rel="canonical" href="https://www.youtube.com/channel/' . self::ID . '">'));
    $this->assertNull($r->parseChannelIdFromHtml('<html>no channel here</html>'));
    // Regression: a recommended channel's id can appear BEFORE the page's own
    // canonical/og:url — the canonical channel must win.
    $page = '"channelId":"UCwrongRECOMMENDEDxxxxxxx"'
      . '<meta property="og:url" content="https://www.youtube.com/channel/' . self::ID . '">';
    $this->assertSame(self::ID, $r->parseChannelIdFromHtml($page));
  }

  /**
   * The scraped og:title is decoded but stored inert (no tags/control chars).
   */
  public function testParseLabelFromHtmlSanitizesOgTitle(): void {
    $r = $this->resolver();
    // A legit entity-encoded title decodes to its real text.
    $this->assertSame('Tom & Jerry', $r->parseLabelFromHtml('<meta property="og:title" content="Tom &amp; Jerry">'));
    // A hostile og:title is decoded then stripped — no markup persists.
    $hostile = '<meta property="og:title" content="&lt;script&gt;alert(1)&lt;/script&gt;Channel">';
    $label = $r->parseLabelFromHtml($hostile);
    $this->assertSame('alert(1)Channel', $label);
    $this->assertStringNotContainsString('<', (string) $label);
    // No og:title → NULL.
    $this->assertNull($r->parseLabelFromHtml('<html>no title</html>'));
  }

  /**
   * Resolves inputs that already carry the channel id.
   */
  public function testResolveDirectInputs(): void {
    $r = $this->resolver();
    $inputs = [
      self::ID,
      'https://www.youtube.com/channel/' . self::ID,
      'https://www.youtube.com/feeds/videos.xml?channel_id=' . self::ID,
    ];
    foreach ($inputs as $input) {
      $info = $r->resolve($input);
      $this->assertSame(self::ID, $info['channel_id'], "resolve($input)");
      $this->assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=' . self::ID, $info['feed_url']);
      $this->assertSame('https://www.youtube.com/channel/' . self::ID, $info['channel_url']);
    }
  }

  /**
   * Never fetches for non-YouTube input (SSRF guard).
   */
  public function testResolveRejectsNonYouTube(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $this->assertNull($this->resolver($client)->resolve('https://evil.example.com/@mkbhd'));
  }

  /**
   * Returns NULL for empty or whitespace input.
   */
  public function testResolveEmpty(): void {
    $r = $this->resolver();
    $this->assertNull($r->resolve(''));
    $this->assertNull($r->resolve('   '));
  }

  /**
   * Fetches an allow-listed page to resolve a handle.
   */
  public function testResolveHandleViaFetch(): void {
    $html = '<meta property="og:title" content="Marques Brownlee"> ... {"channelId":"' . self::ID . '"}';
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('GET', 'https://www.youtube.com/@mkbhd', $this->anything())
      ->willReturn(new Response(200, [], $html));
    $info = $this->resolver($client)->resolve('@mkbhd');
    $this->assertSame(self::ID, $info['channel_id']);
    $this->assertSame('Marques Brownlee', $info['label']);
  }

  /**
   * Returns NULL instead of throwing on a network error (SPEC §6).
   */
  public function testResolveNeverCrashesOnHttpError(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(new \RuntimeException('network down'));
    $this->assertNull($this->resolver($client)->resolve('@mkbhd'));
  }

  /**
   * An allow-listed youtube.com → youtube.com redirect is followed.
   */
  public function testResolveFollowsYouTubeRedirect(): void {
    $r = $this->resolverWithResponses(
      new Response(301, ['Location' => 'https://www.youtube.com/channel/' . self::ID]),
      new Response(200, [], '<meta property="og:url" content="https://www.youtube.com/channel/' . self::ID . '">'),
    );
    $info = $r->resolve('c/SomeName');
    $this->assertSame(self::ID, $info['channel_id']);
  }

  /**
   * A redirect off the YouTube allowlist is blocked (SSRF defense-in-depth).
   *
   * The second (evil) response would resolve to a channel id if it were ever
   * fetched; the on_redirect host guard prevents that, so resolve() is NULL.
   */
  public function testResolveBlocksOffAllowlistRedirect(): void {
    $r = $this->resolverWithResponses(
      new Response(301, ['Location' => 'https://evil.example.com/x']),
      new Response(200, [], '{"channelId":"' . self::ID . '"}'),
    );
    $this->assertNull($r->resolve('@mkbhd'));
  }

  /**
   * A non-HTTPS redirect is blocked (protocol-downgrade guard).
   */
  public function testResolveBlocksNonHttpsRedirect(): void {
    $r = $this->resolverWithResponses(
      new Response(301, ['Location' => 'http://www.youtube.com/x']),
      new Response(200, [], '{"channelId":"' . self::ID . '"}'),
    );
    $this->assertNull($r->resolve('@mkbhd'));
  }

}
