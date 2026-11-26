<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Lockfile;

use Sparkfabrik\DrupalSbom\Util\InvalidYamlException;
use Sparkfabrik\DrupalSbom\Util\Yaml;

/**
 * Reads pnpm lockfiles, versions 6 and 9.
 */
final class PnpmLockfile {

  /**
   * Parses a pnpm-lock.yaml file.
   *
   * @param string $contents
   *   The lockfile contents.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   The registries the project configures; the public one by default.
   *
   * @throws \UnexpectedValueException
   *   When the lockfile cannot be read or has an unsupported version.
   */
  public static function parse(string $contents, NpmRegistry $registry = new NpmRegistry()): LockfileGraph {
    [$data, $major] = self::decode($contents);
    self::validate($data);
    if ($major === 9 && ($data['packages'] ?? []) !== [] && ($data['snapshots'] ?? []) === []) {
      throw new \UnexpectedValueException('pnpm package metadata has no dependency snapshots.');
    }
    [$entries, $unresolved] = self::entries($data, $major, $registry);
    [$roots, $missing] = self::roots($data, $entries);
    return new LockfileGraph($entries, $roots, array_values(array_unique([...$unresolved, ...$missing])));
  }

  /**
   * Decodes the lockfile and checks its version.
   *
   * @return array{0: array<mixed>, 1: int}
   *   The decoded lockfile and its major version.
   */
  private static function decode(string $contents): array {
    try {
      $data = Yaml::decode($contents);
    }
    catch (InvalidYamlException $e) {
      throw new \UnexpectedValueException($e->getMessage(), 0, $e);
    }
    if (!is_array($data)) {
      throw new \UnexpectedValueException('The lockfile is not a mapping.');
    }
    if (isset($data['lockfileVersion']) && !is_scalar($data['lockfileVersion'])) {
      throw new \UnexpectedValueException('Malformed pnpm lockfile version.');
    }
    $version = (string) ($data['lockfileVersion'] ?? '');
    if (preg_match('/^\d+(?:\.\d+)?$/', $version) !== 1) {
      throw new \UnexpectedValueException('Malformed pnpm lockfile version.');
    }
    $major = (int) $version;
    if (!in_array($major, [6, 9], TRUE)) {
      throw new \UnexpectedValueException(sprintf('pnpm lockfile version %s is not supported; versions 6 and 9 are.', $version));
    }
    return [$data, $major];
  }

  /**
   * Rejects malformed collections and package records.
   *
   * @param array<mixed> $data
   *   The decoded lockfile.
   */
  private static function validate(array $data): void {
    foreach (['packages', 'snapshots', 'importers', 'dependencies', 'devDependencies', 'optionalDependencies'] as $collection) {
      if (array_key_exists($collection, $data) && !is_array($data[$collection])) {
        throw new \UnexpectedValueException(sprintf('pnpm %s must be a mapping.', $collection));
      }
    }
    foreach (['packages', 'snapshots'] as $collection) {
      foreach ($data[$collection] ?? [] as $key => $entry) {
        self::validateRecord($key, $entry);
      }
    }
  }

  /**
   * Rejects a malformed package record.
   */
  private static function validateRecord(mixed $key, mixed $entry): void {
    if (!is_string($key) || !is_array($entry) || LockfileGraph::split(self::entryKey($key))[1] === '') {
      throw new \UnexpectedValueException('Malformed pnpm package record.');
    }
    foreach (['resolution', 'dependencies', 'optionalDependencies'] as $field) {
      if (array_key_exists($field, $entry) && !is_array($entry[$field])) {
        throw new \UnexpectedValueException(sprintf('pnpm %s must be a mapping.', $field));
      }
    }
    foreach (['dependencies', 'optionalDependencies'] as $field) {
      foreach ($entry[$field] ?? [] as $name => $reference) {
        if (!is_string($name) || !is_string($reference) || $reference === '') {
          throw new \UnexpectedValueException('Malformed pnpm dependency.');
        }
      }
    }
  }

  /**
   * Builds the graph entries.
   *
   * Version 9 keeps the dependency graph in "snapshots", version 6 in
   * "packages"; keys of version 6 start with a slash.
   *
   * @param array<mixed> $data
   *   The validated lockfile.
   * @param int $major
   *   The lockfile major version.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   The configured registries.
   *
   * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
   *   The entries keyed by "name@version", and the unresolved targets.
   */
  private static function entries(array $data, int $major, NpmRegistry $registry): array {
    $entries = [];
    $graph = $major === 9 ? ($data['snapshots'] ?? []) : ($data['packages'] ?? []);
    $packages = $data['packages'] ?? [];
    foreach ($graph as $key => $entry) {
      $key = self::entryKey((string) $key);
      [$name, $locked] = LockfileGraph::split($key);
      $metadata = $packages[$major === 9 ? $key : '/' . $key] ?? $entry;
      $entries[$key] = [
        'name' => $name,
        'version' => $locked,
        'registry' => self::fromRegistry($name, $metadata['resolution'] ?? [], $registry),
        'dependencies' => [
          ...($entries[$key]['dependencies'] ?? []),
          ...self::targets($entry['dependencies'] ?? []),
          ...self::targets($entry['optionalDependencies'] ?? []),
        ],
      ];
    }
    $unresolved = [];
    foreach ($entries as $key => $entry) {
      $kept = array_filter($entry['dependencies'], static fn (string $target): bool => isset($entries[$target]));
      $unresolved = [...$unresolved, ...array_diff($entry['dependencies'], $kept)];
      $entries[$key]['dependencies'] = array_values(array_unique($kept));
    }
    return [$entries, $unresolved];
  }

  /**
   * Tells whether a resolution points to a public registry.
   *
   * @param string $name
   *   The package name.
   * @param array<string, mixed> $resolution
   *   The package resolution.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   The configured registries.
   */
  private static function fromRegistry(string $name, array $resolution, NpmRegistry $registry): bool {
    if (isset($resolution['tarball'])) {
      return $registry->allowsDisclosure($name) && NpmRegistry::isPublicTarball((string) $resolution['tarball']);
    }
    return isset($resolution['integrity']) && !isset($resolution['directory']) && !isset($resolution['repo']) && $registry->isPublic($name);
  }

  /**
   * Returns the entry keys a dependency map points to.
   *
   * @param array<array-key, string> $dependencies
   *   Dependency references keyed by name.
   *
   * @return list<string>
   *   The keys, without links to local directories.
   */
  private static function targets(array $dependencies): array {
    $targets = [];
    foreach ($dependencies as $dependency => $reference) {
      $target = self::target((string) $dependency, $reference);
      if ($target !== NULL) {
        $targets[] = $target;
      }
    }
    return $targets;
  }

  /**
   * Returns the direct dependencies of each importer.
   *
   * @param array<mixed> $data
   *   The validated lockfile.
   * @param array<string, array<string, mixed>> $entries
   *   The graph entries.
   *
   * @return array{0: array<string, list<string>>, 1: list<string>}
   *   The root keys by dependency group, and the unresolved targets.
   */
  private static function roots(array $data, array $entries): array {
    $roots = [];
    $unresolved = [];
    $importers = $data['importers'] ?? ['.' => $data];
    foreach ($importers as $path => $importer) {
      if (!is_string($path) || !is_array($importer)) {
        throw new \UnexpectedValueException('Malformed pnpm importer.');
      }
      foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $group) {
        foreach (self::requirements($importer, $group) as $target) {
          if (isset($entries[$target])) {
            $roots[$group][] = $target;
          }
          else {
            $unresolved[] = $target;
          }
        }
      }
    }
    return [$roots, $unresolved];
  }

  /**
   * Returns the entry keys an importer requires in one dependency group.
   *
   * @param array<mixed> $importer
   *   The importer.
   * @param string $group
   *   The dependency group.
   *
   * @return list<string>
   *   The keys, without links to local directories.
   */
  private static function requirements(array $importer, string $group): array {
    if (!array_key_exists($group, $importer)) {
      return [];
    }
    if (!is_array($importer[$group])) {
      throw new \UnexpectedValueException('pnpm importer dependencies must be a mapping.');
    }
    $references = [];
    foreach ($importer[$group] as $dependency => $requirement) {
      if (!is_string($dependency) || (!is_string($requirement) && (!is_array($requirement) || !is_string($requirement['version'] ?? NULL)))) {
        throw new \UnexpectedValueException('Malformed pnpm importer dependency.');
      }
      $references[$dependency] = is_array($requirement) ? $requirement['version'] : $requirement;
    }
    return self::targets($references);
  }

  /**
   * Normalizes a graph key to "name@version" without peer suffixes.
   */
  private static function entryKey(string $key): string {
    $key = ltrim($key, '/');
    $peers = strpos($key, '(');
    return $peers === FALSE ? $key : substr($key, 0, $peers);
  }

  /**
   * Returns the entry key a dependency reference points to.
   *
   * @return string|null
   *   The key, or NULL for links to workspace or local directories.
   */
  private static function target(string $name, string $reference): ?string {
    if ($reference === '' || str_starts_with($reference, 'link:') || str_starts_with($reference, 'file:')) {
      return NULL;
    }
    $reference = self::entryKey($reference);
    // An alias reference names the real package: "npm-name@version".
    if (str_contains(ltrim($reference, '@'), '@')) {
      return $reference;
    }
    return $name . '@' . $reference;
  }

}
