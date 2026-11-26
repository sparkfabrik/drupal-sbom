<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Model;

use Sparkfabrik\DrupalSbom\Tests\Traits\JsonSchemaAssertionsTrait;
use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Model\License;
use Sparkfabrik\DrupalSbom\Model\LicenseClassification;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the snapshot model against its JSON schema.
 */
#[CoversClass(Snapshot::class)]
#[CoversClass(License::class)]
#[Group('drubom')]
final class SnapshotTest extends TestCase {

  use JsonSchemaAssertionsTrait;

  /**
   * The snapshot schema.
   */
  private const SCHEMA = __DIR__ . '/../../../schema/snapshot.schema.json';

  /**
   * The representative snapshot fixture.
   */
  private const FIXTURE = __DIR__ . '/../../fixtures/model/representative.snapshot.json';

  /**
   * The fixture is valid and survives a round trip through the model.
   */
  public function testRepresentativeSnapshotRoundTrip(): void {
    $data = $this->fixture();
    $this->assertMatchesJsonSchema($data, self::SCHEMA);

    $snapshot = Snapshot::fromArray($data);

    $this->assertSame([], $snapshot->integrityErrors());
    $this->assertEquals($data, $snapshot->toArray());
    $this->assertMatchesJsonSchema($snapshot->toArray(), self::SCHEMA);
  }

  /**
   * Dangling references and duplicate identifiers are reported.
   */
  public function testIntegrityErrors(): void {
    $data = $this->fixture();
    $data['occurrences'][0]['package'] = 'composer:missing/package';
    $data['extensions'][1]['id'] = 'extension:webform';

    $errors = Snapshot::fromArray($data)->integrityErrors();

    $this->assertContains('The occurrence occurrence:core references the unknown element "composer:missing/package".', $errors);
    $this->assertContains('Duplicate identifier "extension:webform".', $errors);
  }

  /**
   * Relationships may connect packages and extensions, not occurrences.
   */
  public function testRelationshipToOccurrenceIsInconsistent(): void {
    $data = $this->fixture();
    $data['relationships'][0]['to'][] = 'occurrence:lodash-root';

    $errors = Snapshot::fromArray($data)->integrityErrors();

    $this->assertContains('The dependsOn relationship from application:example/site references the unknown element "occurrence:lodash-root".', $errors);
  }

  /**
   * An input listed twice is rejected instead of silently collapsed.
   */
  public function testDuplicateInputIsRejected(): void {
    $data = $this->fixture();
    $data['inputs'][] = ['path' => 'composer.lock', 'sha256' => str_repeat('f', 64)];

    $this->expectExceptionMessage('The input composer.lock is listed twice.');
    Snapshot::fromArray($data);
  }

  /**
   * A malformed SPDX expression is rejected at the model boundary.
   */
  public function testMalformedLicenseExpressionIsRejected(): void {
    $this->expectExceptionMessage('"MIT AND" is not a valid SPDX license expression.');
    new License(['MIT AND'], LicenseClassification::SpdxExpression, 'MIT AND', []);
  }

  /**
   * An unknown schema version is rejected.
   */
  public function testUnsupportedSchemaVersion(): void {
    $data = $this->fixture();
    $data['schemaVersion'] = '2';

    $this->expectException(\InvalidArgumentException::class);
    Snapshot::fromArray($data);
  }

  /**
   * A license expression is required for, and only for, SPDX expressions.
   */
  public function testLicenseExpressionInvariant(): void {
    $this->expectException(\InvalidArgumentException::class);
    new License(['MIT'], LicenseClassification::NonStandard, 'MIT', []);
  }

  /**
   * The schema rejects documents that break its contract.
   *
   * @param callable $mutate
   *   Breaks the fixture in place.
   * @param string $pointer
   *   JSON pointer the error must refer to.
   */
  #[DataProvider('invalidSnapshotProvider')]
  public function testSchemaRejectsInvalidSnapshots(callable $mutate, string $pointer): void {
    $data = $this->fixture();
    $mutate($data);

    $errors = $this->jsonSchemaErrors($data, self::SCHEMA);

    $this->assertNotEmpty(array_filter($errors, static fn (string $error) => str_starts_with($error, $pointer)), implode("\n", $errors));
  }

  /**
   * Provides fixture mutations that the schema must reject.
   *
   * @return array<string, array{callable, string}>
   *   Mutation callbacks and the JSON pointer of the expected error.
   */
  public static function invalidSnapshotProvider(): array {
    return [
      'expression without spdx classification' => [
        static function (array &$data): void {
          $data['packages'][5]['licenses']['expression'] = 'MIT';
        },
        '/packages/5/licenses',
      ],
      'spdx classification without expression' => [
        static function (array &$data): void {
          $data['packages'][1]['licenses']['expression'] = NULL;
        },
        '/packages/1/licenses',
      ],
      'absolute path' => [
        static function (array &$data): void {
          $data['occurrences'][0]['path'] = '/var/www/html/web/core';
        },
        '/occurrences/0/path',
      ],
      'parent segment in path' => [
        static function (array &$data): void {
          $data['inputs'][0]['path'] = '../composer.lock';
        },
        '/inputs/0/path',
      ],
      'unknown ecosystem' => [
        static function (array &$data): void {
          $data['packages'][1]['ecosystem'] = 'pypi';
        },
        '/packages/1/ecosystem',
      ],
      'empty scopes' => [
        static function (array &$data): void {
          $data['occurrences'][0]['scopes'] = [];
        },
        '/occurrences/0/scopes',
      ],
      'unexpected member' => [
        static function (array &$data): void {
          $data['packages'][1]['installed'] = TRUE;
        },
        '/packages/1',
      ],
      'theme fields on a module' => [
        static function (array &$data): void {
          $data['extensions'][0]['defaultTheme'] = TRUE;
        },
        '/extensions/0',
      ],
      'missing evidence' => [
        static function (array &$data): void {
          $data['relationships'][0]['evidence'] = [];
        },
        '/relationships/0/evidence',
      ],
    ];
  }

  /**
   * Returns the decoded representative fixture.
   *
   * @return array<string, mixed>
   *   The fixture data.
   */
  private function fixture(): array {
    return json_decode(file_get_contents(self::FIXTURE), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
