<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\Collector\ComposerCollector;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\LicenseClassification;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

// cspell:ignore bbuild
/**
 * Tests the Composer collector against a project fixture.
 */
#[CoversClass(ComposerCollector::class)]
#[Group('drubom')]
final class ComposerCollectorTest extends TestCase {

  /**
   * The collected inventory.
   */
  private InventoryBuilder $builder;

  /**
   * The collector status.
   */
  private CollectorStatus $status;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/composer');
    $this->builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));
    $this->status = (new ComposerCollector())->collect($this->builder);
  }

  /**
   * The project itself is the root package.
   */
  public function testRootPackage(): void {
    $this->assertSame(CollectorStatus::Complete, $this->status);
    $this->assertSame('application:example/site', $this->builder->rootPackageId());
    $root = $this->builder->package('application:example/site');
    $this->assertSame('GPL-2.0-or-later', $root->license->expression);
  }

  /**
   * Installed packages carry their metadata and install location.
   */
  public function testInstalledPackages(): void {
    $core = $this->builder->package('composer:drupal/core');
    $this->assertSame('11.4.9', $core->version);
    $this->assertSame('pkg:composer/drupal/core@11.4.9', $core->purl);
    $this->assertSame('a1b2c3d4', $core->sourceReference);
    $this->assertSame('web/core', $this->occurrence('composer:drupal/core')['path']);
    $this->assertSame(Presence::Installed, $this->occurrence('composer:drupal/core')['presence']);

    $metapackage = $this->occurrence('composer:drupal/core-recommended');
    $this->assertNull($metapackage['path']);
    $this->assertSame(Presence::Installed, $metapackage['presence']);

    $this->assertSame(['https://patches.example.com/webform/1.patch', 'webform-local.patch'], $this->builder->package('composer:drupal/webform')->patched);
    $this->assertSame('pkg:composer/acme/gone@1.0.0%2Bbuild.5', $this->builder->package('composer:acme/gone')->purl);
  }

  /**
   * Credentials, query strings and server paths never reach the inventory.
   */
  public function testSanitization(): void {
    $this->assertSame('https://example.com/site', $this->builder->package('application:example/site')->homepage);
    $exported = json_encode($this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', [])->toArray());
    $this->assertStringNotContainsString('s3cret', $exported);
    $this->assertStringNotContainsString('token', $exported);
    $this->assertStringNotContainsString('/srv/secret', $exported);
  }

  /**
   * A virtual package resolves to every package that provides it.
   */
  public function testVirtualPackageProviders(): void {
    $snapshot = $this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      if ($relationship->from === 'composer:acme/consumer') {
        $edges = $relationship->to;
      }
    }
    $this->assertSame(['composer:acme/impl-a', 'composer:acme/impl-b'], $edges);
    foreach ($snapshot->coverage->gaps as $gap) {
      $this->assertNotSame('composer:acme/consumer', $gap->subject);
    }
  }

  /**
   * Only packages from a public repository get a package URL.
   */
  public function testPackageUrls(): void {
    $this->assertSame('pkg:composer/drupal/webform@6.2.5?repository_url=https://packages.drupal.org/8', $this->builder->package('composer:drupal/webform')->purl);
    $this->assertNull($this->builder->package('composer:acme/consumer')->purl);
  }

  /**
   * Composer 1 installed metadata is reported, not guessed.
   */
  public function testComposerOneMetadata(): void {
    $root = (string) realpath(__DIR__ . '/../../fixtures/projects/composer-v1');
    $builder = new InventoryBuilder(new ProjectRoots($root, $root . '/vendor', $root . '/web'));

    $status = (new ComposerCollector())->collect($builder);

    $this->assertSame(CollectorStatus::Partial, $status);
    $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    $this->assertSame(Presence::Unknown, $snapshot->occurrences[0]->presence);
    $this->assertSame(GapKind::UnsupportedFormat, $snapshot->coverage->gaps[0]->kind);
  }

  /**
   * An input that changes during collection is detected.
   */
  public function testChangedInput(): void {
    $directory = sys_get_temp_dir() . '/drubom-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/composer.json', '{"name":"a/b"}');
    $builder = new InventoryBuilder(new ProjectRoots((string) realpath($directory), $directory . '/vendor', $directory));
    $builder->readJson($directory . '/composer.json');
    $builder->verifyInputs();
    file_put_contents($directory . '/composer.json', '{"name":"a/c"}');

    try {
      $builder->verifyInputs();
      $this->fail('A changed input must be detected.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('composer.json changed', $e->getMessage());
    }
    finally {
      unlink($directory . '/composer.json');
      rmdir($directory);
    }
  }

  /**
   * A missing install path makes presence unknown and records a gap.
   */
  public function testMissingInstallPath(): void {
    $this->assertSame(Presence::Unknown, $this->occurrence('composer:acme/gone')['presence']);
    $this->assertGap(GapKind::UnreadableInput, 'composer:acme/gone');
  }

  /**
   * Locked but not installed packages are declared only, in their scope.
   */
  public function testDeclaredOnlyPackage(): void {
    $phpunit = $this->occurrence('composer:phpunit/phpunit');
    $this->assertSame(Presence::DeclaredOnly, $phpunit['presence']);
    $this->assertSame([Scope::Development], $phpunit['scopes']);
    $this->assertNull($phpunit['path']);
  }

  /**
   * A lock file that disagrees with the installed version is a gap.
   */
  public function testVersionConflict(): void {
    $this->assertSame('6.2.5', $this->builder->package('composer:drupal/webform')->version);
    $this->assertGap(GapKind::VersionConflict, 'composer:drupal/webform');
  }

  /**
   * Composer license arrays are a choice between licenses.
   */
  public function testLicenses(): void {
    $polyfill = $this->builder->package('composer:symfony/polyfill-php84')->license;
    $this->assertSame(LicenseClassification::SpdxExpression, $polyfill->classification);
    $this->assertSame('MIT OR Apache-2.0', $polyfill->expression);
    $this->assertSame(LicenseClassification::NonStandard, $this->builder->package('composer:acme/gone')->license->classification);
  }

  /**
   * Requirements become scoped edges; replaced names resolve to the replacer.
   */
  public function testRelationships(): void {
    $snapshot = $this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    $edges = [];
    foreach ($snapshot->relationships as $relationship) {
      $scopes = array_map(static fn (Scope $scope) => $scope->value, $relationship->scopes);
      $edges[$relationship->from][] = [$scopes, $relationship->to];
    }

    $this->assertSame([
      [['production'], ['composer:drupal/core-recommended', 'composer:drupal/webform']],
      [['development'], ['composer:phpunit/phpunit']],
    ], $edges['application:example/site']);
    $this->assertSame([[[], ['composer:drupal/core']]], $edges['composer:drupal/webform']);
    $this->assertSame([[[], ['composer:symfony/polyfill-php84']]], $edges['composer:drupal/core']);
    $this->assertGap(GapKind::UnresolvedReference, 'composer:drupal/webform');
    $this->assertSame([], $snapshot->integrityErrors());
    $this->assertContainsOnlyInstancesOf(Relationship::class, $snapshot->relationships);
  }

  /**
   * Every metadata file read is recorded with its digest.
   */
  public function testInputs(): void {
    $snapshot = $this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    $this->assertSame(['composer.json', 'composer.lock', 'vendor/composer/installed.json'], array_keys($snapshot->inputs));
    $this->assertSame(hash_file('sha256', __DIR__ . '/../../fixtures/projects/composer/composer.lock'), $snapshot->inputs['composer.lock']);
  }

  /**
   * Returns an occurrence of a package as an array.
   *
   * @return array<string, mixed>
   *   The occurrence properties.
   */
  private function occurrence(string $package): array {
    $snapshot = $this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    foreach ($snapshot->occurrences as $occurrence) {
      if ($occurrence->package === $package) {
        return get_object_vars($occurrence);
      }
    }
    $this->fail(sprintf('No occurrence of %s.', $package));
  }

  /**
   * Asserts that a gap of a kind exists for a subject.
   */
  private function assertGap(GapKind $kind, string $subject): void {
    $snapshot = $this->builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    foreach ($snapshot->coverage->gaps as $gap) {
      if ($gap->kind === $kind && $gap->subject === $subject) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail(sprintf('No %s gap for %s.', $kind->value, $subject));
  }

}
