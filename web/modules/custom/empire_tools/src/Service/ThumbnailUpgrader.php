<?php

declare(strict_types=1);

namespace Drupal\empire_tools\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Upgrades imported YouTube thumbnails to maxres, stored locally.
 *
 * YouTube oEmbed returns hqdefault (480x360, 4:3); the cinematic 16:9 layout
 * wants better. maxresdefault (1280x720, true 16:9) is available at a
 * predictable i.ytimg.com URL keyed by the video ID. We download it LOCALLY
 * (never hotlink) so the site stays consent-clean — no third-party image
 * requests in the browser — and fall back to the existing thumbnail
 * for older videos that 404 on maxres. Idempotent: skips already hi-res media.
 */
class ThumbnailUpgrader {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly ImageFactory $imageFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Upgrades one remote-video media's thumbnail to maxres.
   *
   * @return bool
   *   TRUE if the thumbnail was replaced with a higher-resolution image.
   */
  public function upgrade(MediaInterface $media): bool {
    if (!$media->hasField('field_media_oembed_video') || $media->get('field_media_oembed_video')->isEmpty()) {
      return FALSE;
    }
    $url = (string) $media->get('field_media_oembed_video')->value;
    if (!preg_match('#(?:v=|be/|embed/|shorts/)([A-Za-z0-9_-]{11})#', $url, $matches)) {
      return FALSE;
    }
    $video_id = $matches[1];

    // Idempotent: skip media already at hi-res (e.g. on re-import).
    $current = $media->get('thumbnail')->entity;
    if ($current instanceof FileInterface) {
      $image = $this->imageFactory->get($current->getFileUri());
      if ($image->isValid() && $image->getWidth() >= 1280) {
        return FALSE;
      }
    }

    // Fetch + validate the maxres image (older videos 404 — keep the existing
    // thumbnail). Empty string means unavailable or not a valid hi-res JPEG.
    $data = $this->fetchMaxres($video_id);
    if ($data === '') {
      return FALSE;
    }

    // Store first-party (consent-clean) and point the media thumbnail at it.
    $directory = 'public://oembed_thumbnails';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      return FALSE;
    }
    try {
      $file = $this->fileRepository->writeData($data, $directory . '/' . $video_id . '-maxres.jpg', FileExists::Replace);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Thumbnail upgrade write failed for @id: @msg', [
        '@id' => $video_id,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $alt = $current instanceof FileInterface ? (string) ($media->get('thumbnail')->alt ?? '') : '';
    $media->set('thumbnail', ['target_id' => $file->id(), 'alt' => $alt]);
    $media->save();
    return TRUE;
  }

  /**
   * Fetches and validates the maxres thumbnail bytes for a video ID.
   *
   * Hardened against a hostile/compromised/on-path upstream: the i.ytimg.com
   * URL is canonical so redirects are refused, an image content-type is
   * required, the streamed body is capped (no memory / zip-bomb exhaustion),
   * and the bytes are validated as a real hi-res JPEG before being returned.
   *
   * @param string $video_id
   *   The 11-character YouTube video ID.
   *
   * @return string
   *   The validated JPEG bytes, or '' if unavailable / not a valid hi-res JPEG.
   */
  protected function fetchMaxres(string $video_id): string {
    $data = '';
    $max_bytes = 5 * 1024 * 1024;
    try {
      $response = $this->httpClient->request('GET', 'https://i.ytimg.com/vi/' . $video_id . '/maxresdefault.jpg', [
        'timeout' => 10,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'stream' => TRUE,
      ]);
      $type = $response->getHeaderLine('Content-Type');
      if ($response->getStatusCode() === 200 && ($type === '' || stripos($type, 'image/') === 0)) {
        $body = $response->getBody();
        while (!$body->eof()) {
          $data .= $body->read(65536);
          if (strlen($data) > $max_bytes) {
            $this->logger->warning('Thumbnail upgrade for @id exceeded the size cap; skipped.', ['@id' => $video_id]);
            return '';
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Thumbnail upgrade fetch failed for @id: @msg', [
        '@id' => $video_id,
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
    if ($data === '') {
      return '';
    }

    // A 200 body could be an HTML interstitial or a tiny placeholder, not the
    // real image — require a JPEG of plausible resolution.
    $info = @getimagesizefromstring($data);
    if ($info === FALSE || $info[2] !== IMAGETYPE_JPEG || $info[0] < 1000) {
      $this->logger->warning('Thumbnail upgrade for @id was not a valid hi-res JPEG; skipped.', ['@id' => $video_id]);
      return '';
    }

    return $data;
  }

}
