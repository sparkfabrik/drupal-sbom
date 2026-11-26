<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use Sparkfabrik\DrupalSbom\Inventory\Collector\NpmCollector;
use Sparkfabrik\DrupalSbom\Inventory\DevelopmentExclusion;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Extension;
use Sparkfabrik\DrupalSbom\Model\ExtensionType;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Scope;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the npm collector against a project fixture.
 */
#[CoversClass(NpmCollector::class)]
#[Group('drubom')]
final class NpmCollectorTest extends TestCase {

  /**
   * The lockfile of the example theme.
   */
  private const LOCKFILE = 'web/themes/custom/example/package-lock.json';

  /**
   * The collected snapshot.
   */
  private Snapshot $snapshot;

  /**
   * The collector status.
   */
  private CollectorStatus $status;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/npm');
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    $builder->setRootPackage(new Package('application:example/npm-site', Ecosystem::Application, 'example/npm-site', NULL, NULL, LicenseClassifier::composer([], [])));
    $builder->addExtension(new Extension('extension:example', 'example', ExtensionType::Theme, TRUE, 'application:example/npm-site', 'web/themes/custom/example'));
    $this->status = (new NpmCollector())->collect($builder);
    $this->snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
  }

  /**
   * Unsupported lockfiles make the collection partial.
   */
  public function testUnsupportedLockfiles(): void {
    $this->assertSame(CollectorStatus::Partial, $this->status);
    $this->assertSame(['web/themes/custom/legacy/bun.lock'], $this->gapPaths(GapKind::UnsupportedFormat));
  }

  /**
   * A version 1 lockfile nests copies and takes its roots from package.json.
   */
  public function testVersion1Lockfile(): void {
    $snapshot = $this->project('npm-v1');
    $this->assertSame([
      'app-lib@1.0.0',
      'fsevents@1.2.13',
      'gulp@3.9.1',
      'lodash@3.10.1',
      'local-tool@file:../tool',
      'lodash@4.17.21',
    ], array_map(static fn ($package) => $package->name . '@' . $package->version, array_values(array_filter($snapshot->packages, static fn ($package) => $package->ecosystem === Ecosystem::Npm))));
    $this->assertNull($snapshot->package('npm-private:local-tool@file:../tool')?->purl, 'A local package gets no public identity.');
    $occurrences = [];
    foreach ($snapshot->occurrences as $occurrence) {
      $occurrences[$occurrence->path] = $occurrence;
    }
    $this->assertSame(Presence::Installed, $occurrences['web/legacy/node_modules/lodash']->presence);
    $this->assertSame(Presence::DeclaredOnly, $occurrences['web/legacy/node_modules/gulp/node_modules/lodash']->presence);
    $this->assertSame([Scope::Development], $occurrences['web/legacy/node_modules/gulp/node_modules/lodash']->scopes);
    $this->assertSame([Scope::Optional], $occurrences['web/legacy/node_modules/fsevents']->scopes);

    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      $edges[$relationship->from][$relationship->scopes[0]->value] = $relationship->to;
    }
    $this->assertSame([
      'production' => ['npm:app-lib@1.0.0', 'npm-private:local-tool@file:../tool'],
      'optional' => ['npm:fsevents@1.2.13'],
      'development' => ['npm:gulp@3.9.1'],
    ], $edges['application:test']);
    $this->assertSame(['production' => ['npm:lodash@4.17.21']], $edges['npm:app-lib@1.0.0']);
    $this->assertSame(['production' => ['npm:lodash@3.10.1']], $edges['npm:gulp@3.9.1'], 'The nested copy wins.');
    $this->assertSame(CollectorStatus::Partial, $this->status);
    $this->assertSame(['UnresolvedReference node_modules/gulp requires missing-dep, which the lockfile does not resolve.'], array_map(static fn ($gap) => $gap->kind->name . ' ' . $gap->detail, $snapshot->coverage->gaps));
  }

  /**
   * Duplicate copies are one package with several occurrences.
   */
  public function testNestedCopies(): void {
    $lodash = $this->snapshot->package('npm:lodash@4.17.21');
    $this->assertSame('pkg:npm/lodash@4.17.21', $lodash->purl);
    $this->assertSame('MIT', $lodash->license->expression);
    $occurrences = $this->occurrencesOf('npm:lodash@4.17.21');
    $this->assertSame([
      'web/themes/custom/example/node_modules/lodash',
      'web/themes/custom/example/node_modules/sass/node_modules/lodash',
    ], array_keys($occurrences));
    $this->assertSame(Presence::Installed, $occurrences['web/themes/custom/example/node_modules/lodash']->presence);
    $this->assertSame(Presence::DeclaredOnly, $occurrences['web/themes/custom/example/node_modules/sass/node_modules/lodash']->presence);
    $this->assertSame([Scope::Development], $occurrences['web/themes/custom/example/node_modules/sass/node_modules/lodash']->scopes);
    $this->assertNotNull($this->snapshot->package('npm-private:lodash@3.10.1'), 'A copy without a registry tarball is not the public package.');
  }

  /**
   * Scoped names, workspaces and flags are understood.
   */
  public function testEntries(): void {
    $this->assertSame('pkg:npm/%40parcel/watcher@2.4.1', $this->snapshot->package('npm:@parcel/watcher@2.4.1')->purl);
    $this->assertSame([Scope::Optional], $this->occurrencesOf('npm:@parcel/watcher@2.4.1')['web/themes/custom/example/node_modules/@parcel/watcher']->scopes);
    $this->assertNotNull($this->snapshot->package('npm-private:@acme/ui@0.1.0'));
    $this->assertNull($this->snapshot->package('npm-private:@acme/ui@0.1.0')->purl, 'A workspace package gets no public identity.');
    $this->assertSame('non-standard', $this->snapshot->package('npm-private:@acme/ui@0.1.0')->license->classification->value);
  }

  /**
   * Dependencies follow the Node.js lookup, links and the owning theme.
   */
  public function testRelationships(): void {
    $this->assertSame([
      'production' => ['npm:lodash@4.17.21', 'npm-private:@acme/ui@0.1.0'],
      'development' => ['npm:sass@1.80.4'],
    ], $this->edgesFrom('extension:example'));
    $this->assertSame(['production' => ['npm-private:lodash@3.10.1']], $this->edgesFrom('npm-private:@acme/ui@0.1.0'));
    $this->assertSame([
      'production' => ['npm:chokidar@4.0.1'],
      'optional' => ['npm:@parcel/watcher@2.4.1'],
    ], $this->edgesFrom('npm:sass@1.80.4'));
    $this->assertContains(self::LOCKFILE, $this->gapPaths(GapKind::UnresolvedReference));
    $this->assertSame([], $this->snapshot->integrityErrors());
  }

  /**
   * Edge cases: ancestor lookup, links, escaping keys and future formats.
   */
  public function testEdgeCases(): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/npm-edge');
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    $builder->setRootPackage(new Package('application:example/npm-edge', Ecosystem::Application, 'example/npm-edge', NULL, NULL, LicenseClassifier::composer([], [])));
    (new NpmCollector())->collect($builder);
    $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);

    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      $edges[$relationship->from] = $relationship->to;
    }
    $this->assertSame(['npm:example@2.0.0'], $edges['npm-private:@acme/ui@0.1.0'], 'The nearest ancestor node_modules wins.');
    $this->assertNull($snapshot->package('npm-private:@company/internal@1.0.0')?->purl, 'A private registry package gets no public identity.');
    $this->assertSame('pkg:npm/example@1.0.0', $snapshot->package('npm:example@1.0.0')?->purl);
    $this->assertNull($snapshot->package('npm-private:example@1.0.0')?->purl, 'A local copy stays apart from the public package.');
    $paths = array_map(static fn ($occurrence) => $occurrence->path, $snapshot->occurrences);
    $this->assertContains('app/node_modules/@acme/ui', $paths, 'A link location is an occurrence.');
    $this->assertNotContains('outside', array_map(static fn ($package) => $package->name, $snapshot->packages));
    $kinds = array_map(static fn ($gap) => $gap->kind->value . ' ' . $gap->path, $snapshot->coverage->gaps);
    $this->assertContains('unreadable-input app/package-lock.json', $kinds);
    $this->assertContains('unsupported-format future/package-lock.json', $kinds);
    $this->assertSame([], $snapshot->integrityErrors());
  }

  /**
   * Yarn classic, Yarn Berry, pnpm 6 and pnpm 9 lockfiles are inventoried.
   */
  public function testYarnAndPnpm(): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/yarn-pnpm');
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    $builder->setRootPackage(new Package('application:example/yarn-pnpm', Ecosystem::Application, 'example/yarn-pnpm', NULL, NULL, LicenseClassifier::composer([], [])));
    $this->assertSame(CollectorStatus::Complete, (new NpmCollector())->collect($builder));
    $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);

    $rows = [];
    foreach ($snapshot->occurrences as $occurrence) {
      $rows[$occurrence->package . ' ' . $occurrence->contextSource] = [
        $occurrence->presence->value,
        implode(',', array_map(static fn ($scope) => $scope->value, $occurrence->scopes)),
        $occurrence->path,
      ];
    }
    ksort($rows);
    $classic = 'web/themes/custom/classic/yarn.lock';
    $berry = 'web/themes/custom/berry/yarn.lock';
    $nine = 'web/modules/custom/pnpm9/pnpm-lock.yaml';
    $six = 'web/modules/custom/pnpm6/pnpm-lock.yaml';
    $this->assertSame([
      "npm-private:@acme/util@2.0.0 $nine" => [
        'installed',
        'production',
        'web/modules/custom/pnpm9/node_modules/.pnpm/@acme+util@2.0.0/node_modules/@acme/util',
      ],
      "npm-private:@company/internal@1.0.0#e49dcb282afe6fd5e54ab92af809e7eb1368ee673a35893ad762748afb7663ac $classic" => [
        'unknown', 'production', NULL,
      ],
      "npm:@babel/core@7.24.0 $classic" => ['unknown', 'development', NULL],
      "npm:axios@1.6.8 $six" => ['declared-only', 'production', NULL],
      "npm:follow-redirects@1.15.6 $six" => ['declared-only', 'production', NULL],
      "npm:jest-lite@1.2.0 $classic" => ['unknown', 'development', NULL],
      "npm:lodash@4.17.21 $classic" => ['installed', 'production', 'web/themes/custom/classic/node_modules/lodash'],
      "npm:loose-envify@1.4.0 $berry" => ['declared-only', 'production', NULL],
      "npm:react@18.3.1 $berry" => ['declared-only', 'production', NULL],
      "npm:string-width@4.2.3 $classic" => ['unknown', 'production', NULL],
      "npm:tinypool@0.8.4 $nine" => ['unknown', 'development', NULL],
      "npm:typescript@5.4.5 $berry" => ['declared-only', 'development', NULL],
      "npm:vitest@1.6.0 $nine" => ['unknown', 'development', NULL],
    ], $rows);
    $this->assertSame('pkg:npm/string-width@4.2.3', $snapshot->package('npm:string-width@4.2.3')?->purl, 'An alias is the package it points to.');
    $this->assertSame('pkg:npm/%40babel/core@7.24.0', $snapshot->package('npm:@babel/core@7.24.0')?->purl);
    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      $edges[$relationship->from][] = $relationship->to;
    }
    $this->assertSame([['npm-private:@acme/util@2.0.0', 'npm:tinypool@0.8.4']], $edges['npm:vitest@1.6.0'], 'A scope with a private registry is not public.');
    $this->assertSame([['npm:loose-envify@1.4.0']], $edges['npm:react@18.3.1']);
    $this->assertSame([], $snapshot->coverage->gaps);
    $this->assertSame([], $snapshot->integrityErrors());
  }

  /**
   * A package from an inherited private registry stays private.
   */
  public function testInheritedRegistry(): void {
    $snapshot = $this->project('npm-inherited');
    $private = $snapshot->package('npm-private:@acme/tool@1.0.0#5ccebd46e82a2d4a112e77ef9aa31c16b26ae302d07d9a154c2c416fcb60e94b');
    $this->assertNotNull($private);
    $this->assertNull($private->purl);
    $this->assertSame('pkg:npm/%40acme/tool@1.0.0', $snapshot->package('npm:@acme/tool@1.0.0')?->purl);
    $this->assertStringNotContainsString('private.test', (string) json_encode($snapshot->toArray()));
  }

  /**
   * Lockfile names that are not npm names never become a path.
   */
  public function testHostileGraphNames(): void {
    $root = realpath(sys_get_temp_dir()) . '/drubom-hostile-' . bin2hex(random_bytes(4));
    mkdir($root . '/web/node_modules', 0777, TRUE);
    mkdir($root . '/web/outside');
    file_put_contents($root . '/web/package.json', '{"dependencies": {"../outside": "^1.0.0"}}');
    file_put_contents($root . '/web/yarn.lock', "\"../outside@^1.0.0\":\n  version \"1.0.0\"\n");
    file_put_contents($root . '/web/outside/package.json', '{"version": "1.0.0"}');
    try {
      $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
      $builder->setRootPackage(new Package('application:example/hostile', Ecosystem::Application, 'example/hostile', NULL, NULL, LicenseClassifier::composer([], [])));
      (new NpmCollector())->collect($builder);
      $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
      $occurrence = $snapshot->occurrences[0];
      $this->assertStringStartsWith('npm-private:../outside@1.0.0#', $occurrence->package);
      $this->assertNull($occurrence->path);
      $this->assertSame(Presence::Unknown, $occurrence->presence);
    }
    finally {
      foreach (['web/outside/package.json', 'web/yarn.lock', 'web/package.json'] as $file) {
        unlink($root . '/' . $file);
      }
      foreach (['web/outside', 'web/node_modules', 'web', ''] as $directory) {
        rmdir($root . '/' . $directory);
      }
    }
  }

  /**
   * Custom-only scope skips core and contributed lockfiles and lists them.
   */
  public function testCustomLockfilesOnly(): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/npm-scope');
    $this->assertSame(['left-pad-core', 'left-pad-site', 'left-pad-tool'], self::npmNames($this->scoped($root, FALSE)));
    $snapshot = $this->scoped($root, TRUE);
    $this->assertSame(['left-pad-site'], self::npmNames($snapshot));
    $excluded = array_map(static fn ($gap) => $gap->path, array_filter($snapshot->coverage->gaps, static fn ($gap) => $gap->kind === GapKind::Excluded));
    sort($excluded);
    $this->assertSame(['web/core/package-lock.json', 'web/modules/contrib/tool/package-lock.json'], $excluded);
  }

  /**
   * Both PnP markers withhold absence and execute-path suggestions.
   */
  public function testPnpPresence(): void {
    $snapshot = $this->project('npm-pnp');
    $this->assertCount(2, $snapshot->occurrences);
    foreach ($snapshot->occurrences as $occurrence) {
      $this->assertSame(Presence::Unknown, $occurrence->presence);
    }
  }

  /**
   * Malformed graphs make coverage partial rather than complete and empty.
   */
  public function testMalformedGraphCoverage(): void {
    $snapshot = $this->project('npm-malformed');
    $this->assertSame(CollectorStatus::Partial, $this->status);
    $this->assertCount(2, $snapshot->coverage->gaps);
    foreach ($snapshot->coverage->gaps as $gap) {
      $this->assertSame(GapKind::UnreadableInput, $gap->kind);
    }
  }

  /**
   * Lockfiles deeper than the former depth limit are fully inventoried.
   */
  public function testDeepLockfile(): void {
    $snapshot = $this->project('npm-depth');
    $this->assertSame(CollectorStatus::Complete, $this->status);
    $this->assertSame([], $snapshot->coverage->gaps);
    $this->assertSame(['deep-package'], self::npmNames($snapshot));
    $this->assertSame('web/' . str_repeat('nested/', 8) . 'package-lock.json', $snapshot->occurrences[0]->contextSource);
    $this->assertSame('npm:deep-package@1.0.0', $snapshot->occurrences[0]->package);
  }

  /**
   * Child workspace manifests supply scoped dependency roots.
   */
  public function testWorkspaceRoots(): void {
    $snapshot = $this->project('npm-workspaces');
    $this->assertSame(CollectorStatus::Complete, $this->status);
    $scopes = [];
    foreach ($snapshot->occurrences as $occurrence) {
      $scopes[$occurrence->package] = $occurrence->scopes;
    }
    $this->assertSame([
      'npm:prod@1.0.0' => [Scope::Production],
      'npm:tool@1.0.0' => [Scope::Development],
      'npm:extra@1.0.0' => [Scope::Optional],
    ], $scopes);
    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      $edges[$relationship->scopes[0]->value] = $relationship->to;
    }
    $this->assertSame(['npm:tool@1.0.0'], $edges['development']);
    $this->assertSame(['npm:prod@1.0.0', 'npm:extra@1.0.0'], array_column(array_filter(DevelopmentExclusion::apply($snapshot)->packages, static fn ($package) => $package->ecosystem === Ecosystem::Npm), 'id'));
  }

  /**
   * Public, private, Git and file copies keep separate identities.
   */
  public function testDistinctYarnSources(): void {
    $snapshot = $this->project('yarn-sources');
    $packages = array_values(array_filter($snapshot->packages, static fn ($package) => $package->name === 'example'));
    $this->assertCount(4, $packages);
    $this->assertCount(1, array_filter($packages, static fn ($package) => $package->purl !== NULL));
    $this->assertCount(4, array_unique(array_column($packages, 'id')));
    $this->assertCount(4, $snapshot->occurrences);
    $edges = array_values(array_filter($snapshot->relationships, static fn ($edge) => $edge->from === 'application:test'));
    $this->assertCount(3, $edges);
    $this->assertSame(['npm:example@1.0.0'], $edges[0]->to);
    $this->assertNotSame($edges[1]->to, $edges[2]->to);
    $this->assertSame([], $snapshot->integrityErrors());
    $identifiers = implode(' ', array_column($packages, 'id'));
    $this->assertStringNotContainsString('synthetic-secret', $identifiers);
    $this->assertStringNotContainsString('user:', $identifiers);
  }

  /**
   * Collects one regression project and records its status.
   */
  private function project(string $name): Snapshot {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/' . $name);
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    $builder->setRootPackage(new Package('application:test', Ecosystem::Application, 'test', NULL, NULL, LicenseClassifier::composer([], [])));
    $this->status = (new NpmCollector())->collect($builder);
    return $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
  }

  /**
   * Builds a snapshot of a project with or without core and contrib lockfiles.
   */
  private function scoped(string $root, bool $customOnly): Snapshot {
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'), $customOnly);
    $builder->setRootPackage(new Package('application:example/npm-scope', Ecosystem::Application, 'example/npm-scope', NULL, NULL, LicenseClassifier::composer([], [])));
    (new NpmCollector())->collect($builder);
    return $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
  }

  /**
   * Returns the sorted names of the npm packages of a snapshot.
   *
   * @return list<string>
   *   The names.
   */
  private static function npmNames(Snapshot $snapshot): array {
    $names = [];
    foreach ($snapshot->packages as $package) {
      if ($package->ecosystem === Ecosystem::Npm) {
        $names[] = $package->name;
      }
    }
    sort($names);
    return $names;
  }

  /**
   * Returns the occurrences of a package keyed by path.
   *
   * @return array<string, \Sparkfabrik\DrupalSbom\Model\Occurrence>
   *   The occurrences.
   */
  private function occurrencesOf(string $package): array {
    $occurrences = [];
    foreach ($this->snapshot->occurrences as $occurrence) {
      if ($occurrence->package === $package) {
        $occurrences[$occurrence->path] = $occurrence;
      }
    }
    ksort($occurrences);
    return $occurrences;
  }

  /**
   * Returns the dependency targets of an element, keyed by scope.
   *
   * @return array<string, list<string>>
   *   Targets keyed by scope.
   */
  private function edgesFrom(string $from): array {
    $edges = [];
    foreach ($this->snapshot->relationships as $relationship) {
      if ($relationship->from === $from) {
        $edges[$relationship->scopes[0]->value] = $relationship->to;
      }
    }
    return $edges;
  }

  /**
   * Returns the paths of the gaps of a kind.
   *
   * @return list<string>
   *   The paths, sorted.
   */
  private function gapPaths(GapKind $kind): array {
    $paths = [];
    foreach ($this->snapshot->coverage->gaps as $gap) {
      if ($gap->kind === $kind) {
        $paths[] = (string) $gap->path;
      }
    }
    sort($paths);
    return $paths;
  }

}
