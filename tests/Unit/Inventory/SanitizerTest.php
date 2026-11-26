<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Inventory\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that references lose credentials, query strings and server paths.
 */
#[CoversClass(Sanitizer::class)]
#[Group('drubom')]
final class SanitizerTest extends TestCase {

  /**
   * References are reduced to what is safe to export.
   */
  #[DataProvider('referenceProvider')]
  public function testReference(string $value, string $expected): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/composer');
    $roots = new ProjectRoots($root, $root . '/vendor', $root . '/web');

    $this->assertSame($expected, Sanitizer::reference(str_replace('ROOT', $root, $value), $roots));
  }

  /**
   * Provides references and their safe form.
   *
   * @return array<string, array{string, string}>
   *   Value and expected result.
   */
  public static function referenceProvider(): array {
    return [
      'https with credentials and token' => [
        'https://user:secret@example.com/a.patch?token=x#frag',
        'https://example.com/a.patch',
      ],
      'scheme-relative with credentials' => ['//user:secret@example.com/file?token=x', '//example.com/file'],
      'file URI outside the root' => ['file:///outside/private.patch?token=x', 'private.patch'],
      'absolute path inside the root' => ['ROOT/composer.json', 'composer.json'],
      'absolute path outside the root' => ['/srv/secret/patches/x.patch', 'x.patch'],
      'relative path with query' => ['patches/x.patch?token=x', 'patches/x.patch'],
    ];
  }

}
