<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Model;

use Sparkfabrik\DrupalSbom\Tests\Traits\JsonSchemaAssertionsTrait;
use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Model\AnalysisRun;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the analysis run model against its JSON schema.
 */
#[CoversClass(AnalysisRun::class)]
#[Group('drubom')]
final class AnalysisRunTest extends TestCase {

  use JsonSchemaAssertionsTrait;

  /**
   * The analysis run schema.
   */
  private const SCHEMA = __DIR__ . '/../../../schema/analysis-run.schema.json';

  /**
   * The representative analysis run fixture.
   */
  private const FIXTURE = __DIR__ . '/../../fixtures/model/representative.analysis.json';

  /**
   * The fixture is valid and survives a round trip through the model.
   */
  public function testRepresentativeAnalysisRoundTrip(): void {
    $data = json_decode(file_get_contents(self::FIXTURE), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertMatchesJsonSchema($data, self::SCHEMA);

    $analysis = AnalysisRun::fromArray($data);

    $this->assertEquals($data, $analysis->toArray());
    $this->assertSame('SA-CONTRIB-2026-041', $analysis->findings[0]->advisory->localId());
  }

  /**
   * Unknown packages, unlisted providers and impossible times are reported.
   */
  public function testIntegrityErrors(): void {
    $snapshot = Snapshot::fromArray(json_decode(file_get_contents(__DIR__ . '/../../fixtures/model/representative.snapshot.json'), TRUE, 512, JSON_THROW_ON_ERROR));
    $data = json_decode(file_get_contents(self::FIXTURE), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([], AnalysisRun::fromArray($data)->integrityErrors($snapshot));

    $data['findings'][0]['package'] = 'composer:missing/package';
    $data['outcomes'][0]['provider'] = 'osv';
    $data['finishedAt'] = '2026-10-10T10:00:00Z';
    $data['findings'][] = $data['findings'][0];

    $errors = AnalysisRun::fromArray($data)->integrityErrors($snapshot);

    $this->assertContains('The analysis run references the unknown package "composer:missing/package".', $errors);
    $this->assertContains('The analysis run references the unlisted provider "osv".', $errors);
    $this->assertContains('The analysis run finished before it started.', $errors);
    $this->assertContains('Duplicate finding identifier "finding:webform-sa-contrib-2026-041".', $errors);
  }

  /**
   * Advisory identifiers must carry their source namespace.
   */
  public function testSchemaRejectsUnnamespacedAdvisory(): void {
    $data = json_decode(file_get_contents(self::FIXTURE), TRUE, 512, JSON_THROW_ON_ERROR);
    $data['findings'][0]['advisory']['id'] = 'SA-CONTRIB-2026-041';

    $errors = $this->jsonSchemaErrors($data, self::SCHEMA);

    $this->assertNotEmpty(array_filter($errors, static fn (string $error) => str_starts_with($error, '/findings/0/advisory/id')), implode("\n", $errors));
  }

}
