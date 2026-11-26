<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Confidence;
use Sparkfabrik\DrupalSbom\Model\ContextKind;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Evidence;
use Sparkfabrik\DrupalSbom\Model\Extension;
use Sparkfabrik\DrupalSbom\Model\ExtensionType;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Occurrence;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Scope;

/**
 * Records the Drupal extensions found in a codebase into an inventory.
 *
 * Shared by the file collector and by the Drubom module's runtime collector.
 */
final class ExtensionRecorder {

  /**
   * Adds extensions to the inventory, each attached to its package.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param list<array{name: string, type: \Sparkfabrik\DrupalSbom\Model\ExtensionType, installed: ?bool, path: string, infoFile: string, info: array<mixed>, defaultTheme: ?bool, adminTheme: ?bool}> $candidates
   *   The extensions; paths are relative to the web root, NULL states are
   *   unknown and theme flags are FALSE for other types.
   */
  public static function record(InventoryBuilder $builder, array $candidates): CollectorStatus {
    // Parents first, so a submodule of a custom module joins its package.
    usort($candidates, static function (array $a, array $b): int {
      return [strlen($a['path']), $a['path']] <=> [strlen($b['path']), $b['path']];
    });

    $contains = [];
    $status = CollectorStatus::Complete;
    foreach ($candidates as $candidate) {
      ['name' => $name, 'type' => $type] = $candidate;
      $path = $builder->roots->relative($builder->roots->web . '/' . $candidate['path']);
      if ($path === NULL) {
        $builder->addGap(new Gap(GapKind::UnreadableInput, sprintf('The %s %s is outside the application root.', $type->value, $name)));
        $status = CollectorStatus::Partial;
        continue;
      }
      $infoFile = $path . '/' . $candidate['infoFile'];
      $builder->read($builder->roots->application . '/' . $infoFile);
      $package = $builder->packageContaining($path) ?? self::addCustomPackage($builder, $name, $candidate['info'], $path, $infoFile);

      $baseTheme = $candidate['info']['base theme'] ?? NULL;
      $builder->addExtension(new Extension(
        'extension:' . $name,
        $name,
        $type,
        $candidate['installed'],
        $package,
        $path,
        $type === ExtensionType::Theme && is_string($baseTheme) ? $baseTheme : NULL,
        $candidate['defaultTheme'],
        $candidate['adminTheme'],
      ));
      $contains[$package]['extension:' . $name] = new Evidence($infoFile, Confidence::High);
    }

    foreach ($contains as $package => $extensions) {
      $builder->addRelationship(new Relationship($package, RelationshipType::Contains, array_keys($extensions), array_values($extensions)));
    }
    return $status;
  }

  /**
   * Adds a package for an extension that no Composer package contains.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $name
   *   The extension machine name.
   * @param array<mixed> $info
   *   The parsed info file.
   * @param string $path
   *   The extension directory, relative to the application root.
   * @param string $infoFile
   *   The info file, relative to the application root.
   *
   * @return string
   *   The package identifier.
   */
  private static function addCustomPackage(InventoryBuilder $builder, string $name, array $info, string $path, string $infoFile): string {
    $id = 'drupal-extension:' . $name;
    $version = $info['version'] ?? NULL;
    // Core-relative "VERSION" says nothing about a custom extension.
    if (!is_string($version) || $version === '' || $version === 'VERSION') {
      $version = NULL;
      $builder->addGap(new Gap(GapKind::UnknownVersion, sprintf('%s declares no version.', $name), subject: $id));
    }
    $evidence = [new Evidence($infoFile, Confidence::High)];
    $builder->addPackage(new Package(
      $id,
      Ecosystem::DrupalExtension,
      $name,
      $version,
      NULL,
      LicenseClassifier::composer([], $evidence),
      is_string($info['description'] ?? NULL) ? $info['description'] : NULL,
    ));
    $builder->addOccurrence(new Occurrence(
      'occurrence:' . $id,
      $id,
      $path,
      Presence::Installed,
      [Scope::Production],
      ContextKind::DrupalExtension,
      $infoFile,
      $evidence,
    ));
    $builder->addRelationship(new Relationship((string) $builder->rootPackageId(), RelationshipType::Contains, [$id], $evidence));
    return $id;
  }

}
