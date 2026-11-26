<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Scan\ScanCommand;
use Sparkfabrik\DrupalSbom\Inventory\InstalledSelection;
use Sparkfabrik\DrupalSbom\Inventory\CollectorRunner;
use Sparkfabrik\DrupalSbom\Model\Advisory;
use Sparkfabrik\DrupalSbom\Model\AnalysisRun;
use Sparkfabrik\DrupalSbom\Model\Finding;
use Sparkfabrik\DrupalSbom\Model\Outcome;
use Sparkfabrik\DrupalSbom\Model\OutcomeStatus;
use Sparkfabrik\DrupalSbom\Model\ProviderRun;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use Sparkfabrik\DrupalSbom\Model\VexAssertion;
use Sparkfabrik\DrupalSbom\Model\VexSelection;
use Sparkfabrik\DrupalSbom\SbomFormatter\Spdx3Formatter;
use Sparkfabrik\DrupalSbom\Tests\Traits\JsonSchemaAssertionsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the SPDX 3.0.1 formatter.
 */
#[CoversClass(Spdx3Formatter::class)]
#[Group('drubom')]
#[RunTestsInSeparateProcesses]
final class Spdx3FormatterTest extends TestCase {

  use JsonSchemaAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  /**
   * Expected documents, validated against the SPDX schema and SHACL in CI.
   *
   * Set DRUBOM_UPDATE_SPDX_FIXTURES=1 to rewrite them after a deliberate
   * mapping change, then review the diff.
   */
  private const EXPECTED = __DIR__ . '/../fixtures/spdx/';

  /**
   * Directory of the model fixtures.
   */
  private const FIXTURES = __DIR__ . '/../fixtures/model/';

  /**
   * A snapshot with findings becomes a valid, self-contained document.
   */
  public function testDocumentWithFindings(): void {
    [$document, $loss] = $this->format($this->snapshot(), $this->analysis());
    $this->assertExpectedDocument('representative-with-findings.spdx.json', $document);

    $this->assertValidDocument($document);
    $graph = $this->graph($document);
    $spdxDocument = $this->elementsOfType($graph, 'SpdxDocument')[0];
    $this->assertSame(['core', 'software', 'simpleLicensing', 'security'], $spdxDocument['profileConformance']);
    $this->assertStringContainsString('Coverage gap (unsupported-format): Yarn lockfiles are not parsed yet.', $spdxDocument['comment']);

    $vulnerability = $this->elementsOfType($graph, 'security_Vulnerability')[0];
    $this->assertSame('SA-CONTRIB-2026-041', $vulnerability['name']);
    $this->assertSame(
      ['securityOther' => 'SA-CONTRIB-2026-041', 'cve' => 'CVE-2026-41234'],
      array_column($vulnerability['externalIdentifier'], 'identifier', 'externalIdentifierType'),
    );
    $assessment = $this->elementsOfType($graph, 'security_CvssV3VulnAssessmentRelationship')[0];
    $this->assertSame('5.4', $assessment['security_score']);
    $this->assertSame('medium', $assessment['security_severity']);
    $this->assertSame('CVSS:3.1/AV:N/AC:L/PR:L/UI:R/S:C/C:L/I:L/A:N', $assessment['security_vectorString']);
    $this->assertSame($vulnerability['spdxId'], $assessment['from']);
    $derived = $this->elementsOfType($graph, 'security_CvssV4VulnAssessmentRelationship')[0];
    $this->assertSame(['9.3', 'critical'], [$derived['security_score'], $derived['security_severity']]);
    $this->assertSame('drupal/webform', $this->element($graph, $assessment['to'][0])['name']);
    $catalog = $this->elementsOfType($graph, 'security_ExploitCatalogVulnAssessmentRelationship')[0];
    $this->assertSame(['kev', TRUE], [$catalog['security_catalogType'], $catalog['security_exploited']]);
    $associations = $this->relationships($graph, 'hasAssociatedVulnerability');
    $this->assertCount(1, $associations);
    $this->assertSame('drupal/webform', $this->element($graph, $associations[0]['from'])['name']);

    $facts = array_column($loss->entries(), 'fact');
    $this->assertContains('"declares" relationship to drupal-library:webform/webform.element.codemirror has no SPDX equivalent.', $facts);
    $this->assertContains('Severity from drupal.org (drupal-risk) has no SPDX equivalent.', $facts);
    $this->assertContains('Declared license values "Proprietary" (non-standard) are not a valid SPDX expression.', $facts);
    $this->assertContains('Location web/themes/custom/example/node_modules/sass-loader/node_modules/lodash (unknown) is not represented.', $facts);
  }

  /**
   * Decisions become VEX assessments of the site, issued by the organization.
   */
  public function testDocumentWithDecisions(): void {
    $data = json_decode(file_get_contents(self::FIXTURES . 'representative.analysis.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $template = $data['findings'][0];
    $data['findings'] = [];
    $assertions = [];
    $decided = new \DateTimeImmutable('2026-10-01T12:00:00Z');
    foreach (['not_affected', 'affected', 'fixed', 'under_investigation'] as $index => $status) {
      $finding = $template;
      $finding['id'] .= '-' . $status;
      $finding['advisory']['id'] = 'drupal:SA-CONTRIB-2026-04' . $index;
      $finding['advisory']['aliases'] = [];
      unset($finding['exploited']);
      $data['findings'][] = $finding;
      $assertions[] = new VexAssertion(
        '00000000-0000-4000-8000-00000000000' . $index,
        $index + 1,
        $finding['id'],
        $status,
        $status === 'not_affected' ? 'vulnerableCodeNotInExecutePath' : NULL,
        $status === 'affected' ? NULL : 'Checked the ' . $status . ' case.',
        $status === 'affected' ? 'Update to 6.2.9.' : NULL,
        $decided,
      );
    }
    $analysis = AnalysisRun::fromArray($data);

    [$document] = $this->format($this->snapshot(), $analysis, new VexSelection('Example Agency', 'security@example.com', $assertions));
    $this->assertExpectedDocument('representative-with-decisions.spdx.json', $document);
    $this->assertValidDocument($document);
    $graph = $this->graph($document);
    $issuer = $this->elementsOfType($graph, 'Organization')[0];
    $this->assertSame('Example Agency', $issuer['name']);
    $notAffected = $this->elementsOfType($graph, 'security_VexNotAffectedVulnAssessmentRelationship')[0];
    $this->assertSame('doesNotAffect', $notAffected['relationshipType']);
    $this->assertSame('vulnerableCodeNotInExecutePath', $notAffected['security_justificationType']);
    $this->assertSame('example/site', $this->element($graph, $notAffected['to'][0])['name']);
    $this->assertSame('drupal/webform', $this->element($graph, $notAffected['security_assessedElement'])['name']);
    $this->assertSame('Update to 6.2.9.', $this->elementsOfType($graph, 'security_VexAffectedVulnAssessmentRelationship')[0]['security_actionStatement']);
    $this->assertCount(1, $this->elementsOfType($graph, 'security_VexFixedVulnAssessmentRelationship'));
    $this->assertCount(1, $this->elementsOfType($graph, 'security_VexUnderInvestigationVulnAssessmentRelationship'));

    // Another contact is another organization, with its own assessments.
    $otherGraph = $this->graph($this->format($this->snapshot(), $analysis, new VexSelection('Example Agency', 'psirt@example.com', $assertions))[0]);
    $otherIssuer = $this->elementsOfType($otherGraph, 'Organization')[0];
    $this->assertNotSame($issuer['spdxId'], $otherIssuer['spdxId']);
    $this->assertNotSame($notAffected['spdxId'], $this->elementsOfType($otherGraph, 'security_VexNotAffectedVulnAssessmentRelationship')[0]['spdxId']);
    $this->assertNotSame($this->elementsOfType($graph, 'SpdxDocument')[0]['spdxId'], $this->elementsOfType($otherGraph, 'SpdxDocument')[0]['spdxId']);

    [$withoutIssuer, $loss] = $this->format($this->snapshot(), $analysis, new VexSelection('', NULL, $assertions));
    $this->assertSame([], $this->elementsOfType($this->graph($withoutIssuer), 'security_VexNotAffectedVulnAssessmentRelationship'));
    $this->assertContains('The decision on this finding is not exported: no issuing organization is set.', array_column($loss->entries(), 'fact'));
  }

  /**
   * Packages, licenses and scoped dependencies map to SPDX terms.
   */
  public function testPackageMapping(): void {
    [$document] = $this->format($this->snapshot(), NULL);
    $this->assertExpectedDocument('representative.spdx.json', $document);

    $this->assertValidDocument($document);
    $graph = $this->graph($document);
    $this->assertSame(['core', 'software', 'simpleLicensing'], $this->elementsOfType($graph, 'SpdxDocument')[0]['profileConformance']);
    $this->assertSame([], $this->elementsOfType($graph, 'security_Vulnerability'));

    $core = $this->packageNamed($graph, 'drupal/core');
    $this->assertSame('11.4.9', $core['software_packageVersion']);
    $this->assertSame('pkg:composer/drupal/core@11.4.9', $core['software_packageUrl']);
    $this->assertSame('framework', $core['software_primaryPurpose']);
    $this->assertSame('GPL-2.0-or-later', $this->declaredLicense($graph, $core)['simplelicensing_licenseExpression']);

    $custom = $this->packageNamed($graph, 'example_custom', 'module');
    $this->assertArrayNotHasKey('software_packageVersion', $custom);
    $this->assertSame('https://spdx.org/rdf/3.0.1/terms/ExpandedLicensing/NoAssertionLicense', $this->licenseTarget($graph, $custom));

    $this->assertSame('Declared by the project metadata but not installed.', $this->packageNamed($graph, 'phpunit/phpunit')['comment']);
    $this->assertSame('Drupal module, not enabled.', $this->packageNamed($graph, 'webform_ui')['comment']);
    $this->assertSame('6.2.5', $this->packageNamed($graph, 'webform_ui')['software_packageVersion']);

    $scoped = array_filter($this->relationships($graph, 'dependsOn'), static fn ($relationship) => ($relationship['scope'] ?? NULL) === 'development');
    $this->assertNotEmpty($scoped);
    foreach ($scoped as $relationship) {
      $this->assertSame('LifecycleScopedRelationship', $relationship['type']);
    }
    $rootDependencies = array_filter($this->relationships($graph, 'dependsOn'), fn ($relationship) => $this->element($graph, $relationship['from'])['name'] === 'example/site');
    foreach ($rootDependencies as $relationship) {
      $this->assertSame('incomplete', $relationship['completeness']);
    }
  }

  /**
   * An inconsistent snapshot is refused before anything is written.
   */
  public function testInconsistentSnapshotIsRefused(): void {
    $data = $this->snapshotData();
    $data['relationships'][0]['to'][] = 'composer:missing/package';
    $stream = fopen('php://memory', 'w+');

    try {
      $this->formatter()->format(Snapshot::fromArray($data), NULL, $stream);
      $this->fail('An inconsistent snapshot must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('composer:missing/package', $e->getMessage());
    }
    rewind($stream);
    $this->assertSame('', stream_get_contents($stream));
  }

  /**
   * An analysis run of another snapshot is refused.
   */
  public function testForeignAnalysisIsRefused(): void {
    $data = json_decode(file_get_contents(self::FIXTURES . 'representative.analysis.json'), TRUE);
    $data['snapshot'] = '00000000-0000-4000-8000-000000000000';

    $this->expectException(\InvalidArgumentException::class);
    $this->formatter()->format($this->snapshot(), AnalysisRun::fromArray($data), fopen('php://memory', 'w+'));
  }

  /**
   * An inconsistent analysis run is refused before anything is written.
   */
  public function testInconsistentAnalysisIsRefused(): void {
    $data = json_decode(file_get_contents(self::FIXTURES . 'representative.analysis.json'), TRUE);
    $data['findings'][0]['package'] = 'composer:missing/package';
    $stream = fopen('php://memory', 'w+');

    try {
      $this->formatter()->format($this->snapshot(), AnalysisRun::fromArray($data), $stream);
      $this->fail('An inconsistent analysis run must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('composer:missing/package', $e->getMessage());
    }
    rewind($stream);
    $this->assertSame('', stream_get_contents($stream));
  }

  /**
   * Failed and unsupported lookups stay visible without findings.
   */
  public function testAnalysisWithoutFindingsKeepsOutcomes(): void {
    $data = json_decode(file_get_contents(self::FIXTURES . 'representative.analysis.json'), TRUE);
    $data['findings'] = [];
    $data['outcomes'][1]['status'] = 'unsupported';

    [$document, $loss] = $this->format($this->snapshot(), AnalysisRun::fromArray($data));

    $this->assertValidDocument($document);
    $spdxDocument = $this->elementsOfType($this->graph($document), 'SpdxDocument')[0];
    $this->assertStringContainsString('Outcomes: 1 clean, 1 error, 1 unsupported.', $spdxDocument['comment']);
    $this->assertNotContains('security', $spdxDocument['profileConformance']);
    $facts = array_column($loss->entries(), 'fact');
    $this->assertContains('Advisory lookup by packagist returned "error": Timed out after 10 seconds.', $facts);
    $this->assertContains('Advisory lookup by drupal_org returned "unsupported".', $facts);
  }

  /**
   * Two analyses of one snapshot get distinct identities and creation times.
   */
  public function testAnalysesHaveTheirOwnIdentity(): void {
    $first = $this->analysis();
    $data = $first->toArray();
    $data['id'] = '11111111-2222-4333-8444-555555555555';
    $data['finishedAt'] = '2026-10-11T08:00:00Z';
    $second = AnalysisRun::fromArray($data);

    $firstGraph = $this->graph($this->format($this->snapshot(), $first)[0]);
    $secondGraph = $this->graph($this->format($this->snapshot(), $second)[0]);

    $documentIds = static fn (array $graph) => array_column(array_filter($graph, static fn ($node) => $node['type'] === 'SpdxDocument'), 'spdxId');
    $this->assertNotSame($documentIds($firstGraph), $documentIds($secondGraph));
    $vulnerability = $this->elementsOfType($secondGraph, 'security_Vulnerability')[0];
    $this->assertStringContainsString(':analysis/11111111-2222-4333-8444-555555555555/', $vulnerability['spdxId']);
    $creationInfo = array_values(array_filter($secondGraph, static fn ($node) => ($node['@id'] ?? NULL) === $vulnerability['creationInfo']))[0];
    $this->assertSame('2026-10-11T08:00:00Z', $creationInfo['created']);
    $package = $this->packageNamed($secondGraph, 'drupal/core');
    $this->assertSame($package['spdxId'], $this->packageNamed($firstGraph, 'drupal/core')['spdxId']);
  }

  /**
   * An application version is never copied to the custom theme it ships.
   */
  public function testApplicationVersionIsNotInherited(): void {
    $data = $this->snapshotData();
    $data['packages'][0]['version'] = '2.4.0';

    [$document] = $this->format(Snapshot::fromArray($data), NULL);

    $theme = $this->packageNamed($this->graph($document), 'example', 'module');
    $this->assertArrayNotHasKey('software_packageVersion', $theme);
  }

  /**
   * A selection gets its own document identity; shared elements keep theirs.
   */
  public function testSelectionHasItsOwnIdentity(): void {
    $full = $this->graph($this->format($this->snapshot(), NULL)[0]);
    $selected = $this->graph($this->format(InstalledSelection::apply($this->snapshot()), NULL)[0]);

    $documentId = fn (array $graph) => $this->elementsOfType($graph, 'SpdxDocument')[0]['spdxId'];
    $this->assertNotSame($documentId($full), $documentId($selected));
    $nodes = [];
    foreach ([...$full, ...$selected] as $node) {
      $id = $node['spdxId'] ?? $node['@id'];
      if (isset($nodes[$id]) && !in_array($node['type'], ['SpdxDocument', 'software_Sbom'], TRUE)) {
        $this->assertSame($nodes[$id], $node, sprintf('%s means the same in both documents.', $id));
      }
      $nodes[$id] = $node;
    }
  }

  /**
   * A scan of a real codebase matches the official SPDX schema.
   */
  public function testScannedDocumentMatchesSchema(): void {
    $output = tempnam(sys_get_temp_dir(), 'drupal-sbom');
    $stderr = fopen('php://memory', 'w+');
    $this->assertContains(ScanCommand::run(['--root', (string) realpath(__DIR__ . '/../fixtures/projects/scan'), '--skip', 'php', '--output', $output], STDOUT, $stderr), [ScanCommand::OK, ScanCommand::PARTIAL]);
    $document = (string) file_get_contents($output);
    unlink($output);
    $this->assertValidDocument($document);
    $this->assertSame([], $this->jsonSchemaErrors(json_decode($document, FALSE, 512, JSON_THROW_ON_ERROR), $this->spdxSchemaFile()));
  }

  /**
   * A document with findings for a scanned snapshot matches the schema.
   */
  public function testSnapshotDocumentWithFindingsMatchesSchema(): void {
    $snapshot = $this->snapshot();
    $package = $snapshot->packages[1];
    $analysis = new AnalysisRun(
      '11111111-2222-4333-8444-555555555555',
      $snapshot->id,
      new \DateTimeImmutable(),
      new \DateTimeImmutable(),
      [new ProviderRun('packagist', 'https://packagist.org/api/security-advisories/')],
      [new Outcome($package->id, 'packagist', OutcomeStatus::Matched)],
      [new Finding('finding:packagist/1', $package->id, 'packagist', new Advisory('pksa:PKSA-1', ['cve:CVE-2026-0001'], 'Advisory number 1', NULL), ['<99'], [], [])],
    );
    // One document: validating a full export takes several seconds.
    [$document] = $this->format($snapshot, $analysis);
    $this->assertValidDocument($document);
    $this->assertSame([], $this->jsonSchemaErrors(json_decode($document, FALSE, 512, JSON_THROW_ON_ERROR), $this->spdxSchemaFile()));
  }

  /**
   * A stream that rejects writes fails the export.
   */
  public function testWriteFailureIsReported(): void {
    $this->expectException(\RuntimeException::class);
    $this->formatter()->format($this->snapshot(), NULL, fopen('php://memory', 'r'));
  }

  /**
   * Formats a snapshot and returns the document and loss report.
   *
   * @return array{string, \Sparkfabrik\DrupalSbom\SbomFormatter\LossReport}
   *   The document and the loss report.
   */
  private function format(Snapshot $snapshot, ?AnalysisRun $analysis, ?VexSelection $vex = NULL): array {
    $stream = fopen('php://memory', 'w+');
    $loss = $this->formatter()->format($snapshot, $analysis, $stream, $vex);
    rewind($stream);
    $document = stream_get_contents($stream);
    return [$document, $loss];
  }

  /**
   * Asserts reference closure and collection membership.
   */
  private function assertValidDocument(string $document): void {
    $graph = $this->graph($document);
    $ids = array_column($graph, 'spdxId');
    $this->assertCount(count($ids), array_unique($ids), 'Element identifiers are unique.');
    $known = array_flip([...$ids, 'https://spdx.org/rdf/3.0.1/terms/ExpandedLicensing/NoAssertionLicense']);
    foreach ($graph as $node) {
      $references = (array) ($node['from'] ?? []);
      foreach (['to', 'element', 'rootElement', 'createdBy', 'createdUsing'] as $property) {
        array_push($references, ...($node[$property] ?? []));
      }
      foreach ($references as $reference) {
        $this->assertArrayHasKey($reference, $known, sprintf('%s references an element that is not in the document.', $node['spdxId'] ?? $node['@id']));
      }
    }
    $spdxDocument = $this->elementsOfType($graph, 'SpdxDocument')[0];
    $listed = array_diff($ids, [$spdxDocument['spdxId']]);
    $this->assertEqualsCanonicalizing(array_values($listed), $spdxDocument['element'], 'The document lists every element.');
  }

  /**
   * Asserts that a document matches its expected fixture.
   */
  private function assertExpectedDocument(string $name, string $document): void {
    $decoded = json_decode($document, TRUE, 512, JSON_THROW_ON_ERROR);
    if (getenv('DRUBOM_UPDATE_SPDX_FIXTURES')) {
      file_put_contents(self::EXPECTED . $name, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    $this->assertSame(json_decode(file_get_contents(self::EXPECTED . $name), TRUE, 512, JSON_THROW_ON_ERROR), $decoded);
  }

  /**
   * Returns the formatter.
   */
  private function formatter(): Spdx3Formatter {
    return new Spdx3Formatter();
  }

  /**
   * Returns the decoded representative snapshot fixture.
   *
   * @return array<string, mixed>
   *   The fixture data.
   */
  private function snapshotData(): array {
    return json_decode(file_get_contents(self::FIXTURES . 'representative.snapshot.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the representative snapshot.
   */
  private function snapshot(): Snapshot {
    return Snapshot::fromArray($this->snapshotData());
  }

  /**
   * Returns the representative analysis run.
   */
  private function analysis(): AnalysisRun {
    return AnalysisRun::fromArray(json_decode(file_get_contents(self::FIXTURES . 'representative.analysis.json'), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the graph nodes of a document.
   *
   * @return list<array<string, mixed>>
   *   The nodes.
   */
  private function graph(string $document): array {
    return json_decode($document, TRUE, 512, JSON_THROW_ON_ERROR)['@graph'];
  }

  /**
   * Returns the graph elements of a type.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param string $type
   *   The SPDX type.
   *
   * @return list<array<string, mixed>>
   *   The matching elements.
   */
  private function elementsOfType(array $graph, string $type): array {
    return array_values(array_filter($graph, static fn ($node) => $node['type'] === $type));
  }

  /**
   * Returns the element with an identifier.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param string $id
   *   The element identifier.
   *
   * @return array<string, mixed>
   *   The element.
   */
  private function element(array $graph, string $id): array {
    foreach ($graph as $node) {
      if (($node['spdxId'] ?? NULL) === $id) {
        return $node;
      }
    }
    $this->fail(sprintf('No element %s.', $id));
  }

  /**
   * Returns the package with a name and purpose.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param string $name
   *   The package name.
   * @param string|null $purpose
   *   The primary purpose, to tell a package from an extension.
   *
   * @return array<string, mixed>
   *   The package.
   */
  private function packageNamed(array $graph, string $name, ?string $purpose = NULL): array {
    foreach ($this->elementsOfType($graph, 'software_Package') as $package) {
      if ($package['name'] === $name && ($purpose === NULL || $package['software_primaryPurpose'] === $purpose)) {
        return $package;
      }
    }
    $this->fail(sprintf('No package %s.', $name));
  }

  /**
   * Returns the relationships of a type.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param string $type
   *   The relationship type.
   *
   * @return list<array<string, mixed>>
   *   The relationships.
   */
  private function relationships(array $graph, string $type): array {
    return array_values(array_filter($graph, static fn ($node) => ($node['relationshipType'] ?? NULL) === $type));
  }

  /**
   * Returns the declared license target of a package.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param array<string, mixed> $package
   *   The package.
   */
  private function licenseTarget(array $graph, array $package): string {
    $relationships = array_values(array_filter($this->relationships($graph, 'hasDeclaredLicense'), static fn ($relationship) => $relationship['from'] === $package['spdxId']));
    $this->assertCount(1, $relationships);
    return $relationships[0]['to'][0];
  }

  /**
   * Returns the declared license element of a package.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param array<string, mixed> $package
   *   The package.
   *
   * @return array<string, mixed>
   *   The license element.
   */
  private function declaredLicense(array $graph, array $package): array {
    return $this->element($graph, $this->licenseTarget($graph, $package));
  }

}
