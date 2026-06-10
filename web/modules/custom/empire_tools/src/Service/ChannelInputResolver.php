<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves human YouTube channel input into a channel ID and feed URL.
 *
 * This is the ONLY networked custom code in Empire (SPEC §6), so it is small,
 * isolated, and defensive:
 *  - only ever fetches allow-listed YouTube hosts,
 *  - short timeout, limited redirects,
 *  - sanitizes input, tolerates YouTube markup changes,
 *  - never hard-crashes setup: on any failure it returns NULL and the caller
 *    shows a plain-language error.
 *
 * Accepts: UC… IDs, channel URLs, feed URLs (parsed offline) and @handles /
 * legacy /c/ and /user/ URLs (resolved with one HTTP GET).
 */
final class ChannelInputResolver {

  /**
   * Hosts we are willing to fetch. Nothing else is ever requested.
   */
  private const ALLOWED_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];

  private const TIMEOUT = 10;
  private const MAX_REDIRECTS = 3;

  /**
   * Matches a YouTube channel ID: "UC" + 22 url-safe chars.
   */
  private const CHANNEL_ID = 'UC[A-Za-z0-9_-]{22}';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves any accepted input into channel details.
   *
   * @param string $input
   *   @handle, channel URL, UC… ID, or feed URL.
   *
   * @return array|null
   *   ['channel_id' => …, 'channel_url' => …, 'feed_url' => …, 'label' => ?…],
   *   or NULL if the input could not be resolved.
   */
  public function resolve(string $input): ?array {
    $input = trim($input);
    if ($input === '') {
      return NULL;
    }

    // 1. Channel ID present directly (bare UC…, channel URL, or feed URL).
    $channel_id = $this->extractChannelId($input);
    $label = NULL;

    // 2. Otherwise it is a handle / legacy URL — resolve with one GET.
    if ($channel_id === NULL) {
      $url = $this->buildResolvableUrl($input);
      if ($url !== NULL) {
        [$channel_id, $label] = $this->resolveByFetch($url);
      }
    }

    if ($channel_id === NULL) {
      return NULL;
    }
    return $this->buildInfo($channel_id, $label);
  }

  /**
   * Extracts a channel ID directly present in the input (no network).
   */
  public function extractChannelId(string $input): ?string {
    $input = trim($input);
    // Feed URL (channel_id=UC…) or channel URL (/channel/UC…).
    if (preg_match('#(?:channel_id=|/channel/)(' . self::CHANNEL_ID . ')#', $input, $m)) {
      return $m[1];
    }
    // Bare channel ID.
    if (preg_match('#^(' . self::CHANNEL_ID . ')$#', $input, $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * Builds a safe, allow-listed YouTube URL to resolve a handle / legacy path.
   *
   * @return string|null
   *   A youtube.com URL to fetch, or NULL if the input is not resolvable or
   *   points at a non-YouTube host.
   */
  public function buildResolvableUrl(string $input): ?string {
    $input = trim($input);

    // Bare @handle.
    if (preg_match('#^@[A-Za-z0-9_.-]+$#', $input)) {
      return 'https://www.youtube.com/' . $input;
    }
    // Bare handle without the @ (users often omit it) — normalise to @handle.
    // UC channel IDs are matched earlier by extractChannelId(), so a bare word
    // here is a handle, not an ID. The URL is hardcoded to youtube.com, so the
    // SSRF allowlist is unaffected.
    if (preg_match('#^[A-Za-z0-9_.-]+$#', $input)) {
      return 'https://www.youtube.com/@' . $input;
    }
    // Bare legacy path: c/Name or user/Name (optionally leading slash).
    if (preg_match('#^/?(c/[A-Za-z0-9_.-]+|user/[A-Za-z0-9_.-]+)$#', $input, $m)) {
      return 'https://www.youtube.com/' . $m[1];
    }
    // A full URL: only YouTube hosts, only @handle / c / user paths.
    if (preg_match('#^https?://#i', $input)) {
      $host = strtolower((string) parse_url($input, PHP_URL_HOST));
      if (!in_array($host, self::ALLOWED_HOSTS, TRUE)) {
        return NULL;
      }
      $path = (string) parse_url($input, PHP_URL_PATH);
      if (preg_match('#/(@[A-Za-z0-9_.-]+|c/[A-Za-z0-9_.-]+|user/[A-Za-z0-9_.-]+)#', $path, $m)) {
        return 'https://www.youtube.com/' . ltrim($m[1], '/');
      }
    }
    return NULL;
  }

  /**
   * Parses a channel ID from a fetched YouTube channel page.
   */
  public function parseChannelIdFromHtml(string $html): ?string {
    // Order matters: the canonical URL and og:url identify the page's OWN
    // channel. A bare "channelId"/"/channel/…" may belong to a recommended or
    // featured channel that appears earlier in the markup, so those are last.
    foreach ([
      '#<meta[^>]+property="og:url"[^>]+content="[^"]*?/channel/(' . self::CHANNEL_ID . ')#',
      '#rel="canonical"[^>]*href="[^"]*?/channel/(' . self::CHANNEL_ID . ')#',
      '#href="[^"]*?/channel/(' . self::CHANNEL_ID . ')"[^>]*rel="canonical"#',
      '#"channelId":"(' . self::CHANNEL_ID . ')"#',
      '#/channel/(' . self::CHANNEL_ID . ')#',
    ] as $pattern) {
      if (preg_match($pattern, $html, $m)) {
        return $m[1];
      }
    }
    return NULL;
  }

  /**
   * Parses the channel display name from a fetched page, if discoverable.
   */
  public function parseLabelFromHtml(string $html): ?string {
    if (preg_match('#<meta[^>]+property="og:title"[^>]+content="([^"]+)"#', $html, $m)) {
      // Decode entities to recover the real title (e.g. "Tom &amp; Jerry"),
      // then strip any tags + control characters so the stored channel label is
      // inert plain text — defense-in-depth against a hostile og:title such as
      // "&lt;script&gt;…&lt;/script&gt;" persisting as literal markup.
      $label = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
      $label = preg_replace('/[\x00-\x1F\x7F]+/u', '', strip_tags($label)) ?? '';
      $label = trim($label);
      return $label === '' ? NULL : $label;
    }
    return NULL;
  }

  /**
   * Fetches an allow-listed URL and parses [channel_id, label].
   *
   * @return array{0: ?string, 1: ?string}
   *   The resolved channel ID and label; either element may be NULL.
   */
  private function resolveByFetch(string $url): array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, self::ALLOWED_HOSTS, TRUE)) {
      return [NULL, NULL];
    }
    $allowed = self::ALLOWED_HOSTS;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => self::TIMEOUT,
        'connect_timeout' => self::TIMEOUT,
        'allow_redirects' => [
          'max' => self::MAX_REDIRECTS,
          // HTTPS only; never redirect off the allowlist (SSRF guard).
          'protocols' => ['https'],
          'on_redirect' => static function ($request, $response, $uri) use ($allowed): void {
            if (!in_array(strtolower((string) $uri->getHost()), $allowed, TRUE)) {
              throw new \RuntimeException('Empire resolver: blocked redirect to ' . $uri->getHost());
            }
          },
        ],
        'headers' => ['Accept-Language' => 'en-US,en;q=0.9'],
      ]);
      $html = (string) $response->getBody();
      return [$this->parseChannelIdFromHtml($html), $this->parseLabelFromHtml($html)];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Empire channel resolve failed for @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return [NULL, NULL];
    }
  }

  /**
   * Assembles the resolver output from a channel ID.
   */
  private function buildInfo(string $channel_id, ?string $label): array {
    return [
      'channel_id' => $channel_id,
      'channel_url' => 'https://www.youtube.com/channel/' . $channel_id,
      'feed_url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel_id,
      'label' => $label,
    ];
  }

}
