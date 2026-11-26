<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Lockfile;

use Sparkfabrik\DrupalSbom\Util\InvalidYamlException;
use Sparkfabrik\DrupalSbom\Util\Yaml;

/**
 * Reads Yarn lockfiles: the classic format (v1) and Yarn Berry (v2 and later).
 */
final class YarnLockfile {

  /**
   * Parses a yarn.lock file.
   *
   * @param string $contents
   *   The lockfile contents.
   * @param array<string, mixed> $manifest
   *   The package.json next to the lockfile, which names the direct
   *   dependencies: the lockfile does not tell them apart.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   The registries the project configures; the public one by default.
   * @param list<array<string, mixed>> $workspaces
   *   Child workspace manifests.
   *
   * @throws \UnexpectedValueException
   *   When the lockfile cannot be read.
   */
  public static function parse(string $contents, array $manifest, NpmRegistry $registry = new NpmRegistry(), array $workspaces = []): LockfileGraph {
    $records = str_contains($contents, "\n__metadata:") || str_starts_with($contents, '__metadata:')
      ? self::berryRecords($contents, $registry)
      : self::classicRecords($contents, $registry);

    // Every requirement spec points to the entry that satisfies it.
    $entries = [];
    $specs = [];
    foreach ($records as $record) {
      $key = self::key($record);
      // A patched copy shares the name and version of the registry package.
      $entries[$key] = [
        'name' => $record['name'],
        'version' => $record['version'],
        'registry' => $record['registry'] || ($entries[$key]['registry'] ?? FALSE),
        'dependencies' => [],
      ];
      foreach ($record['specs'] as $spec) {
        $specs[$spec] = $key;
      }
    }
    $unresolved = [];
    $lookup = static function (string $name, string $range) use ($specs): ?string {
      return $specs[$name . '@' . $range] ?? $specs[$name . '@npm:' . $range] ?? NULL;
    };
    foreach ($records as $record) {
      $key = self::key($record);
      foreach ($record['dependencies'] as $name => $range) {
        $target = $lookup($name, $range);
        if ($target === NULL) {
          $unresolved[] = $name . '@' . $range;
          continue;
        }
        $entries[$key]['dependencies'][] = $target;
      }
      $entries[$key]['dependencies'] = array_values(array_unique($entries[$key]['dependencies']));
    }
    $roots = [];
    foreach ([$manifest, ...$workspaces] as $manifest) {
      foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $group) {
        foreach (is_array($manifest[$group] ?? NULL) ? $manifest[$group] : [] as $name => $range) {
          $target = is_string($range) ? $lookup((string) $name, $range) : NULL;
          if ($target === NULL) {
            // Workspace and link requirements have no locked package.
            if (is_string($range) && !preg_match('/^(workspace|link|file|portal):/', $range)) {
              $unresolved[] = $name . '@' . $range;
            }
            continue;
          }
          $roots[$group][] = $target;
        }
      }
    }
    return new LockfileGraph($entries, $roots, $unresolved);
  }

  /**
   * Returns a resolution key without credentials or query parameters.
   *
   * @param array{name: string, version: string, registry: bool, source: string} $record
   *   The lockfile record.
   */
  private static function key(array $record): string {
    $key = $record['name'] . '@' . $record['version'];
    if ($record['registry']) {
      return $key;
    }
    $source = (string) preg_replace('/\?[^#]*(?=#|$)/s', '', $record['source']);
    $source = (string) preg_replace('#(?<=//)[^/]*@#', '', $source);
    return $key . '#' . hash('sha256', $source);
  }

  /**
   * Returns child workspace paths declared by a Berry lockfile.
   *
   * @return list<string>
   *   Paths relative to the lockfile directory.
   */
  public static function workspacePaths(string $contents): array {
    if (!str_contains($contents, "\n__metadata:") && !str_starts_with($contents, '__metadata:')) {
      return [];
    }
    self::berryRecords($contents, new NpmRegistry());
    $paths = [];
    foreach (Yaml::decode($contents) as $entry) {
      if (is_array($entry) && is_string($entry['resolution'] ?? NULL)) {
        [, $reference] = LockfileGraph::split($entry['resolution']);
        if (str_starts_with($reference, 'workspace:') && $reference !== 'workspace:.') {
          $paths[] = substr($reference, strlen('workspace:'));
        }
      }
    }
    return array_values(array_unique($paths));
  }

  /**
   * Reads the records of a classic (v1) yarn.lock.
   *
   * @param string $contents
   *   The lockfile contents.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   Local npm privacy rules.
   *
   * @return list<array{name: string, version: string, registry: bool, source: string, specs: list<string>, dependencies: array<string, string>}>
   *   The records.
   */
  private static function classicRecords(string $contents, NpmRegistry $registry): array {
    $records = [];
    $current = NULL;
    $section = NULL;
    $lines = preg_split('/\R/', $contents);
    foreach ($lines === FALSE ? [] : $lines as $line) {
      if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
        continue;
      }
      $indent = strlen($line) - strlen(ltrim($line, ' '));
      $text = trim($line);
      if ($indent === 0) {
        if ($current !== NULL) {
          $records[] = $current;
        }
        if (!str_ends_with($text, ':')) {
          throw new \UnexpectedValueException('Unexpected top-level line.');
        }
        $specs = array_map(static fn (string $spec) => trim($spec, " \""), explode(',', substr($text, 0, -1)));
        [$name, $range] = LockfileGraph::split($specs[0]);
        $current = [
          // An alias spec, "alias@npm:name@range", names the real package.
          'name' => str_starts_with($range, 'npm:') ? LockfileGraph::split(substr($range, 4))[0] : $name,
          'version' => '',
          'registry' => FALSE,
          'source' => $range,
          'specs' => $specs,
          'dependencies' => [],
        ];
        $section = NULL;
        continue;
      }
      if ($current === NULL) {
        throw new \UnexpectedValueException('Indented line before the first entry.');
      }
      if ($indent === 2) {
        $section = NULL;
        if (str_ends_with($text, ':')) {
          $section = substr($text, 0, -1);
          continue;
        }
        [$field, $value] = self::pair($text);
        if ($field === 'version') {
          $current['version'] = $value;
        }
        elseif ($field === 'resolved') {
          $current['registry'] = $registry->allowsDisclosure($current['name']) && NpmRegistry::isPublicTarball($value);
          $current['source'] = $value;
        }
        continue;
      }
      if ($section === 'dependencies' || $section === 'optionalDependencies') {
        [$name, $range] = self::pair($text);
        $current['dependencies'][$name] = $range;
      }
    }
    if ($current !== NULL) {
      $records[] = $current;
    }
    return array_values(array_filter($records, static fn (array $record) => $record['version'] !== ''));
  }

  /**
   * Splits a classic yarn.lock line into a key and a value.
   *
   * @return array{0: string, 1: string}
   *   The key and the value, without quotes.
   */
  private static function pair(string $text): array {
    if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\S+)\s+(.*)$/', $text, $matches) !== 1) {
      return [trim($text, '"'), ''];
    }
    return [trim($matches[1], '"'), trim($matches[2], '"')];
  }

  /**
   * Reads the records of a Yarn Berry lockfile.
   *
   * @return list<array{name: string, version: string, registry: bool, source: string, specs: list<string>, dependencies: array<string, string>}>
   *   The records; workspaces and links are left out.
   */
  private static function berryRecords(string $contents, NpmRegistry $registry): array {
    try {
      $data = Yaml::decode($contents);
    }
    catch (InvalidYamlException $e) {
      throw new \UnexpectedValueException($e->getMessage(), 0, $e);
    }
    if (!is_array($data)) {
      throw new \UnexpectedValueException('The lockfile is not a mapping.');
    }
    $records = [];
    foreach ($data as $specs => $entry) {
      if ($specs === '__metadata') {
        if (!is_array($entry) || !isset($entry['version']) || (!is_int($entry['version']) && (!is_string($entry['version']) || !ctype_digit($entry['version'])))) {
          throw new \UnexpectedValueException('Malformed Yarn metadata.');
        }
        continue;
      }
      $record = self::berryRecord($specs, $entry, $registry);
      if ($record !== NULL) {
        $records[] = $record;
      }
    }
    return $records;
  }

  /**
   * Reads one record of a Yarn Berry lockfile.
   *
   * @return array{name: string, version: string, registry: bool, source: string, specs: list<string>, dependencies: array<string, string>}|null
   *   The record, or NULL for a workspace or link.
   */
  private static function berryRecord(mixed $specs, mixed $entry, NpmRegistry $registry): ?array {
    if (!is_string($specs) || !is_array($entry) || !is_string($entry['resolution'] ?? NULL) || !is_string($entry['version'] ?? NULL) || $entry['version'] === '') {
      throw new \UnexpectedValueException('Malformed Yarn package record.');
    }
    if (array_key_exists('dependencies', $entry) && !is_array($entry['dependencies'])) {
      throw new \UnexpectedValueException('Yarn dependencies must be a mapping.');
    }
    [$name, $reference] = LockfileGraph::split($entry['resolution']);
    if (str_starts_with($reference, 'patch:')) {
      [, $reference] = LockfileGraph::split(rawurldecode(explode('#', substr($reference, 6), 2)[0]));
    }
    $dependencies = [];
    foreach ($entry['dependencies'] ?? [] as $dependency => $range) {
      if (!is_string($dependency) || !is_string($range)) {
        throw new \UnexpectedValueException('Malformed Yarn dependency.');
      }
      $dependencies[$dependency] = $range;
    }
    if (!str_starts_with($reference, 'npm:') && ($entry['linkType'] ?? 'hard') === 'soft') {
      return NULL;
    }
    return [
      'name' => $name,
      'version' => $entry['version'],
      'registry' => str_starts_with($reference, 'npm:') && $registry->isPublic($name),
      'source' => $reference,
      'specs' => array_map('trim', explode(',', $specs)),
      'dependencies' => $dependencies,
    ];
  }

}
