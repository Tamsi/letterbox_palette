<?php

declare(strict_types=1);

namespace Drupal\letterbox_palette\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Extracts a ranked dominant-color palette from an image file (GD).
 */
class DominantColorExtractor {

  /**
   * Max colors returned in the selectable palette.
   */
  public const PALETTE_SIZE = 6;

  /**
   * Constructs the extractor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The letterbox_palette logger channel.
   */
  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether the image is portrait-oriented (height >= width).
   */
  public function isPortrait(FileInterface $file): bool {
    $size = $this->getImageSize($file);
    if ($size === NULL) {
      return FALSE;
    }
    return $size['height'] >= $size['width'];
  }

  /**
   * Whether the image is landscape-oriented (width > height).
   */
  public function isLandscape(FileInterface $file): bool {
    $size = $this->getImageSize($file);
    if ($size === NULL) {
      return FALSE;
    }
    return $size['width'] > $size['height'];
  }

  /**
   * Builds a palette + edge colors for letterbox preview.
   *
   * @return array|null
   *   Keys: colors (hex list) and edges (left/center/right), or NULL.
   */
  public function extractPortraitPalette(FileInterface $file): ?array {
    if (!$this->isPortrait($file)) {
      return NULL;
    }

    $src = $this->openGdImage($file);
    if ($src === NULL) {
      return NULL;
    }

    $src_w = imagesx($src);
    $src_h = imagesy($src);
    if ($src_w < 1 || $src_h < 1) {
      imagedestroy($src);
      return NULL;
    }

    $ranked = $this->rankVibrantColors($src, $src_w, $src_h, self::PALETTE_SIZE);
    $left = $this->averageVerticalStripColor($src, 0, $src_w, $src_h);
    $right = $this->averageVerticalStripColor($src, 1, $src_w, $src_h);
    $center = $ranked[0] ?? $left ?? $right;
    imagedestroy($src);

    if ($center === NULL) {
      return NULL;
    }

    $left = $left ?? $center;
    $right = $right ?? $center;

    foreach ([$left, $center, $right] as $hex) {
      if ($hex !== NULL && !in_array($hex, $ranked, TRUE)) {
        array_unshift($ranked, $hex);
      }
    }
    $ranked = array_values(array_unique($ranked));
    $ranked = array_slice($ranked, 0, self::PALETTE_SIZE);

    return [
      'colors' => $ranked,
      'edges' => [
        'left' => $left,
        'center' => $center,
        'right' => $right,
      ],
    ];
  }

  /**
   * Reads image width/height from disk.
   *
   * @return array{width: int, height: int}|null
   *   Dimensions, or NULL when unreadable.
   */
  protected function getImageSize(FileInterface $file): ?array {
    $path = $this->resolveReadablePath($file);
    if ($path === NULL) {
      return NULL;
    }
    $info = @getimagesize($path);
    if ($info === FALSE) {
      return NULL;
    }
    return [
      'width' => (int) $info[0],
      'height' => (int) $info[1],
    ];
  }

  /**
   * Opens a GD resource for the given managed file.
   *
   * @return \GdImage|resource|null
   *   Open image resource, or NULL on failure.
   */
  protected function openGdImage(FileInterface $file) {
    $path = $this->resolveReadablePath($file);
    if ($path === NULL) {
      return NULL;
    }
    $info = @getimagesize($path);
    if ($info === FALSE) {
      return NULL;
    }

    $src = match ((int) $info[2]) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_GIF => @imagecreatefromgif($path),
      IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : FALSE,
      default => FALSE,
    };

    return $src === FALSE ? NULL : $src;
  }

  /**
   * Resolves a readable filesystem path for a managed file.
   */
  protected function resolveReadablePath(FileInterface $file): ?string {
    $uri = $file->getFileUri();
    $realpath = $this->fileSystem->realpath($uri);
    $path = $realpath !== FALSE ? $realpath : $uri;
    if (!is_readable($path)) {
      $this->logger->warning('Unreadable image @uri', ['@uri' => $uri]);
      return NULL;
    }
    return $path;
  }

  /**
   * Returns up to $limit hex colors ranked by frequency × chroma.
   *
   * @param \GdImage|resource $src
   *   Open GD image.
   * @param int $src_w
   *   Source width in pixels.
   * @param int $src_h
   *   Source height in pixels.
   * @param int $limit
   *   Maximum number of colors to return.
   *
   * @return string[]
   *   Ranked hex color list.
   */
  protected function rankVibrantColors($src, int $src_w, int $src_h, int $limit): array {
    $sample_w = min(64, $src_w);
    $sample_h = min(64, $src_h);
    $sample = imagecreatetruecolor($sample_w, $sample_h);
    if ($sample === FALSE) {
      return [];
    }
    imagecopyresampled($sample, $src, 0, 0, 0, 0, $sample_w, $sample_h, $src_w, $src_h);

    $histogram = [];
    $bucket_sums = [];
    for ($y = 0; $y < $sample_h; $y++) {
      for ($x = 0; $x < $sample_w; $x++) {
        $rgb = imagecolorat($sample, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        if ($r > 245 && $g > 245 && $b > 245) {
          continue;
        }
        if ($r < 25 && $g < 25 && $b < 25) {
          continue;
        }

        $key = (($r >> 5) << 6) | (($g >> 5) << 3) | ($b >> 5);
        $histogram[$key] = ($histogram[$key] ?? 0) + 1;
        if (!isset($bucket_sums[$key])) {
          $bucket_sums[$key] = [0, 0, 0, 0];
        }
        $bucket_sums[$key][0] += $r;
        $bucket_sums[$key][1] += $g;
        $bucket_sums[$key][2] += $b;
        $bucket_sums[$key][3]++;
      }
    }
    imagedestroy($sample);

    if ($histogram === []) {
      return [];
    }

    $scored = [];
    foreach ($histogram as $key => $count) {
      $n = $bucket_sums[$key][3];
      if ($n < 1) {
        continue;
      }
      $avg_r = $bucket_sums[$key][0] / $n;
      $avg_g = $bucket_sums[$key][1] / $n;
      $avg_b = $bucket_sums[$key][2] / $n;
      $chroma = max($avg_r, $avg_g, $avg_b) - min($avg_r, $avg_g, $avg_b);
      $scored[] = [
        'score' => $count * (1 + ($chroma / 64)),
        'hex' => sprintf(
          '#%02x%02x%02x',
          (int) round($avg_r),
          (int) round($avg_g),
          (int) round($avg_b)
        ),
      ];
    }

    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    $colors = [];
    foreach ($scored as $row) {
      if (!in_array($row['hex'], $colors, TRUE)) {
        $colors[] = $row['hex'];
      }
      if (count($colors) >= $limit) {
        break;
      }
    }

    return $colors;
  }

  /**
   * Averages a thin vertical strip on the left (0) or right (1) edge.
   *
   * @param \GdImage|resource $src
   *   Open GD image.
   * @param int $side
   *   Side index: 0 = left, 1 = right.
   * @param int $src_w
   *   Source width in pixels.
   * @param int $src_h
   *   Source height in pixels.
   *
   * @return string|null
   *   Hex color, or NULL when no samples remain.
   */
  protected function averageVerticalStripColor($src, int $side, int $src_w, int $src_h): ?string {
    $band = max(1, (int) floor($src_w * 0.04));
    $step_y = max(1, (int) floor($src_h / 96));
    $sum_r = 0;
    $sum_g = 0;
    $sum_b = 0;
    $count = 0;

    for ($y = 0; $y < $src_h; $y += $step_y) {
      for ($x = 0; $x < $band; $x++) {
        $xx = $side === 0 ? $x : ($src_w - 1 - $x);
        $rgb = imagecolorat($src, $xx, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        if ($r > 245 && $g > 245 && $b > 245) {
          continue;
        }
        $sum_r += $r;
        $sum_g += $g;
        $sum_b += $b;
        $count++;
      }
    }

    if ($count < 1) {
      return NULL;
    }

    return sprintf(
      '#%02x%02x%02x',
      (int) round($sum_r / $count),
      (int) round($sum_g / $count),
      (int) round($sum_b / $count)
    );
  }

}
