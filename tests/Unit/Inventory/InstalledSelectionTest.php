<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Tests\Traits\JsonSchemaAssertionsTrait;
use Sparkfabrik\DrupalSbom\Inventory\InstalledSelection;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the installed-only selection.
 */
#[CoversClass(InstalledSelection::class)]
#[Group('drubom')]
final class InstalledSelectionTest extends TestCase {

  use JsonSchemaAssertionsTrait;

  /**
   * Uninstalled extensions go; Composer and npm packages stay.
   */
  public function testSelection(): void {
    $data = $this->fixture();
    // An uninstalled custom module, an uninstalled base theme of an
    // installed theme, and a library declared by an uninstalled module.
    $data['extensions'][2]['installed'] = FALSE;
    $data['extensions'][] = [
      'id' => 'extension:stable9',
      'machineName' => 'stable9',
      'type' => 'theme',
      'installed' => FALSE,
      'package' => 'composer:drupal/core',
      'path' => 'web/core/themes/stable9',
      'baseTheme' => NULL,
      'defaultTheme' => FALSE,
      'adminTheme' => FALSE,
    ];
    $data['relationships'][5]['from'] = 'extension:webform_ui';

    $selected = InstalledSelection::apply(Snapshot::fromArray($data));

    $extensions = array_map(static fn ($extension) => $extension->machineName, $selected->extensions);
    $this->assertSame(['webform', 'example', 'stable9'], $extensions);
    $packages = array_map(static fn ($package) => $package->id, $selected->packages);
    $this->assertNotContains('drupal-extension:example_custom', $packages);
    $this->assertNotContains('drupal-library:webform/webform.element.codemirror', $packages);
    $this->assertContains('composer:phpunit/phpunit', $packages);
    $this->assertContains('npm:lodash@4.17.21', $packages);
    $this->assertNotContains('occurrence:custom', array_map(static fn ($occurrence) => $occurrence->id, $selected->occurrences));
    $this->assertSame([], array_filter($selected->coverage->gaps, static fn ($gap) => $gap->subject === 'drupal-extension:example_custom'));

    $this->assertSame('installed', $selected->selection);
    $this->assertSame([], $selected->integrityErrors());
    $this->assertMatchesJsonSchema($selected->toArray(), __DIR__ . '/../../../schema/snapshot.schema.json');
  }

  /**
   * A snapshot with everything installed only gains the selection name.
   */
  public function testEverythingInstalled(): void {
    $data = $this->fixture();
    $data['extensions'][1]['installed'] = TRUE;

    $selected = InstalledSelection::apply(Snapshot::fromArray($data));

    $expected = $data + ['selection' => 'installed'];
    $this->assertEquals($expected, $selected->toArray());
  }

  /**
   * Returns the decoded representative fixture.
   *
   * @return array<string, mixed>
   *   The fixture data.
   */
  private function fixture(): array {
    return json_decode(file_get_contents(__DIR__ . '/../../fixtures/model/representative.snapshot.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
