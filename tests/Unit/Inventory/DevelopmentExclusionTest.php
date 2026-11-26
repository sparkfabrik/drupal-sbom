<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\Collector\ComposerCollector;
use Sparkfabrik\DrupalSbom\Inventory\DevelopmentExclusion;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests leaving development-only packages out of a snapshot.
 */
#[CoversClass(DevelopmentExclusion::class)]
#[Group('drubom')]
final class DevelopmentExclusionTest extends TestCase {

  /**
   * Development-only packages go, with their edges, and the count is a gap.
   */
  public function testExclusion(): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/composer');
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    (new ComposerCollector())->collect($builder);
    $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    $this->assertNotNull($snapshot->package('composer:phpunit/phpunit'), 'The fixture has a development-only package.');

    $excluded = DevelopmentExclusion::apply($snapshot);

    $this->assertNull($excluded->package('composer:phpunit/phpunit'));
    $this->assertNotNull($excluded->package('composer:drupal/core'));
    foreach ($excluded->relationships as $relationship) {
      $this->assertNotContains('composer:phpunit/phpunit', $relationship->to);
    }
    $gaps = array_values(array_filter($excluded->coverage->gaps, static fn ($gap) => $gap->kind === GapKind::Excluded));
    $this->assertSame(['Packages used only for development are excluded: 1.'], array_map(static fn ($gap) => $gap->detail, $gaps));
    $this->assertSame([], $excluded->integrityErrors());
  }

}
