<?php

declare(strict_types=1);

namespace Drupal\Tests\letterbox_palette\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\letterbox_palette\Service\DominantColorExtractor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DominantColorExtractor.
 *
 * @coversDefaultClass \Drupal\letterbox_palette\Service\DominantColorExtractor
 * @group letterbox_palette
 */
class DominantColorExtractorTest extends UnitTestCase {

  /**
   * Temporary image paths created during the test.
   *
   * @var string[]
   */
  protected array $tempFiles = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!extension_loaded('gd')) {
      $this->markTestSkipped('GD extension is required.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->tempFiles as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * @covers ::isPortrait
   * @covers ::isLandscape
   * @covers ::extractPortraitPalette
   */
  public function testPortraitPaletteExtraction(): void {
    $path = $this->createTestImage(80, 120, [
      [0, 0, 40, 120, [200, 40, 40]],
      [40, 0, 40, 120, [40, 40, 200]],
    ]);

    $extractor = $this->createExtractor($path);
    $file = $this->createFileMock('public://portrait.png');

    $this->assertTrue($extractor->isPortrait($file));
    $this->assertFalse($extractor->isLandscape($file));

    $palette = $extractor->extractPortraitPalette($file);
    $this->assertIsArray($palette);
    $this->assertArrayHasKey('colors', $palette);
    $this->assertArrayHasKey('edges', $palette);
    $this->assertNotEmpty($palette['colors']);
    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['edges']['left']);
    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['edges']['right']);
    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['edges']['center']);
    $this->assertLessThanOrEqual(DominantColorExtractor::PALETTE_SIZE, count($palette['colors']));
  }

  /**
   * @covers ::extractPortraitPalette
   */
  public function testLandscapeImageReturnsNullPalette(): void {
    $path = $this->createTestImage(120, 80, [
      [0, 0, 120, 80, [30, 160, 90]],
    ]);
    $extractor = $this->createExtractor($path);
    $file = $this->createFileMock('public://landscape.png');

    $this->assertTrue($extractor->isLandscape($file));
    $this->assertNull($extractor->extractPortraitPalette($file));
  }

  /**
   * Creates the extractor with mocked filesystem + logger.
   */
  protected function createExtractor(string $realpath): DominantColorExtractor {
    /** @var \Drupal\Core\File\FileSystemInterface&\PHPUnit\Framework\MockObject\MockObject $file_system */
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')->willReturn($realpath);

    /** @var \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger */
    $logger = $this->createMock(LoggerInterface::class);

    return new DominantColorExtractor($file_system, $logger);
  }

  /**
   * Creates a file entity mock.
   */
  protected function createFileMock(string $uri): FileInterface|MockObject {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($uri);
    $file->method('id')->willReturn(1);
    return $file;
  }

  /**
   * Writes a PNG test image with colored rectangles.
   *
   * @param int $width
   *   Image width.
   * @param int $height
   *   Image height.
   * @param array<int, array{0:int,1:int,2:int,3:int,4:array{0:int,1:int,2:int}}> $rects
   *   Rectangles as [x, y, w, h, [r, g, b]].
   *
   * @return string
   *   Absolute path to the generated PNG.
   */
  protected function createTestImage(int $width, int $height, array $rects): string {
    $image = imagecreatetruecolor($width, $height);
    $this->assertNotFalse($image);
    foreach ($rects as $rect) {
      [$x, $y, $w, $h, $rgb] = $rect;
      $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
      imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, $color);
    }
    $path = sys_get_temp_dir() . '/letterbox-palette-' . uniqid('', TRUE) . '.png';
    $this->assertTrue(imagepng($image, $path));
    imagedestroy($image);
    $this->tempFiles[] = $path;
    return $path;
  }

}
