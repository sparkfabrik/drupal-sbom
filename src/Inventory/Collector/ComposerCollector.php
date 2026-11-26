<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Collector;

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
 * Collects Composer packages from the installed metadata and the lock file.
 *
 * The installed metadata decides what is present; the lock file adds
 * packages that are declared but not installed, for example development
 * packages on a production build.
 */
final class ComposerCollector implements CollectorInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'composer';
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
    $roots = $builder->roots;
    $installedFile = $roots->vendor . '/composer/installed.json';
    $installedSource = $roots->relative($installedFile) ?? 'vendor/composer/installed.json';
    $rootManifest = $builder->readJson($roots->application . '/composer.json');
    $lock = $builder->readJson($roots->application . '/composer.lock');
    $installed = $builder->readJson($installedFile);

    if ($installed !== NULL && !is_array($installed['packages'] ?? NULL)) {
      $builder->addGap(new Gap(GapKind::UnsupportedFormat, 'The Composer installed metadata is not in the Composer 2 format.', path: $installedSource));
      $installed = NULL;
    }
    if ($lock !== NULL && (!is_array($lock['packages'] ?? []) || !is_array($lock['packages-dev'] ?? []))) {
      $builder->addGap(new Gap(GapKind::UnreadableInput, 'composer.lock has an unexpected structure.', path: 'composer.lock'));
      $lock = NULL;
    }
    $unreadable = FALSE;
    foreach (['composer.json' => $rootManifest, 'composer.lock' => $lock] as $file => $data) {
      if ($data === NULL && is_file($roots->application . '/' . $file)) {
        $unreadable = TRUE;
        $builder->addGap(new Gap(GapKind::UnreadableInput, sprintf('%s could not be read or understood.', $file), path: $file));
      }
    }
    $rootManifest ??= [];
    $this->addRootPackage($builder, $rootManifest);
    if ($installed === NULL && $lock === NULL) {
      $builder->addGap(new Gap(GapKind::UnreadableInput, 'Neither the Composer installed metadata nor composer.lock could be read.'));
      return CollectorStatus::Failed;
    }

    // Package data keyed by name, with the scope each one is used in.
    $packages = [];
    $installedPackages = $installed['packages'] ?? [];
    $devNames = array_flip($installed['dev-package-names'] ?? []);
    foreach ($installedPackages as $data) {
      $scope = isset($devNames[$data['name']]) ? Scope::Development : Scope::Production;
      $packages[$data['name']] = $data;
      $this->addPackage($builder, $data, $installedSource);
      $this->addInstalledOccurrence($builder, $data, $scope, $installedSource);
    }

    $status = $unreadable ? CollectorStatus::Partial : CollectorStatus::Complete;
    if ($installed === NULL) {
      $status = CollectorStatus::Partial;
      $builder->addGap(new Gap(GapKind::UnreadableInput, 'The Composer installed metadata is missing; packages come from composer.lock only and their presence is unknown.'));
    }
    foreach (['packages' => Scope::Production, 'packages-dev' => Scope::Development] as $key => $scope) {
      foreach ($lock[$key] ?? [] as $data) {
        $id = 'composer:' . $data['name'];
        if (isset($packages[$data['name']])) {
          if ($data['version'] !== $packages[$data['name']]['version']) {
            $builder->addGap(new Gap(
              GapKind::VersionConflict,
              sprintf('composer.lock declares %s, %s is installed.', $data['version'], $packages[$data['name']]['version']),
              subject: $id,
            ));
          }
          continue;
        }
        $packages[$data['name']] = $data;
        $this->addPackage($builder, $data, 'composer.lock');
        $builder->addOccurrence(new Occurrence(
          'occurrence:' . $id,
          $id,
          NULL,
          $installed === NULL ? Presence::Unknown : Presence::DeclaredOnly,
          [$scope],
          ContextKind::ComposerLock,
          'composer.lock',
          [new Evidence('composer.lock', Confidence::High, $key)],
        ));
      }
    }

    $this->addRelationships($builder, $rootManifest, $packages, $installedSource);
    return $status;
  }

  /**
   * Adds the package that represents the project itself.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param array<string, mixed> $manifest
   *   The root composer.json.
   */
  private function addRootPackage(InventoryBuilder $builder, array $manifest): void {
    $builder->setRootPackage(new Package(
      'application:' . ($manifest['name'] ?? 'project'),
      Ecosystem::Application,
      $manifest['name'] ?? 'project',
      $manifest['version'] ?? NULL,
      NULL,
      LicenseClassifier::composer((array) ($manifest['license'] ?? []), [new Evidence('composer.json', Confidence::High, 'license')]),
      $manifest['description'] ?? NULL,
      is_string($manifest['homepage'] ?? NULL) ? Sanitizer::reference($manifest['homepage'], $builder->roots) : NULL,
    ));
  }

  /**
   * Adds a package from its Composer metadata.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param array<string, mixed> $data
   *   The package metadata.
   * @param string $source
   *   The metadata file, relative to the application root.
   */
  private function addPackage(InventoryBuilder $builder, array $data, string $source): void {
    $builder->addPackage(new Package(
      'composer:' . $data['name'],
      Ecosystem::Composer,
      $data['name'],
      $data['version'] ?? NULL,
      self::purl($data),
      LicenseClassifier::composer((array) ($data['license'] ?? []), [new Evidence($source, Confidence::High, 'license')]),
      $data['description'] ?? NULL,
      is_string($data['homepage'] ?? NULL) ? Sanitizer::reference($data['homepage'], $builder->roots) : NULL,
      $data['source']['reference'] ?? $data['dist']['reference'] ?? NULL,
      array_values(array_map(static fn ($patch) => Sanitizer::reference((string) $patch, $builder->roots), $data['extra']['patches_applied'] ?? [])),
    ));
  }

  /**
   * Returns the package URL of a package from a public repository.
   *
   * @param array<string, mixed> $data
   *   The package metadata.
   *
   * @return string|null
   *   The package URL, or NULL for a package from a private repository.
   */
  private static function purl(array $data): ?string {
    $host = is_string($data['notification-url'] ?? NULL) ? parse_url($data['notification-url'], PHP_URL_HOST) : NULL;
    if (!isset($data['version']) || !in_array($host, ['packagist.org', 'packages.drupal.org'], TRUE)) {
      return NULL;
    }
    $purl = 'pkg:composer/' . strtolower($data['name']) . '@' . rawurlencode($data['version']);
    return $host === 'packagist.org' ? $purl : $purl . '?repository_url=https://packages.drupal.org/8';
  }

  /**
   * Adds the occurrence of an installed package.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param array<string, mixed> $data
   *   The package metadata.
   * @param \Sparkfabrik\DrupalSbom\Model\Scope $scope
   *   The scope the package is installed for.
   * @param string $source
   *   The installed metadata file, relative to the application root.
   */
  private function addInstalledOccurrence(InventoryBuilder $builder, array $data, Scope $scope, string $source): void {
    $id = 'composer:' . $data['name'];
    $path = NULL;
    $presence = Presence::Installed;
    // Metapackages have no files and no install path.
    if (isset($data['install-path'])) {
      $absolute = $builder->roots->vendor . '/composer/' . $data['install-path'];
      $path = $builder->roots->relative($absolute);
      if ($path === NULL) {
        $presence = Presence::Unknown;
        $builder->addGap(new Gap(GapKind::UnreadableInput, 'The install path is missing or outside the application root.', subject: $id));
      }
    }
    $builder->addOccurrence(new Occurrence(
      'occurrence:' . $id,
      $id,
      $path,
      $presence,
      [$scope],
      ContextKind::ComposerVendor,
      $source,
      [new Evidence($source, Confidence::High, 'install-path')],
    ));
  }

  /**
   * Adds the dependency edges of the project and of every package.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param array<string, mixed> $manifest
   *   The root composer.json.
   * @param array<string, array<string, mixed>> $packages
   *   Package metadata keyed by name.
   * @param string $installedSource
   *   The installed metadata file, relative to the application root.
   */
  private function addRelationships(InventoryBuilder $builder, array $manifest, array $packages, string $installedSource): void {
    $rootId = (string) $builder->rootPackageId();
    // Virtual and replaced names resolve to every package that provides them.
    $providers = [];
    foreach ($packages as $name => $data) {
      foreach (array_keys(($data['replace'] ?? []) + ($data['provide'] ?? [])) as $virtual) {
        $providers[strtolower($virtual)]['composer:' . $name] = TRUE;
      }
    }
    foreach (array_keys(($manifest['replace'] ?? []) + ($manifest['provide'] ?? [])) as $virtual) {
      $providers[strtolower($virtual)][$rootId] = TRUE;
    }
    $names = [];
    foreach (array_keys($packages) as $name) {
      $names[strtolower($name)] = 'composer:' . $name;
    }
    $resolve = static function (string $target) use ($names, $providers): array {
      $key = strtolower($target);
      return isset($names[$key]) ? [$names[$key]] : array_keys($providers[$key] ?? []);
    };

    foreach (['require' => Scope::Production, 'require-dev' => Scope::Development] as $key => $scope) {
      $this->addEdges($builder, $rootId, array_keys($manifest[$key] ?? []), $resolve, [$scope], new Evidence('composer.json', Confidence::High, $key));
    }
    foreach ($packages as $name => $data) {
      $source = array_key_exists('install-path', $data) ? $installedSource : 'composer.lock';
      $this->addEdges($builder, 'composer:' . $name, array_keys($data['require'] ?? []), $resolve, [], new Evidence($source, Confidence::High, 'require'));
    }
  }

  /**
   * Adds one dependency relationship for a set of required names.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $from
   *   Identifier of the dependent package.
   * @param list<string> $required
   *   The required package names.
   * @param callable(string): list<string> $resolve
   *   Resolves a name to the identifiers of the packages that provide it.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Scope> $scopes
   *   The dependency scopes.
   * @param \Sparkfabrik\DrupalSbom\Model\Evidence $evidence
   *   Where the requirements come from.
   */
  private function addEdges(InventoryBuilder $builder, string $from, array $required, callable $resolve, array $scopes, Evidence $evidence): void {
    $to = [];
    $runtime = $builder->package(PhpRuntimeCollector::PACKAGE_ID) !== NULL;
    foreach ($required as $name) {
      $targets = $resolve($name);
      if (!str_contains($name, '/')) {
        // Platform requirements such as php or ext-json resolve to the PHP
        // runtime when it was inventoried; a polyfill can provide an
        // extension the runtime lacks.
        $platform = self::platformPackage($name);
        if (!$runtime || $platform === NULL) {
          continue;
        }
        if ($builder->package($platform) !== NULL) {
          $targets = [$platform];
        }
        elseif ($targets === []) {
          $builder->addGap(new Gap(GapKind::UnresolvedReference, sprintf('Required PHP extension %s is not loaded in the PHP runtime that took the snapshot, and no package provides it.', $name), subject: $from));
        }
      }
      elseif ($targets === []) {
        $builder->addGap(new Gap(GapKind::UnresolvedReference, sprintf('Required package %s is not installed, not locked, or provided by any package.', $name), subject: $from));
      }
      foreach ($targets as $target) {
        if ($target !== $from) {
          $to[$target] = $target;
        }
      }
    }
    if ($to !== []) {
      $builder->addRelationship(new Relationship($from, RelationshipType::DependsOn, array_values($to), [$evidence], $scopes));
    }
  }

  /**
   * Returns the PHP runtime package a platform requirement names.
   *
   * @return string|null
   *   The package identifier, or NULL for requirements outside the runtime
   *   inventory, such as lib-icu or composer-plugin-api.
   */
  private static function platformPackage(string $name): ?string {
    $name = strtolower($name);
    return match (TRUE) {
      $name === 'php' || str_starts_with($name, 'php-') => PhpRuntimeCollector::PACKAGE_ID,
      str_starts_with($name, 'ext-') => 'php:' . $name,
      default => NULL,
    };
  }

}
