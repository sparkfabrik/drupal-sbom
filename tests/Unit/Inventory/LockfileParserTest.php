<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use Sparkfabrik\DrupalSbom\Inventory\Lockfile\LockfileGraph;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\NpmRegistry;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\PnpmLockfile;
use Sparkfabrik\DrupalSbom\Inventory\Lockfile\YarnLockfile;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Yarn and pnpm lockfile parsers on edge cases.
 */
#[CoversClass(YarnLockfile::class)]
#[CoversClass(PnpmLockfile::class)]
#[CoversClass(LockfileGraph::class)]
#[CoversClass(NpmRegistry::class)]
#[Group('drubom')]
final class LockfileParserTest extends TestCase {

  /**
   * Credential changes do not change the identity of a private resolution.
   */
  public function testYarnCredentialIndependentIdentity(): void {
    $contents = file_get_contents(__DIR__ . '/../../fixtures/projects/yarn-sources/web/yarn.lock');
    $original = YarnLockfile::parse($contents, []);
    $changed = YarnLockfile::parse(str_replace(
      ['user:synthetic-secret@', 'token=synthetic-secret'],
      ['example:another-secret@', 'token=another-secret'],
      $contents,
    ), []);
    $this->assertCount(4, $original->entries);
    $this->assertSame(array_keys($original->entries), array_keys($changed->entries));
  }

  /**
   * Different Git revisions keep separate private resolution identities.
   */
  public function testYarnRevisionIdentity(): void {
    $lock = "example@first:\n  version \"1.0.0\"\n  resolved \"git+https://git.example.test/example.git#first\"\n";
    $lock .= "example@second:\n  version \"1.0.0\"\n  resolved \"git+https://git.example.test/example.git#second\"\n";
    $graph = YarnLockfile::parse($lock, []);
    $this->assertCount(2, $graph->entries);
    $this->assertCount(0, array_filter($graph->entries, static fn ($entry) => $entry['registry']));
  }

  /**
   * Malformed collections and records are refused before producing a graph.
   *
   * @param string $manager
   *   The package manager.
   * @param string $contents
   *   The malformed input.
   */
  #[DataProvider('malformedGraphs')]
  public function testMalformedGraphs(string $manager, string $contents): void {
    $this->expectException(\UnexpectedValueException::class);
    $manager === 'yarn' ? YarnLockfile::parse($contents, []) : PnpmLockfile::parse($contents);
  }

  /**
   * Malformed graph structures.
   *
   * @return list<array{string, string}>
   *   The regression cases.
   */
  public static function malformedGraphs(): array {
    return [
      ['yarn', "__metadata: wrong\n"],
      ['yarn', "__metadata: {version: true}\n"],
      ['yarn', "__metadata:\n  version: 8\nfoo: wrong\n"],
      ['yarn', "__metadata:\n  version: 8\nfoo:\n  resolution: foo@npm:1\n  version: 1\n"],
      ['yarn', "__metadata:\n  version: 8\nfoo:\n  resolution: foo@npm:1\n  version: '1'\n  dependencies: wrong\n"],
      ['pnpm', "lockfileVersion: '9.0'\npackages: wrong\nsnapshots: wrong\n"],
      ['pnpm', "lockfileVersion: '9.0'\npackages: null\n"],
      ['pnpm', "lockfileVersion: '9.0'\nimporters: [{}]\n"],
      ['pnpm', "lockfileVersion: '9garbage'\n"],
      ['pnpm', "lockfileVersion: '9.0'\npackages: {foo@1: {resolution: {integrity: fake}}}\n"],
      ['pnpm', "lockfileVersion: '9.0'\nsnapshots:\n  foo@1: wrong\n"],
      ['pnpm', "lockfileVersion: '6.0'\npackages:\n  /foo@1:\n    dependencies: wrong\n"],
      ['pnpm', "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      foo: {specifier: '^1'}\n"],
    ];
  }

  /**
   * Legitimately empty supported lockfiles remain valid.
   */
  public function testEmptyGraphs(): void {
    $this->assertSame([], YarnLockfile::parse("__metadata:\n  version: 8\n", [])->entries);
    foreach (['6.0', '9.0'] as $version) {
      $this->assertSame([], PnpmLockfile::parse("lockfileVersion: '$version'\npackages: {}\nsnapshots: {}\nimporters: {}\n")->entries);
    }
  }

  /**
   * Inheritance stops at the application root and keeps scoped overrides.
   */
  public function testRegistryBoundary(): void {
    $parent = __DIR__ . '/../../fixtures/projects/npm-inherited';
    $root = $parent . '/web';
    // The private parent configuration must not apply above this boundary.
    $registry = NpmRegistry::fromDirectory($root . '/internal', $root);
    $this->assertTrue($registry->isPublic('@acme/tool'));
    // The same file is inherited when it is within the application boundary.
    $registry = NpmRegistry::fromDirectory($root . '/internal', $parent);
    $this->assertFalse($registry->isPublic('@acme/tool'));
    $registry = NpmRegistry::fromDirectory($root . '/public', $parent);
    $this->assertTrue($registry->isPublic('@acme/tool'));
    $this->assertFalse($registry->isPublic('unscoped'));
    $this->assertFalse(NpmRegistry::fromDirectory($parent, $root)->isPublic('@acme/tool'));
  }

  /**
   * Requirements without a locked package are reported, not dropped.
   */
  public function testYarnUnresolved(): void {
    $lock = "# yarn lockfile v1\n\na@^1.0.0:\n  version \"1.0.0\"\n  dependencies:\n    b \"^2.0.0\"\n";
    $graph = YarnLockfile::parse($lock, ['dependencies' => ['a' => '^1.0.0', 'c' => '^3.0.0', 'd' => 'workspace:*']]);

    $this->assertCount(1, $graph->roots['dependencies']);
    $this->assertFalse($graph->entries[$graph->roots['dependencies'][0]]['registry']);
    $this->assertSame(['b@^2.0.0', 'c@^3.0.0'], $graph->unresolved);
  }

  /**
   * A Berry patch of a registry package keeps its registry identity.
   */
  public function testBerryPatch(): void {
    $lock = <<<'YAML'
      __metadata:
        version: 8

      "resolve@npm:^1.22.0":
        version: 1.22.8
        resolution: "resolve@npm:1.22.8"
        linkType: hard

      "resolve@patch:resolve@npm%3A^1.22.0#~builtin<compat/resolve>":
        version: 1.22.8
        resolution: "resolve@patch:resolve@npm%3A1.22.8#~builtin<compat/resolve>::version=1.22.8"
        linkType: hard
      YAML;
    $graph = YarnLockfile::parse($lock, ['dependencies' => ['resolve' => '^1.22.0']]);

    $this->assertSame(['resolve@1.22.8'], array_keys($graph->entries));
    $this->assertTrue($graph->entries['resolve@1.22.8']['registry']);
  }

  /**
   * Only packages of the public registry get a public identity.
   */
  public function testPrivateRegistries(): void {
    $this->assertTrue(NpmRegistry::isPublicTarball('https://registry.npmjs.org/@acme/ui/-/ui-1.0.0.tgz'));
    $this->assertTrue(NpmRegistry::isPublicTarball('https://registry.yarnpkg.com/lodash/-/lodash-4.17.21.tgz#sha1'));
    $this->assertFalse(NpmRegistry::isPublicTarball('https://npm.company.test/@company/internal/-/internal-1.0.0.tgz'));
    $this->assertFalse(NpmRegistry::isPublicTarball('https://registry.npmjs.org.evil.test/a/-/a-1.0.0.tgz'));

    $directory = sys_get_temp_dir() . '/drubom-registry-' . bin2hex(random_bytes(4));
    mkdir($directory);
    try {
      file_put_contents($directory . '/.npmrc', "@acme:registry=https://npm.acme.test/\n//npm.acme.test/:_authToken=\${NPM_TOKEN}\n");
      $npm = NpmRegistry::fromDirectory($directory);
      $this->assertFalse($npm->isPublic('@acme/util'));
      $this->assertTrue($npm->isPublic('@other/util'));
      $this->assertTrue($npm->isPublic('lodash'));
      unlink($directory . '/.npmrc');

      file_put_contents($directory . '/.yarnrc.yml', "npmRegistryServer: \"https://npm.company.test\"\nnpmScopes:\n  babel:\n    npmRegistryServer: \"https://registry.yarnpkg.com\"\n");
      $yarn = NpmRegistry::fromDirectory($directory);
      $this->assertFalse($yarn->isPublic('lodash'));
      $this->assertTrue($yarn->isPublic('@babel/core'));
      $lock = "__metadata:\n  version: 8\n\n\"lodash@npm:^4.17.0\":\n  version: 4.17.21\n  resolution: \"lodash@npm:4.17.21\"\n  linkType: hard\n";
      $this->assertFalse(array_values(YarnLockfile::parse($lock, [], $yarn)->entries)[0]['registry'], 'A Berry package from a private default registry is not public.');
      unlink($directory . '/.yarnrc.yml');
    }
    finally {
      rmdir($directory);
    }
  }

  /**
   * Malformed and unsupported lockfiles are refused with a reason.
   */
  public function testRefusals(): void {
    try {
      YarnLockfile::parse("not a lockfile\n", []);
      $this->fail('A malformed yarn.lock is refused.');
    }
    catch (\UnexpectedValueException $e) {
      $this->assertSame('Unexpected top-level line.', $e->getMessage());
    }
    try {
      PnpmLockfile::parse("lockfileVersion: 5.4\n");
      $this->fail('A pnpm 5 lockfile is refused.');
    }
    catch (\UnexpectedValueException $e) {
      $this->assertSame('pnpm lockfile version 5.4 is not supported; versions 6 and 9 are.', $e->getMessage());
    }
  }

  /**
   * Scoped names keep their leading "@".
   */
  public function testSplit(): void {
    $this->assertSame(['@acme/util', '2.0.0'], LockfileGraph::split('@acme/util@2.0.0'));
    $this->assertSame(['lodash', ''], LockfileGraph::split('lodash'));
  }

}
