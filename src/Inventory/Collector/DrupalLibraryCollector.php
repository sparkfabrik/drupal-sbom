<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Collector;

use Sparkfabrik\DrupalSbom\Util\InvalidYamlException;
use Sparkfabrik\DrupalSbom\Util\Yaml;
use Sparkfabrik\DrupalSbom\Inventory\LibraryDiscovery;
use Sparkfabrik\DrupalSbom\Inventory\CollectorInterface;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\Sanitizer;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Confidence;
use Sparkfabrik\DrupalSbom\Model\ContextKind;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Evidence;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Occurrence;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Scope;

/**
 * Collects third-party asset libraries declared by Drupal extensions.
 *
 * A library counts as third-party when its definition names a remote
 * project; the other libraries are the extension's own code. Installed
 * extensions go through library discovery, so alters and overrides apply;
 * for the others, and for every extension when there is no library
 * discovery, as in a scan outside the site, the libraries file is read as
 * declared.
 */
final class DrupalLibraryCollector implements CollectorInterface {

  /**
   * License names used by Drupal library definitions, mapped to SPDX.
   */
  private const LICENSE_ALIASES = [
    'GNU-GPL-2.0-or-later' => 'GPL-2.0-or-later',
  ];

  /**
   * Constructs a DrupalLibraryCollector.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\LibraryDiscovery|null $libraryDiscovery
   *   The library discovery; NULL outside the site.
   */
  public function __construct(
    private readonly ?LibraryDiscovery $libraryDiscovery = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal_libraries';
  }

  /**
   * {@inheritdoc}
   */
  public function version(): string {
    return '1';
  }

  /**
   * {@inheritdoc}
   */
  public function collect(InventoryBuilder $builder): CollectorStatus {
    $status = CollectorStatus::Complete;
    $coreDirectory = $builder->roots->relative($builder->roots->web . '/core');
    $owners = [];
    if ($coreDirectory !== NULL && ($core = $builder->packageContaining($coreDirectory)) !== NULL) {
      $discovered = $this->libraryDiscovery !== NULL;
      $owners[] = ['core', $core, $coreDirectory, $coreDirectory . '/core.libraries.yml', $discovered];
    }
    foreach ($builder->extensions() as $extension) {
      $installed = $extension->installed === TRUE && $this->libraryDiscovery !== NULL;
      $file = $extension->path . '/' . $extension->machineName . '.libraries.yml';
      $owners[] = [$extension->machineName, $extension->id, $extension->path, $file, $installed];
    }

    foreach ($owners as [$name, $owner, $directory, $file, $installed]) {
      $absolute = $builder->roots->application . '/' . $file;
      $hasFile = is_file($absolute);
      // Installed extensions can define libraries in code, without a file.
      if (!$hasFile && !$installed) {
        continue;
      }
      $contents = $hasFile ? $builder->read($absolute) : NULL;
      $file = $hasFile ? $file : $directory;
      if ($installed && $this->libraryDiscovery !== NULL) {
        $libraries = $this->libraryDiscovery->getLibrariesByExtension($name);
        $confidence = Confidence::High;
      }
      else {
        try {
          $libraries = $contents === NULL ? [] : (array) Yaml::decode($contents);
        }
        catch (InvalidYamlException $e) {
          $builder->addGap(new Gap(GapKind::UnreadableInput, 'Invalid YAML: ' . $e->getMessage(), path: $file));
          $status = CollectorStatus::Partial;
          continue;
        }
        $confidence = Confidence::Medium;
      }

      $declared = [];
      foreach ($libraries as $library => $definition) {
        if (!is_array($definition) || (!is_string($definition['remote'] ?? NULL) && $this->assets($definition, TRUE) === [])) {
          continue;
        }
        $declared[] = $this->addLibrary($builder, $name . '/' . $library, $definition, $directory, $file, $confidence, $installed);
      }
      if ($declared !== []) {
        $builder->addRelationship(new Relationship($owner, RelationshipType::Declares, $declared, [new Evidence($file, $confidence)]));
      }
    }
    return $status;
  }

  /**
   * Adds a third-party library as a package with its occurrence.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $name
   *   The library name, prefixed with its extension.
   * @param array<string, mixed> $definition
   *   The library definition.
   * @param string $directory
   *   The extension directory, relative to the application root.
   * @param string $file
   *   The libraries file, relative to the application root.
   * @param \Sparkfabrik\DrupalSbom\Model\Confidence $confidence
   *   How much the definition can be trusted.
   * @param bool $discovered
   *   Whether the definition comes from library discovery, whose asset
   *   paths are relative to the web root rather than to the extension.
   *
   * @return string
   *   The package identifier.
   */
  private function addLibrary(InventoryBuilder $builder, string $name, array $definition, string $directory, string $file, Confidence $confidence, bool $discovered): string {
    $id = 'drupal-library:' . $name;
    $evidence = [new Evidence($file, $confidence)];
    $version = is_scalar($definition['version'] ?? NULL) ? (string) $definition['version'] : NULL;
    // "VERSION" stands for the core version, which says nothing about a
    // third-party library; discovery has already replaced it.
    if ($version === NULL || $version === '' || $version === 'VERSION' || ($discovered && $version === \Drupal::VERSION)) {
      $version = NULL;
      $builder->addGap(new Gap(GapKind::UnknownVersion, sprintf('Library %s declares no version of its own.', $name), subject: $id));
    }
    $licenseName = is_string($definition['license']['name'] ?? NULL) ? $definition['license']['name'] : NULL;
    $license = LicenseClassifier::composer(
      $licenseName === NULL ? [] : [self::LICENSE_ALIASES[$licenseName] ?? $licenseName],
      [new Evidence($file, $confidence, 'license')],
    );

    $builder->addPackage(new Package(
      $id,
      Ecosystem::DrupalLibrary,
      $name,
      $version,
      NULL,
      $license,
      homepage: is_string($definition['remote'] ?? NULL) ? Sanitizer::reference($definition['remote'], $builder->roots) : NULL,
    ));
    // Present only when a declared local asset exists on disk.
    $local = $this->assets($definition, FALSE);
    $present = FALSE;
    foreach ($local as $asset) {
      $base = $discovered || str_starts_with($asset, '/') ? $builder->roots->web : $builder->roots->application . '/' . $directory;
      if (is_file($base . '/' . ltrim($asset, '/'))) {
        $present = TRUE;
        break;
      }
    }
    $builder->addOccurrence(new Occurrence(
      'occurrence:' . $id,
      $id,
      $local === [] ? NULL : $directory,
      $present ? Presence::Installed : Presence::Unknown,
      [Scope::Production],
      ContextKind::DrupalLibrary,
      $file,
      $evidence,
    ));
    return $id;
  }

  /**
   * Returns the asset paths or URLs of a library definition.
   *
   * @param array<string, mixed> $definition
   *   The library definition, declared or discovered.
   * @param bool $external
   *   TRUE for the external assets, FALSE for the local ones.
   *
   * @return list<string>
   *   The asset paths or URLs.
   */
  private function assets(array $definition, bool $external): array {
    $assets = [];
    foreach ($definition['js'] ?? [] as $key => $options) {
      $assets[] = [$key, is_array($options) ? $options : []];
    }
    $css = $definition['css'] ?? [];
    // Library discovery flattens assets into lists with a data key; the
    // declared form groups CSS by SMACSS category.
    foreach (array_is_list($css) ? [$css] : $css as $group) {
      foreach (is_array($group) ? $group : [] as $key => $options) {
        $assets[] = [$key, is_array($options) ? $options : []];
      }
    }
    $paths = [];
    foreach ($assets as [$key, $options]) {
      $path = (string) ($options['data'] ?? $key);
      $isExternal = ($options['type'] ?? 'file') === 'external' || preg_match('#^(https?:)?//#', $path) === 1;
      if ($isExternal === $external && $path !== '') {
        $paths[] = $path;
      }
    }
    return $paths;
  }

}
