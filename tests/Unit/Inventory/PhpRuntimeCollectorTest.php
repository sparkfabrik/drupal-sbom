<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Tests\Traits\JsonSchemaAssertionsTrait;
use Sparkfabrik\DrupalSbom\Inventory\Collector\ComposerCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\PhpRuntimeCollector;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\PhpRuntime;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Confidence;
use Sparkfabrik\DrupalSbom\Model\ContextKind;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Evidence;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

// cspell:ignore mysqlnd deb12u1
/**
 * Tests the PHP runtime collector and the platform requirements it resolves.
 */
#[CoversClass(PhpRuntimeCollector::class)]
#[CoversClass(ComposerCollector::class)]
#[Group('drubom')]
final class PhpRuntimeCollectorTest extends TestCase {

  use JsonSchemaAssertionsTrait;

  /**
   * A distribution build of PHP 8.4.26 serving requests through FPM.
   */
  private static function runtime(string $version = '8.4.26-1+deb12u1'): PhpRuntime {
    return new PhpRuntime($version, 'fpm-fcgi', [
      'Core' => $version,
      'Zend OPcache' => $version,
      'mysqlnd' => 'mysqlnd ' . $version,
      'dom' => '20031129',
      'redis' => '6.1.0',
      'unversioned' => NULL,
    ]);
  }

  /**
   * The runtime and every loaded extension become PHP packages.
   */
  public function testRuntimePackages(): void {
    $snapshot = $this->snapshot(self::runtime());

    $php = $snapshot->package('php:php');
    $this->assertSame(Ecosystem::Php, $php->ecosystem);
    $this->assertSame('8.4.26', $php->version);
    $this->assertSame('pkg:generic/php@8.4.26', $php->purl);
    $this->assertSame('PHP 8.4.26-1+deb12u1, fpm-fcgi server API', $php->description);

    // Bundled extensions take the PHP release, others keep their version.
    $this->assertSame('8.4.26', $snapshot->package('php:ext-core')->version);
    $this->assertSame('8.4.26', $snapshot->package('php:ext-zend-opcache')->version);
    $this->assertSame('8.4.26', $snapshot->package('php:ext-mysqlnd')->version);
    $this->assertSame('20031129', $snapshot->package('php:ext-dom')->version);
    $this->assertSame('6.1.0', $snapshot->package('php:ext-redis')->version);
    $this->assertNull($snapshot->package('php:ext-unversioned')->version);

    // Parts of php-src name their directory, whatever version they report.
    $this->assertSame('pkg:generic/php@8.4.26#Zend', $snapshot->package('php:ext-core')->purl);
    $this->assertSame('pkg:generic/php@8.4.26#ext/opcache', $snapshot->package('php:ext-zend-opcache')->purl);
    $this->assertSame('pkg:generic/php@8.4.26#ext/dom', $snapshot->package('php:ext-dom')->purl);
    $this->assertNull($snapshot->package('php:ext-redis')->purl);
    $this->assertNull($snapshot->package('php:ext-unversioned')->purl);

    $sapi = $snapshot->package('php:sapi-fpm-fcgi');
    $this->assertSame('8.4.26', $sapi->version);
    $this->assertSame('pkg:generic/php@8.4.26#sapi/fpm', $sapi->purl);

    $contains = array_values(array_filter($snapshot->relationships, static fn ($relationship) => $relationship->type === RelationshipType::Contains));
    $this->assertCount(1, $contains);
    $this->assertSame('php:php', $contains[0]->from);
    $this->assertSame([
      'php:ext-core',
      'php:ext-dom',
      'php:ext-mysqlnd',
      'php:ext-redis',
      'php:ext-unversioned',
      'php:ext-zend-opcache',
      'php:sapi-fpm-fcgi',
    ], $contains[0]->to);

    foreach ($snapshot->occurrences as $occurrence) {
      if (str_starts_with($occurrence->package, 'php:')) {
        $this->assertSame(Presence::Installed, $occurrence->presence);
        $this->assertSame(ContextKind::PhpRuntime, $occurrence->contextKind);
        $this->assertNull($occurrence->path);
      }
    }
    $this->assertSame([], $snapshot->integrityErrors());
    $this->assertMatchesJsonSchema($snapshot->toArray(), __DIR__ . '/../../../schema/snapshot.schema.json');
  }

  /**
   * Runtime packages and occurrences follow the snapshot schema.
   */
  public function testSchema(): void {
    $builder = $this->builder();
    $builder->setRootPackage(new Package('application:project', Ecosystem::Application, 'project', NULL, NULL, LicenseClassifier::composer([], [new Evidence('composer.json', Confidence::High)])));
    (new PhpRuntimeCollector(self::runtime()))->collect($builder);

    $this->assertMatchesJsonSchema($this->build($builder)->toArray(), __DIR__ . '/../../../schema/snapshot.schema.json');
  }

  /**
   * Prerelease suffixes stay; distribution suffixes go.
   */
  public function testVersions(): void {
    $cases = [
      '8.4.27RC1' => '8.4.27RC1',
      '8.5.0alpha2' => '8.5.0alpha2',
      '8.6.0-dev' => '8.6.0-dev',
      '8.1.2-1ubuntu2.14' => '8.1.2',
      '8.4.26' => '8.4.26',
    ];
    foreach ($cases as $reported => $expected) {
      $builder = $this->builder();
      (new PhpRuntimeCollector(new PhpRuntime($reported, 'cli', ['dom' => '20031129'])))->collect($builder);
      $this->assertSame($expected, $builder->package('php:php')->version, $reported);
      $this->assertSame('pkg:generic/php@' . rawurlencode($expected) . '#ext/dom', $builder->package('php:ext-dom')->purl, $reported);
    }
  }

  /**
   * Extensions moved to PECL are bundled only before they moved.
   */
  public function testUnbundledExtensions(): void {
    $bundled = $this->builder();
    (new PhpRuntimeCollector(new PhpRuntime('8.3.20', 'cli', ['imap' => '8.3.20'])))->collect($bundled);
    $this->assertSame('pkg:generic/php@8.3.20#ext/imap', $bundled->package('php:ext-imap')->purl);

    $pecl = $this->builder();
    (new PhpRuntimeCollector(new PhpRuntime('8.4.20', 'cli', ['imap' => '1.0.3', 'pspell' => '1.0.1'])))->collect($pecl);
    $this->assertNull($pecl->package('php:ext-imap')->purl);
    $this->assertNull($pecl->package('php:ext-pspell')->purl);
  }

  /**
   * A server API outside php-src gets no package URL.
   */
  public function testUnknownServerApi(): void {
    $builder = $this->builder();
    (new PhpRuntimeCollector(new PhpRuntime('8.4.26', 'frankenphp', [])))->collect($builder);

    $this->assertSame('sapi-frankenphp', $builder->package('php:sapi-frankenphp')->name);
    $this->assertNull($builder->package('php:sapi-frankenphp')->purl);
  }

  /**
   * A version without a release number is a gap, not a guess.
   */
  public function testUnknownVersion(): void {
    $builder = $this->builder();
    $status = (new PhpRuntimeCollector(new PhpRuntime('nightly', 'cli', [])))->collect($builder);

    $this->assertSame(CollectorStatus::Partial, $status);
    $this->assertNull($builder->package('php:php')->version);
    $this->assertNull($builder->package('php:php')->purl);
    $gaps = array_map(static fn ($gap) => [$gap->kind, $gap->subject], $this->build($builder)->coverage->gaps);
    $this->assertContains([GapKind::UnknownVersion, 'php:php'], $gaps);
  }

  /**
   * Composer platform requirements link to the runtime and its extensions.
   */
  public function testPlatformRequirements(): void {
    $snapshot = $this->snapshot(self::runtime());

    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      if ($relationship->type === RelationshipType::DependsOn) {
        $edges[$relationship->from] = array_merge($edges[$relationship->from] ?? [], $relationship->to);
      }
    }
    $this->assertContains('php:php', $edges['application:example/site']);
    $this->assertContains('php:php', $edges['composer:drupal/core']);

    // The project requires ext-json, which this runtime does not load.
    $gaps = array_map(static fn ($gap) => [$gap->kind, $gap->subject, $gap->detail], $snapshot->coverage->gaps);
    $this->assertContains([
      GapKind::UnresolvedReference,
      'application:example/site',
      'Required PHP extension ext-json is not loaded in the PHP runtime that took the snapshot, and no package provides it.',
    ], $gaps);
    $this->assertSame([], $snapshot->integrityErrors());
  }

  /**
   * A loaded extension satisfies the requirement without a gap.
   */
  public function testLoadedExtension(): void {
    $snapshot = $this->snapshot(new PhpRuntime('8.4.26', 'cli', ['json' => '8.4.26']));

    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      if ($relationship->from === 'application:example/site' && $relationship->scopes !== [] && $relationship->scopes[0]->value === 'production') {
        $edges = $relationship->to;
      }
    }
    $this->assertContains('php:ext-json', $edges);
    foreach ($snapshot->coverage->gaps as $gap) {
      $this->assertStringNotContainsString('ext-json', $gap->detail);
    }
  }

  /**
   * Without the runtime collector, platform requirements stay out.
   */
  public function testWithoutRuntime(): void {
    $builder = $this->builder();
    (new ComposerCollector())->collect($builder);
    $snapshot = $this->build($builder);

    foreach ($snapshot->relationships as $relationship) {
      $this->assertNotContains('php:php', $relationship->to);
    }
    foreach ($snapshot->coverage->gaps as $gap) {
      $this->assertStringNotContainsString('PHP extension', $gap->detail);
    }
  }

  /**
   * Runs the runtime and Composer collectors in service order.
   */
  private function snapshot(PhpRuntime $runtime): Snapshot {
    $builder = $this->builder();
    (new PhpRuntimeCollector($runtime))->collect($builder);
    (new ComposerCollector())->collect($builder);
    return $this->build($builder);
  }

  /**
   * Returns a builder for the Composer project fixture.
   */
  private function builder(): InventoryBuilder {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/composer');
    return new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
  }

  /**
   * Builds the snapshot, with a root package when no collector set one.
   */
  private function build(InventoryBuilder $builder): Snapshot {
    if ($builder->rootPackageId() === NULL) {
      (new ComposerCollector())->collect($builder);
    }
    return $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
  }

}
