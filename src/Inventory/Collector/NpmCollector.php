<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Collector;

use Sparkfabrik\DrupalSbom\Inventory\CollectorInterface;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\LockfileGraph;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\PnpmLockfile;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\YarnLockfile;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Confidence;
use Sparkfabrik\DrupalSbom\Model\ContextKind;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Evidence;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Identifier;
use Sparkfabrik\DrupalSbom\Model\Occurrence;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Scope;

/**
 * Collects npm packages from the lockfiles found in the codebase.
 *
 * Supports npm lockfile versions 1, 2 and 3, including workspaces, links and
 * nested copies, Yarn lockfiles (classic and Berry) and pnpm lockfiles
 * (versions 6 and 9). Every lockfile keeps its own context: the extension or
 * package around it owns its dependencies. Bun lockfiles are reported as
 * coverage gaps.
 */
final class NpmCollector implements CollectorInterface {

  /**
   * Lockfiles in order of precedence within one directory.
   */
  private const NPM_LOCKFILES = ['npm-shrinkwrap.json', 'package-lock.json'];

  /**
   * Lockfiles of package managers that are not supported yet.
   */
  private const UNSUPPORTED_LOCKFILES = ['bun.lockb', 'bun.lock'];

  /**
   * Groups of a project's direct dependencies and their scope.
   */
  private const ROOT_GROUPS = [
    'dependencies' => Scope::Production,
    'optionalDependencies' => Scope::Optional,
    'devDependencies' => Scope::Development,
  ];

  /**
   * Yarn and pnpm lockfiles, used when a directory has no npm lockfile.
   */
  private const GRAPH_LOCKFILES = ['pnpm-lock.yaml', 'yarn.lock'];

  /**
   * Directory names that are never searched for lockfiles.
   *
   * Test fixtures ship lockfiles that describe no dependency of the site.
   */
  private const SKIPPED_DIRECTORIES = ['node_modules', '.git', 'files', 'private', 'tests', 'fixtures'];

  /**
   * Directories of the web root that hold core and contributed code.
   */
  private const CONTRIBUTED_DIRECTORIES = ['core', 'modules/contrib', 'themes/contrib', 'profiles/contrib', 'libraries'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'npm';
  }

  /**
   * {@inheritdoc}
   */
  public function version(): string {
    return '3';
  }

  /**
   * {@inheritdoc}
   */
  public function collect(InventoryBuilder $builder): CollectorStatus {
    $status = CollectorStatus::Complete;
    $web = $builder->roots->relative($builder->roots->web);
    $lockfiles = $this->lockfiles($builder);
    foreach ($lockfiles as $directory => $files) {
      if ($builder->customLockfilesOnly && self::isContributed($directory, $web)) {
        foreach ($files as $file) {
          $builder->addGap(new Gap(GapKind::Excluded, 'Lockfiles of Drupal core and contributed code are excluded by the DruBOM settings.', path: $this->join($directory, $file)));
        }
        continue;
      }
      $npm = array_values(array_intersect(self::NPM_LOCKFILES, $files));
      foreach (array_intersect(self::UNSUPPORTED_LOCKFILES, $files) as $file) {
        $builder->addGap(new Gap(GapKind::UnsupportedFormat, sprintf('%s lockfiles are not parsed yet.', $file), path: $this->join($directory, $file)));
        $status = CollectorStatus::Partial;
      }
      $graph = array_values(array_intersect(self::GRAPH_LOCKFILES, $files));
      $complete = TRUE;
      if ($npm !== []) {
        $complete = $this->collectLockfile($builder, $directory, $npm[0]);
      }
      elseif ($graph !== []) {
        $complete = $this->collectGraphLockfile($builder, $directory, $graph[0]);
      }
      if (!$complete) {
        $status = CollectorStatus::Partial;
      }
    }
    return $status;
  }

  /**
   * Whether a directory holds Drupal core or contributed code.
   *
   * @param string $directory
   *   The directory, relative to the application root.
   * @param string|null $web
   *   The web root, relative to the application root.
   */
  private static function isContributed(string $directory, ?string $web): bool {
    $prefix = $web === NULL || $web === '.' ? '' : $web . '/';
    foreach (self::CONTRIBUTED_DIRECTORIES as $contributed) {
      if ($directory === $prefix . $contributed || str_starts_with($directory, $prefix . $contributed . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds lockfiles below the application root.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory builder.
   *
   * @return array<string, list<string>>
   *   Lockfile names keyed by directory, relative to the application root.
   */
  private function lockfiles(InventoryBuilder $builder): array {
    $roots = $builder->roots;
    $names = [...self::NPM_LOCKFILES, ...self::GRAPH_LOCKFILES, ...self::UNSUPPORTED_LOCKFILES];
    $directories = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($roots->application, \FilesystemIterator::SKIP_DOTS),
        static function (\SplFileInfo $file) use ($roots, $names): bool {
          if ($file->isLink()) {
            return FALSE;
          }
          if ($file->isDir()) {
            return !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, TRUE) && $file->getPathname() !== $roots->vendor;
          }
          return in_array($file->getFilename(), $names, TRUE);
        },
      ),
      \RecursiveIteratorIterator::LEAVES_ONLY,
    );
    $found = [];
    foreach ($directories as $file) {
      $directory = $roots->relative($file->getPath());
      if ($directory !== NULL) {
        $found[$directory][] = $file->getFilename();
      }
    }
    ksort($found);
    return $found;
  }

  /**
   * Collects the packages of one npm lockfile.
   *
   * @return bool
   *   Whether the lockfile was fully understood.
   */
  private function collectLockfile(InventoryBuilder $builder, string $directory, string $file): bool {
    $source = $this->join($directory, $file);
    $entries = $this->lockfileEntries($builder, $directory, $source);
    if ($entries === NULL) {
      return FALSE;
    }
    $registry = NpmRegistry::fromDirectory($builder->roots->application . '/' . $directory, $builder->roots->application, $builder->privateNpmScopes);
    $evidence = [new Evidence($source, Confidence::High)];
    $complete = TRUE;

    $ids = [];
    foreach ($entries as $key => $entry) {
      $key = (string) $key;
      if ($key === '' || ($entry['link'] ?? FALSE) === TRUE) {
        continue;
      }
      if (!self::isContained($key) || !is_array($entry)) {
        $complete = FALSE;
        $builder->addGap(new Gap(GapKind::UnreadableInput, sprintf('Skipped the entry "%s", which points outside the lockfile directory.', $key), path: $source));
        continue;
      }
      $ids[$key] = $this->addEntry($builder, $directory, $source, $key, $entry, $evidence, $registry);
    }
    $ids = $this->addLinks($builder, $directory, $source, $entries, $ids, $evidence);
    $owner = $this->owner($builder, $directory);
    foreach ($entries as $key => $entry) {
      $from = $key === '' ? $owner : ($ids[$key] ?? NULL);
      if ($from !== NULL && !$this->addDependencies($builder, $source, $entries, (string) $key, $entry, $from, $ids, $evidence)) {
        $complete = FALSE;
      }
    }
    return $complete;
  }

  /**
   * Reads the entries of an npm lockfile as version 2 entries.
   *
   * @return array<array-key, mixed>|null
   *   Entries keyed by their location relative to the lockfile, or NULL when
   *   the lockfile cannot be read.
   */
  private function lockfileEntries(InventoryBuilder $builder, string $directory, string $source): ?array {
    $data = $builder->readJson($builder->roots->application . '/' . $source);
    if ($data === NULL) {
      return NULL;
    }
    $version = $data['lockfileVersion'] ?? 1;
    if ($version === 1 && is_array($data['dependencies'] ?? [])) {
      // Version 1 nests copies and names no direct dependencies: the manifest
      // beside the lockfile declares them.
      $manifest = $builder->readJson($builder->roots->application . '/' . $this->join($directory, 'package.json')) ?? [];
      return ['' => array_intersect_key($manifest, self::ROOT_GROUPS)] + self::version1Entries($data['dependencies'] ?? [], '');
    }
    if (in_array($version, [2, 3], TRUE) && is_array($data['packages'] ?? NULL)) {
      return $data['packages'];
    }
    $builder->addGap(new Gap(GapKind::UnsupportedFormat, sprintf('npm lockfile version %s is not supported; versions 1, 2 and 3 are.', is_scalar($version) ? $version : '?'), path: $source));
    return NULL;
  }

  /**
   * Records the link locations of workspace entries.
   *
   * Links point to a workspace entry elsewhere in the same lockfile; the
   * link location is another appearance of that package.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $directory
   *   The lockfile directory.
   * @param string $source
   *   The lockfile path.
   * @param array<array-key, mixed> $entries
   *   The lockfile entries.
   * @param array<string, string> $ids
   *   Package identifiers keyed by entry.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   The lockfile evidence.
   *
   * @return array<string, string>
   *   The identifiers, including the links.
   */
  private function addLinks(InventoryBuilder $builder, string $directory, string $source, array $entries, array $ids, array $evidence): array {
    foreach ($entries as $key => $entry) {
      $key = (string) $key;
      if (($entry['link'] ?? FALSE) !== TRUE || !self::isContained($key) || !isset($ids[$entry['resolved'] ?? ''])) {
        continue;
      }
      $ids[$key] = $ids[$entry['resolved']];
      $path = $this->join($directory, $key);
      $builder->addOccurrence(new Occurrence(
        Identifier::local('occurrence:', $path),
        $ids[$key],
        $path,
        is_link($builder->roots->application . '/' . $path) ? Presence::Unknown : Presence::DeclaredOnly,
        [Scope::Production],
        ContextKind::NpmLockfile,
        $source,
        $evidence,
      ));
    }
    return $ids;
  }

  /**
   * Records the dependencies of one lockfile entry.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $source
   *   The lockfile path.
   * @param array<array-key, mixed> $entries
   *   The lockfile entries.
   * @param string $key
   *   The entry key; '' for the project.
   * @param mixed $entry
   *   The entry.
   * @param string $from
   *   The package the entry stands for.
   * @param array<string, string> $ids
   *   Package identifiers keyed by entry.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   The lockfile evidence.
   *
   * @return bool
   *   Whether every required dependency was resolved.
   */
  private function addDependencies(InventoryBuilder $builder, string $source, array $entries, string $key, mixed $entry, string $from, array $ids, array $evidence): bool {
    $complete = TRUE;
    $groups = [
      'dependencies' => [Scope::Production],
      'optionalDependencies' => [Scope::Optional],
      'peerDependencies' => [Scope::Peer],
    ];
    // Only the project and its workspaces declare development dependencies.
    if (!str_contains($key, 'node_modules/')) {
      $groups['devDependencies'] = [Scope::Development];
    }
    foreach ($groups as $group => $scopes) {
      $to = [];
      foreach (array_keys($entry[$group] ?? []) as $name) {
        $target = $this->resolve($entries, $key, (string) $name);
        if ($target !== NULL && isset($ids[$target])) {
          $to[$ids[$target]] = $ids[$target];
        }
        elseif ($group === 'dependencies' || $group === 'devDependencies') {
          $complete = FALSE;
          $builder->addGap(new Gap(GapKind::UnresolvedReference, sprintf('%s requires %s, which the lockfile does not resolve.', $key === '' ? 'The project' : $key, $name), path: $source));
        }
      }
      unset($to[$from]);
      if ($to !== []) {
        $builder->addRelationship(new Relationship($from, RelationshipType::DependsOn, array_values($to), $evidence, $scopes));
      }
    }
    return $complete;
  }

  /**
   * Turns the nested tree of a version 1 lockfile into version 2 entries.
   *
   * @param array<mixed> $dependencies
   *   The "dependencies" of the lockfile or of one of its entries.
   * @param string $prefix
   *   The key of the parent entry followed by a slash, or '' at the top.
   *
   * @return array<string, mixed>
   *   Entries keyed by their location relative to the lockfile.
   */
  private static function version1Entries(array $dependencies, string $prefix): array {
    $entries = [];
    foreach ($dependencies as $name => $entry) {
      $key = $prefix . 'node_modules/' . $name;
      if (!is_array($entry)) {
        $entries[$key] = $entry;
        continue;
      }
      $nested = $entry['dependencies'] ?? [];
      $entry['dependencies'] = is_array($entry['requires'] ?? NULL) ? $entry['requires'] : [];
      unset($entry['requires']);
      $entries[$key] = $entry;
      if (is_array($nested)) {
        $entries += self::version1Entries($nested, $key . '/');
      }
    }
    return $entries;
  }

  /**
   * Collects the packages of one Yarn or pnpm lockfile.
   *
   * @return bool
   *   Whether the lockfile was fully understood.
   */
  private function collectGraphLockfile(InventoryBuilder $builder, string $directory, string $file): bool {
    $source = $this->join($directory, $file);
    $contents = $builder->read($builder->roots->application . '/' . $source);
    if ($contents === NULL) {
      return FALSE;
    }
    try {
      $graph = $this->parseGraph($builder, $directory, $file, $contents);
    }
    catch (\UnexpectedValueException $e) {
      $builder->addGap(new Gap(str_contains($e->getMessage(), 'not supported') ? GapKind::UnsupportedFormat : GapKind::UnreadableInput, $e->getMessage(), path: $source));
      return FALSE;
    }
    $evidence = [new Evidence($source, Confidence::High)];
    $ids = $this->addGraphEntries($builder, $directory, $file, $source, $graph, $evidence);

    $owner = $this->owner($builder, $directory);
    foreach (self::ROOT_GROUPS as $group => $scope) {
      $to = array_values(array_unique(array_map(static fn (string $key) => $ids[$key], $graph->roots[$group] ?? [])));
      if ($to !== []) {
        $builder->addRelationship(new Relationship($owner, RelationshipType::DependsOn, $to, $evidence, [$scope]));
      }
    }
    foreach ($graph->entries as $key => $entry) {
      $to = array_values(array_diff(array_unique(array_map(static fn (string $target) => $ids[$target], $entry['dependencies'])), [$ids[$key]]));
      if ($to !== []) {
        $builder->addRelationship(new Relationship($ids[$key], RelationshipType::DependsOn, $to, $evidence, [Scope::Production]));
      }
    }
    foreach ($graph->unresolved as $requirement) {
      $builder->addGap(new Gap(GapKind::UnresolvedReference, sprintf('%s is required but the lockfile does not resolve it.', $requirement), path: $source));
    }
    return $graph->unresolved === [];
  }

  /**
   * Parses a Yarn or pnpm lockfile.
   *
   * @throws \UnexpectedValueException
   *   When the lockfile or a Yarn workspace cannot be read.
   */
  private function parseGraph(InventoryBuilder $builder, string $directory, string $file, string $contents): LockfileGraph {
    $registry = NpmRegistry::fromDirectory($builder->roots->application . '/' . $directory, $builder->roots->application, $builder->privateNpmScopes);
    if ($file !== 'yarn.lock') {
      return PnpmLockfile::parse($contents, $registry);
    }
    $manifest = $builder->readJson($builder->roots->application . '/' . $this->join($directory, 'package.json')) ?? [];
    $workspaces = [];
    foreach (YarnLockfile::workspacePaths($contents) as $workspace) {
      $workspacePath = $builder->roots->application . '/' . $this->join($directory, $workspace . '/package.json');
      if (str_starts_with($workspace, '/') || str_contains($workspace, '\\') || $builder->roots->relative($workspacePath) === NULL) {
        throw new \UnexpectedValueException('A Yarn workspace points outside the application root.');
      }
      $workspaceManifest = $builder->readJson($workspacePath);
      if ($workspaceManifest === NULL) {
        throw new \UnexpectedValueException('A Yarn workspace manifest could not be read.');
      }
      $workspaces[] = $workspaceManifest;
    }
    return YarnLockfile::parse($contents, $manifest, $registry, $workspaces);
  }

  /**
   * Adds the packages and occurrences of a Yarn or pnpm lockfile.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $directory
   *   The lockfile directory.
   * @param string $file
   *   The lockfile name.
   * @param string $source
   *   The lockfile path.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\LockfileGraph $graph
   *   The parsed lockfile.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   The lockfile evidence.
   *
   * @return array<string, string>
   *   Package identifiers keyed by entry.
   */
  private function addGraphEntries(InventoryBuilder $builder, string $directory, string $file, string $source, LockfileGraph $graph, array $evidence): array {
    $reach = self::reach($graph);
    $ids = [];
    foreach ($graph->entries as $key => $entry) {
      $id = self::packageId($entry['name'], $entry['version'], $entry['registry']);
      if (str_contains($key, '#')) {
        $id .= substr($key, strrpos($key, '#'));
      }
      $ids[$key] = $id;
      $builder->addPackage(new Package(
        $id,
        Ecosystem::Npm,
        $entry['name'],
        $entry['version'],
        $entry['registry'] ? self::purl($entry['name'], $entry['version']) : NULL,
        LicenseClassifier::composer([], [new Evidence($source, Confidence::Medium, 'license')]),
      ));
      $scopes = $reach[$key] ?? [];
      $scopes = isset($scopes[Scope::Production->value]) ? [Scope::Production] : array_values($scopes);
      [$path, $presence] = $this->graphPresence($builder, $directory, $file, $entry['name'], $entry['version']);
      $builder->addOccurrence(new Occurrence(
        Identifier::local('occurrence:', $source . '/' . $key),
        $id,
        $path,
        $presence,
        $scopes === [] ? [Scope::Production] : $scopes,
        ContextKind::NpmLockfile,
        $source,
        $evidence,
      ));
    }
    return $ids;
  }

  /**
   * Returns the scopes each entry is reachable from.
   *
   * An entry used by a production dependency is production code; one only
   * reachable from development dependencies is a development tool.
   *
   * @return array<string, array<string, \Sparkfabrik\DrupalSbom\Model\Scope>>
   *   Scopes keyed by entry and scope value.
   */
  private static function reach(LockfileGraph $graph): array {
    $reach = [];
    foreach (self::ROOT_GROUPS as $group => $scope) {
      $pending = $graph->roots[$group] ?? [];
      while (($key = array_pop($pending)) !== NULL) {
        if (isset($reach[$key][$scope->value])) {
          continue;
        }
        $reach[$key][$scope->value] = $scope;
        array_push($pending, ...$graph->entries[$key]['dependencies']);
      }
    }
    return $reach;
  }

  /**
   * Finds where a Yarn or pnpm lockfile entry is installed.
   *
   * @return array{0: string|null, 1: \Sparkfabrik\DrupalSbom\Model\Presence}
   *   The install location relative to the application root, if any, and
   *   the presence.
   */
  private function graphPresence(InventoryBuilder $builder, string $directory, string $file, string $name, string $version): array {
    // Names and versions come from the lockfile; only npm-shaped ones may
    // become a path, so none can point outside node_modules.
    if (preg_match('#^(@[a-z0-9~][a-z0-9._~-]*/)?[a-z0-9~][a-z0-9._~-]*$#i', $name) !== 1 || preg_match('#^[A-Za-z0-9.+_-]+$#', $version) !== 1) {
      return [NULL, Presence::Unknown];
    }
    $modules = $this->join($directory, 'node_modules');
    if (!is_dir($builder->roots->application . '/' . $modules)) {
      $project = $builder->roots->application . '/' . $directory;
      $pnp = $file === 'yarn.lock' && (file_exists($project . '/.pnp.cjs') || file_exists($project . '/.pnp.loader.mjs'));
      return [NULL, $pnp ? Presence::Unknown : Presence::DeclaredOnly];
    }
    // The pnpm store keeps every version in its own directory; Yarn hoists one
    // version to the top and nests the others, which are not looked up.
    $path = $file === 'pnpm-lock.yaml'
      ? $modules . '/.pnpm/' . str_replace('/', '+', $name) . '@' . $version . '/node_modules/' . $name
      : $modules . '/' . $name;
    $presence = $this->presence($builder->roots->application . '/' . $path, $version);
    return $presence === Presence::Installed ? [$path, $presence] : [NULL, Presence::Unknown];
  }

  /**
   * Adds the package and occurrence of one lockfile entry.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $directory
   *   The lockfile directory, relative to the application root.
   * @param string $source
   *   The lockfile, relative to the application root.
   * @param string $key
   *   The entry key: its location relative to the lockfile.
   * @param array<string, mixed> $entry
   *   The entry.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   Evidence pointing to the lockfile.
   * @param \Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry $registry
   *   Local npm privacy rules.
   *
   * @return string
   *   The package identifier.
   */
  private function addEntry(InventoryBuilder $builder, string $directory, string $source, string $key, array $entry, array $evidence, NpmRegistry $registry): string {
    $position = strrpos($key, 'node_modules/');
    $name = is_string($entry['name'] ?? NULL) ? $entry['name'] : ($position === FALSE ? basename($key) : substr($key, $position + strlen('node_modules/')));
    $version = is_string($entry['version'] ?? NULL) ? $entry['version'] : NULL;
    // Only a tarball of the public registry identifies the public package;
    // workspaces, Git, file and private registry packages may share its name
    // and version.
    $public = $registry->allowsDisclosure($name) && $version !== NULL && $position !== FALSE && is_string($entry['resolved'] ?? NULL) && NpmRegistry::isPublicTarball($entry['resolved']);
    $id = self::packageId($name, $version ?? 'unknown', $public);
    $builder->addPackage(new Package(
      $id,
      Ecosystem::Npm,
      $name,
      $version,
      $public ? self::purl($name, $version) : NULL,
      LicenseClassifier::composer(is_string($entry['license'] ?? NULL) ? [$entry['license']] : [], [new Evidence($source, Confidence::Medium, 'license')]),
    ));
    if ($version === NULL) {
      $builder->addGap(new Gap(GapKind::UnknownVersion, sprintf('%s has no version in the lockfile.', $key), subject: $id));
    }

    $path = $this->join($directory, $key);
    $scopes = [];
    if (($entry['dev'] ?? FALSE) === TRUE && ($entry['devOptional'] ?? FALSE) !== TRUE) {
      $scopes[] = Scope::Development;
    }
    if (($entry['optional'] ?? FALSE) === TRUE || ($entry['devOptional'] ?? FALSE) === TRUE) {
      $scopes[] = Scope::Optional;
    }
    if (($entry['peer'] ?? FALSE) === TRUE) {
      $scopes[] = Scope::Peer;
    }
    $builder->addOccurrence(new Occurrence(
      Identifier::local('occurrence:', $path),
      $id,
      $path,
      $this->presence($builder->roots->application . '/' . $path, $version),
      $scopes === [] ? [Scope::Production] : $scopes,
      ContextKind::NpmLockfile,
      $source,
      $evidence,
    ));
    return $id;
  }

  /**
   * Tells whether a lockfile entry is installed at its location.
   *
   * Installed means the directory holds a package.json with the locked
   * version; a directory with another version, or a symlink, is unknown.
   */
  private function presence(string $directory, ?string $version): Presence {
    if (is_link($directory)) {
      return Presence::Unknown;
    }
    if (!is_dir($directory)) {
      return Presence::DeclaredOnly;
    }
    $manifest = @file_get_contents($directory . '/package.json', length: 1024 * 1024);
    $installed = is_string($manifest) ? json_decode($manifest, TRUE) : NULL;
    return is_array($installed) && $version !== NULL && ($installed['version'] ?? NULL) === $version ? Presence::Installed : Presence::Unknown;
  }

  /**
   * Returns the identifier of a package.
   *
   * A package of the public registry and a private or local package with the
   * same name and version are different packages.
   */
  private static function packageId(string $name, string $version, bool $public): string {
    return ($public ? 'npm:' : 'npm-private:') . $name . '@' . $version;
  }

  /**
   * Returns the package URL of a package of the public registry.
   */
  private static function purl(string $name, string $version): string {
    return 'pkg:npm/' . str_replace('@', '%40', $name) . '@' . rawurlencode($version);
  }

  /**
   * Whether a lockfile key stays inside the lockfile directory.
   */
  private static function isContained(string $key): bool {
    return !str_starts_with($key, '/') && !str_contains($key, '\\') && !in_array('..', explode('/', $key), TRUE);
  }

  /**
   * Resolves a dependency the way Node.js looks up node_modules.
   *
   * @param array<string, mixed> $entries
   *   The lockfile entries.
   * @param string $from
   *   Key of the dependent entry.
   * @param string $name
   *   The dependency name.
   *
   * @return string|null
   *   Key of the resolved entry.
   */
  private function resolve(array $entries, string $from, string $name): ?string {
    // Every ancestor directory that is not itself a node_modules directory
    // or a scope inside one is searched, nearest first.
    $segments = $from === '' ? [] : explode('/', $from);
    for ($length = count($segments); $length >= 0; $length--) {
      $last = $segments[$length - 1] ?? NULL;
      $parent = $segments[$length - 2] ?? NULL;
      if ($last === 'node_modules' || ($parent === 'node_modules' && str_starts_with((string) $last, '@'))) {
        continue;
      }
      $base = implode('/', array_slice($segments, 0, $length));
      $candidate = ($base === '' ? '' : $base . '/') . 'node_modules/' . $name;
      if (isset($entries[$candidate])) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Returns the element that owns a lockfile's direct dependencies.
   */
  private function owner(InventoryBuilder $builder, string $directory): string {
    $best = NULL;
    foreach ($builder->extensions() as $extension) {
      if (($directory === $extension->path || str_starts_with($directory . '/', $extension->path . '/')) && strlen($extension->path) > strlen($best === NULL ? '' : $best->path)) {
        $best = $extension;
      }
    }
    return $best === NULL ? ($builder->packageContaining($directory) ?? (string) $builder->rootPackageId()) : $best->id;
  }

  /**
   * Joins a directory relative to the application root with a name.
   */
  private function join(string $directory, string $name): string {
    return $directory === '.' ? $name : $directory . '/' . $name;
  }

}
