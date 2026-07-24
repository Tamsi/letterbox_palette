<?php

declare(strict_types=1);

namespace Drupal\Tests\letterbox_palette\Unit;

use Drupal\letterbox_palette\PaletteFormAlter;
use Drupal\Tests\UnitTestCase;
use ReflectionMethod;

/**
 * Tests palette color normalization helpers.
 *
 * @group letterbox_palette
 */
class ColorNormalizeTest extends UnitTestCase {

  /**
   * @dataProvider providerNormalizeColorValue
   */
  public function testNormalizeColorValue(mixed $input, ?string $expected): void {
    $method = new ReflectionMethod(PaletteFormAlter::class, 'normalizeColorValue');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke(NULL, $input));
  }

  /**
   * Data provider for normalizeColorValue().
   */
  public static function providerNormalizeColorValue(): array {
    return [
      'hash hex' => ['#AaBbCc', '#aabbcc'],
      'bare hex' => ['112233', '#112233'],
      'empty' => ['', NULL],
      'invalid' => ['red', NULL],
      'short' => ['#fff', NULL],
      'null' => [NULL, NULL],
      'int' => [123456, NULL],
    ];
  }

}
