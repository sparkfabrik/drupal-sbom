<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\SbomFormatter;

use Sparkfabrik\DrupalSbom\Model\AnalysisRun;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Extension;
use Sparkfabrik\DrupalSbom\Model\Finding;
use Sparkfabrik\DrupalSbom\Model\LicenseClassification;
use Sparkfabrik\DrupalSbom\Model\OutcomeStatus;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\SeveritySystem;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use Sparkfabrik\DrupalSbom\Model\VexAssertion;
use Sparkfabrik\DrupalSbom\Model\VexSelection;

/**
 * Formats a snapshot as an SPDX 3.0.1 JSON-LD document.
 */
final class Spdx3Formatter implements SbomFormatterInterface {

  /**
   * The SPDX 3.0.1 JSON-LD context.
   */
  private const CONTEXT = 'https://spdx.org/rdf/3.0.1/spdx-context.jsonld';

  /**
   * Predefined individual for unknown licensing.
   */
  private const NO_ASSERTION_LICENSE = 'https://spdx.org/rdf/3.0.1/terms/ExpandedLicensing/NoAssertionLicense';

  /**
   * Creation information of the inventory elements.
   */
  private const CREATION_INFO = '_:creationinfo';

  /**
   * Creation information of the elements that come from an analysis run.
   */
  private const ANALYSIS_CREATION_INFO = '_:analysiscreationinfo';

  /**
   * Lifecycle scopes for the snapshot scopes that have one.
   */
  private const LIFECYCLE_SCOPES = [
    'production' => 'runtime',
    'development' => 'development',
  ];

  /**
   * IRI prefix of the document being written.
   */
  protected string $prefix;

  /**
   * Stream receiving the document.
   *
   * @var resource
   */
  protected $stream;

  /**
   * Identifiers of every element written so far, in order.
   *
   * @var list<string>
   */
  protected array $elements;

  /**
   * Keys of the relationships written so far.
   *
   * @var array<string, true>
   */
  protected array $relationships;

  /**
   * Identifier of the organization issuing the exported decisions, if any.
   */
  protected ?string $issuer;

  /**
   * {@inheritdoc}
   */
  public function format(Snapshot $snapshot, ?AnalysisRun $analysis, $stream, ?VexSelection $vex = NULL): LossReport {
    $errors = $snapshot->integrityErrors();
    if ($errors !== []) {
      throw new \InvalidArgumentException('The snapshot is inconsistent: ' . implode(' ', $errors));
    }
    $errors = $analysis?->integrityErrors($snapshot) ?? [];
    if ($errors !== []) {
      throw new \InvalidArgumentException('The analysis run is inconsistent: ' . implode(' ', $errors));
    }

    $this->prefix = 'urn:drubom:' . $snapshot->id . ':';
    $this->stream = $stream;
    $this->elements = [];
    $this->relationships = [];
    $this->issuer = NULL;
    $loss = new LossReport();

    $this->write('{"@context":' . json_encode(self::CONTEXT, JSON_UNESCAPED_SLASHES) . ',"@graph":[');
    $this->writeNode($this->creationInfo(self::CREATION_INFO, $snapshot->createdAt), FALSE);
    $this->writeElement('agent/drubom', ['type' => 'SoftwareAgent', 'name' => 'DruBOM']);
    $this->writeElement('tool/drubom', ['type' => 'Tool', 'name' => 'DruBOM ' . $snapshot->creatorVersion]);

    $this->writePackages($snapshot, $loss);
    $this->writeExtensions($snapshot);
    $this->writeRelationships($snapshot, $loss);
    // A selection or an analysis changes what the document asserts, so each
    // combination gets its own document and SBOM identity.
    $suffix = $snapshot->selection === NULL ? '' : '/selection/' . $snapshot->selection;
    if ($analysis !== NULL) {
      // Analysis assertions get their own identity and creation time, so two
      // analyses of one snapshot never share element identifiers.
      $suffix .= '/analysis/' . $analysis->id;
      $this->writeNode($this->creationInfo(self::ANALYSIS_CREATION_INFO, $analysis->finishedAt), TRUE);
      if ($vex !== NULL && $vex->assertions !== [] && $vex->issuer === '') {
        foreach ($vex->assertions as $assertion) {
          $loss->add($assertion->finding, 'The decision on this finding is not exported: no issuing organization is set.');
        }
        $vex = NULL;
      }
      if ($vex !== NULL && $vex->assertions !== []) {
        // Decisions are asserted by the issuing organization, not by DruBOM;
        // another name or contact is another organization.
        $this->issuer = $vex->issuerId();
        $this->writeElement('agent/issuer/' . $this->issuer, ['type' => 'Organization', 'name' => $vex->issuer] + ($vex->contact === NULL ? [] : ['comment' => 'Contact: ' . $vex->contact]));
        $suffix .= '/vex/' . $vex->fingerprint();
      }
      $this->writeFindings($analysis, $loss, $vex === NULL || $vex->assertions === [] ? NULL : $vex, $this->packageIri($snapshot->rootPackage));
    }

    $agents = [$this->iri('agent/drubom'), $this->iri('agent/issuer/' . $this->issuer), $this->iri('tool/drubom')];
    $sbomElements = array_values(array_diff($this->elements, $agents));
    $this->writeElement('sbom' . $suffix, [
      'type' => 'software_Sbom',
      'software_sbomType' => $this->sbomTypes($snapshot),
      'rootElement' => [$this->packageIri($snapshot->rootPackage)],
      'element' => $sbomElements,
    ]);
    $profiles = ['core', 'software', 'simpleLicensing'];
    if ($analysis !== NULL && $analysis->findings !== []) {
      $profiles[] = 'security';
    }
    $this->writeElement('document' . $suffix, [
      'type' => 'SpdxDocument',
      'name' => sprintf('%s software bill of materials', $snapshot->siteName),
      'comment' => $this->documentComment($snapshot, $analysis, $vex),
      'profileConformance' => $profiles,
      'rootElement' => [$this->iri('sbom' . $suffix)],
      'element' => $this->elements,
    ], FALSE);
    $this->write(']}');

    return $loss;
  }

  /**
   * Builds a creation information node.
   *
   * @return array<string, mixed>
   *   The node.
   */
  private function creationInfo(string $id, \DateTimeImmutable $created): array {
    return [
      'type' => 'CreationInfo',
      '@id' => $id,
      'specVersion' => '3.0.1',
      'created' => $created->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
      'createdBy' => [$this->iri('agent/drubom')],
      'createdUsing' => [$this->iri('tool/drubom')],
    ];
  }

  /**
   * Writes one SPDX package per snapshot package, with its license.
   */
  private function writePackages(Snapshot $snapshot, LossReport $loss): void {
    $occurrences = [];
    foreach ($snapshot->occurrences as $occurrence) {
      $occurrences[$occurrence->package][] = $occurrence;
      $loss->add($occurrence->id, sprintf('Location %s (%s) is not represented.', $occurrence->path ?? 'none', $occurrence->presence->value));
    }

    $licenses = [];
    foreach ($snapshot->packages as $package) {
      $comments = [];
      $packageOccurrences = $occurrences[$package->id] ?? [];
      if ($packageOccurrences !== [] && array_filter($packageOccurrences, static fn ($occurrence) => $occurrence->presence !== Presence::DeclaredOnly) === []) {
        $comments[] = 'Declared by the project metadata but not installed.';
      }
      if ($package->patched !== []) {
        $comments[] = 'Locally patched: ' . implode(', ', $package->patched) . '.';
      }
      $this->writeElement($this->localKey($package->id), array_filter([
        'type' => 'software_Package',
        'name' => $package->name,
        'comment' => $comments === [] ? NULL : implode(' ', $comments),
        'description' => $package->description,
        'software_packageVersion' => $package->version,
        'software_packageUrl' => $package->purl,
        'externalIdentifier' => $package->ecosystem === Ecosystem::Php && $package->name === 'php' && preg_match('/^\d+\.\d+\.\d+$/', (string) $package->version) ? [
          [
            'type' => 'ExternalIdentifier',
            'externalIdentifierType' => 'cpe23',
            'identifier' => 'cpe:2.3:a:php:php:' . $package->version . ':*:*:*:*:*:*:*',
          ],
        ] : NULL,
        'software_homePage' => $package->homepage,
        'software_sourceInfo' => $package->sourceReference === NULL ? NULL : 'Source reference ' . $package->sourceReference,
        'software_primaryPurpose' => $this->purpose($package),
      ], static fn ($value) => $value !== NULL));

      $license = $package->license;
      if ($license->classification === LicenseClassification::SpdxExpression) {
        $key = 'license/' . hash('sha256', $license->expression);
        if (!isset($licenses[$key])) {
          $licenses[$key] = TRUE;
          $this->writeElement($key, [
            'type' => 'simplelicensing_LicenseExpression',
            'simplelicensing_licenseExpression' => $license->expression,
          ]);
        }
        $target = $this->iri($key);
      }
      else {
        $target = self::NO_ASSERTION_LICENSE;
        if ($license->declared !== []) {
          $loss->add($package->id, sprintf('Declared license values "%s" (%s) are not a valid SPDX expression.', implode('", "', $license->declared), $license->classification->value));
        }
      }
      $this->writeRelationship($this->packageIri($package->id), 'hasDeclaredLicense', [$target]);
    }
  }

  /**
   * Writes one SPDX package per Drupal extension.
   */
  private function writeExtensions(Snapshot $snapshot): void {
    foreach ($snapshot->extensions as $extension) {
      // Only a Composer project or a custom extension's own package shares
      // its release version with the extensions it ships.
      $package = $snapshot->package($extension->package);
      $version = in_array($package?->ecosystem, [Ecosystem::Composer, Ecosystem::DrupalExtension], TRUE) ? $package->version : NULL;
      $this->writeElement($this->localKey($extension->id), array_filter([
        'type' => 'software_Package',
        'name' => $extension->machineName,
        'comment' => $this->extensionComment($extension),
        'software_packageVersion' => $version,
        'software_primaryPurpose' => 'module',
      ], static fn ($value) => $value !== NULL));
    }
  }

  /**
   * Writes the snapshot relationships that SPDX can express.
   */
  private function writeRelationships(Snapshot $snapshot, LossReport $loss): void {
    $complete = $snapshot->coverage->isComplete();
    foreach ($snapshot->relationships as $relationship) {
      $from = $this->packageIri($relationship->from);
      $to = array_map($this->packageIri(...), $relationship->to);
      switch ($relationship->type) {
        case RelationshipType::Contains:
          $this->writeRelationship($from, 'contains', $to);
          break;

        case RelationshipType::DependsOn:
          $extra = $complete || $relationship->from !== $snapshot->rootPackage ? [] : ['completeness' => 'incomplete'];
          $this->writeDependency($relationship, $from, $to, $extra, $loss);
          break;

        case RelationshipType::Declares:
          $loss->add($relationship->from, sprintf('"declares" relationship to %s has no SPDX equivalent.', implode(', ', $relationship->to)));
          break;
      }
    }
  }

  /**
   * Writes a dependency, one lifecycle-scoped relationship per scope.
   *
   * @param \Sparkfabrik\DrupalSbom\Model\Relationship $relationship
   *   The snapshot relationship.
   * @param string $from
   *   IRI of the dependent element.
   * @param list<string> $to
   *   IRIs of the dependencies.
   * @param array<string, string> $extra
   *   Additional relationship properties.
   * @param \Sparkfabrik\DrupalSbom\SbomFormatter\LossReport $loss
   *   The loss report.
   */
  private function writeDependency(Relationship $relationship, string $from, array $to, array $extra, LossReport $loss): void {
    $lifecycle = [];
    foreach ($relationship->scopes as $scope) {
      if (isset(self::LIFECYCLE_SCOPES[$scope->value])) {
        $lifecycle[] = self::LIFECYCLE_SCOPES[$scope->value];
      }
      else {
        $loss->add($relationship->from, sprintf('Dependency scope "%s" has no SPDX lifecycle scope.', $scope->value));
      }
    }
    if ($lifecycle === []) {
      $this->writeRelationship($from, 'dependsOn', $to, $extra);
      return;
    }
    foreach ($lifecycle as $scope) {
      $this->writeRelationship($from, 'dependsOn', $to, $extra + ['scope' => $scope], 'LifecycleScopedRelationship');
    }
  }

  /**
   * Writes one vulnerability per advisory, linked to the matching packages.
   */
  private function writeFindings(AnalysisRun $analysis, LossReport $loss, ?VexSelection $vex, string $product): void {
    $base = 'analysis/' . $analysis->id . '/';
    $decisions = [];
    foreach ($vex === NULL ? [] : $vex->assertions as $assertion) {
      $decisions[$assertion->finding] = $assertion;
    }
    foreach ($analysis->outcomes as $outcome) {
      if (!in_array($outcome->status, [OutcomeStatus::Matched, OutcomeStatus::Clean], TRUE)) {
        $loss->add($outcome->package, sprintf('Advisory lookup by %s returned "%s"%s', $outcome->provider, $outcome->status->value, $outcome->detail === NULL ? '.' : ': ' . $outcome->detail));
      }
    }
    $vulnerabilities = [];
    foreach ($analysis->findings as $finding) {
      $key = $base . 'vulnerability/' . rawurlencode($finding->advisory->id);
      if (!isset($vulnerabilities[$key])) {
        $vulnerabilities[$key] = TRUE;
        $this->writeElement($key, $this->vulnerability($finding), creationInfo: self::ANALYSIS_CREATION_INFO);
      }
      $this->writeRelationship($this->packageIri($finding->package), 'hasAssociatedVulnerability', [$this->iri($key)], keyPrefix: $base, creationInfo: self::ANALYSIS_CREATION_INFO);
      $loss->add($finding->id, sprintf('Affected range %s reported by %s is not exported.', implode(', ', $finding->affected), $finding->provider));
      foreach ($finding->severities as $severity) {
        $class = match ($severity->system) {
          SeveritySystem::CvssV2 => 'security_CvssV2VulnAssessmentRelationship',
          SeveritySystem::CvssV3 => 'security_CvssV3VulnAssessmentRelationship',
          SeveritySystem::CvssV4 => 'security_CvssV4VulnAssessmentRelationship',
          default => NULL,
        };
        if ($class === NULL) {
          $loss->add($finding->id, sprintf('Severity from %s (%s) has no SPDX equivalent.', $severity->source, $severity->system->value));
          continue;
        }
        if ($severity->score === NULL || $severity->vector === NULL) {
          $loss->add($finding->id, $severity->system === SeveritySystem::CvssV4 && $severity->score === NULL
            ? sprintf('CVSS v4 scoring is not implemented; the vector from %s cannot be exported without a score.', $severity->source)
            : sprintf('Severity from %s (%s) lacks the score or vector SPDX requires.', $severity->source, $severity->system->value));
          continue;
        }
        // Scores are decimals; a JSON number would be read as a double.
        $assessment = [
          'security_score' => number_format($severity->score, 1, '.', ''),
          'security_vectorString' => $severity->vector,
        ];
        // CVSS v3 and v4 require a qualitative severity; v2 has none.
        if ($severity->system !== SeveritySystem::CvssV2) {
          $assessment['security_severity'] = $severity->qualitative()->value;
        }
        $this->writeRelationship($this->iri($key), 'hasAssessmentFor', [$this->packageIri($finding->package)], $assessment, $class, $base, self::ANALYSIS_CREATION_INFO);
      }
      foreach ($finding->exploited as $exploitation) {
        [$namespace, $value] = explode(':', $exploitation->identifier, 2);
        $this->writeRelationship($this->iri($key), 'hasAssessmentFor', [$this->packageIri($finding->package)], [
          'security_catalogType' => $exploitation->catalog === 'cisa_kev' ? 'kev' : 'other',
          'security_exploited' => TRUE,
          'security_locator' => match (TRUE) {
            $exploitation->catalog === 'cisa_kev' => 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog',
            $namespace === 'euvd' => 'https://euvd.enisa.europa.eu/vulnerability/' . rawurlencode($value),
            default => 'https://euvd.enisa.europa.eu/',
          },
        ], 'security_ExploitCatalogVulnAssessmentRelationship', $base, self::ANALYSIS_CREATION_INFO);
      }
      if (isset($decisions[$finding->id])) {
        $this->writeDecision($decisions[$finding->id], $this->iri($key), $this->packageIri($finding->package), $product, $base);
      }
      if ($finding->fixed !== []) {
        $loss->add($finding->id, sprintf('Fixed versions %s are not exported yet.', implode(', ', $finding->fixed)));
      }
    }
  }

  /**
   * Writes a recorded decision as a VEX assessment of the site.
   *
   * The site is the product the decision is about; the package is the
   * element that was assessed.
   */
  private function writeDecision(VexAssertion $assertion, string $vulnerability, string $package, string $product, string $base): void {
    $time = $assertion->decidedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $creationInfo = '_:vex-' . $assertion->statement . '-' . $assertion->revision;
    $this->writeNode([
      'type' => 'CreationInfo',
      '@id' => $creationInfo,
      'specVersion' => '3.0.1',
      'created' => $time,
      'createdBy' => [$this->iri('agent/issuer/' . $this->issuer)],
      'createdUsing' => [$this->iri('tool/drubom')],
    ], TRUE);
    $common = ['security_assessedElement' => $package, 'security_vexVersion' => (string) $assertion->revision];
    $optional = static fn (array $values): array => array_filter($values, static fn ($value) => $value !== NULL);
    [$class, $type, $extra] = match ($assertion->status) {
      'not_affected' => [
        'security_VexNotAffectedVulnAssessmentRelationship',
        'doesNotAffect',
        $optional([
          'security_justificationType' => $assertion->justification,
          'security_impactStatement' => $assertion->explanation,
          'security_impactStatementTime' => $assertion->explanation === NULL ? NULL : $time,
        ]),
      ],
      'affected' => [
        'security_VexAffectedVulnAssessmentRelationship',
        'affects',
        ['security_actionStatement' => (string) $assertion->action, 'security_actionStatementTime' => $time],
      ],
      'fixed' => [
        'security_VexFixedVulnAssessmentRelationship',
        'fixedIn',
        $optional(['comment' => $assertion->explanation]),
      ],
      default => [
        'security_VexUnderInvestigationVulnAssessmentRelationship',
        'underInvestigationFor',
        $optional(['comment' => $assertion->explanation]),
      ],
    };
    $this->writeRelationship($vulnerability, $type, [$product], $common + $extra, $class, $base . 'vex/' . $this->issuer . '/', $creationInfo);
  }

  /**
   * Builds the SPDX vulnerability for a finding's advisory.
   *
   * @return array<string, mixed>
   *   The vulnerability element, without identifier and creation info.
   */
  private function vulnerability(Finding $finding): array {
    $advisory = $finding->advisory;
    $identifiers = [];
    foreach ([$advisory->id, ...$advisory->aliases] as $id) {
      [$namespace, $value] = explode(':', $id, 2);
      $identifiers[] = [
        'type' => 'ExternalIdentifier',
        'externalIdentifierType' => $namespace === 'cve' ? 'cve' : 'securityOther',
        'identifier' => $value,
      ];
    }
    $time = static fn (?\DateTimeImmutable $value) => $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    return array_filter([
      'type' => 'security_Vulnerability',
      'name' => $advisory->localId(),
      'summary' => $advisory->title,
      'externalIdentifier' => $identifiers,
      'externalRef' => $advisory->url === NULL ? NULL : [
        ['type' => 'ExternalRef', 'externalRefType' => 'securityAdvisory', 'locator' => [$advisory->url]],
      ],
      'security_publishedTime' => $time($advisory->publishedAt),
      'security_modifiedTime' => $time($advisory->modifiedAt),
      'security_withdrawnTime' => $time($advisory->withdrawnAt),
    ], static fn ($value) => $value !== NULL);
  }

  /**
   * Writes a relationship element.
   *
   * @param string $from
   *   IRI of the source element.
   * @param string $type
   *   The SPDX relationship type.
   * @param list<string> $to
   *   IRIs of the target elements.
   * @param array<string, mixed> $extra
   *   Additional properties.
   * @param string $class
   *   The SPDX relationship class.
   * @param string $keyPrefix
   *   Prefix of the relationship key.
   * @param string $creationInfo
   *   The creation information node.
   */
  private function writeRelationship(string $from, string $type, array $to, array $extra = [], string $class = 'Relationship', string $keyPrefix = '', string $creationInfo = self::CREATION_INFO): void {
    $properties = ['type' => $class, 'from' => $from, 'relationshipType' => $type, 'to' => $to] + $extra;
    // The same assertion keeps the same identifier in every document.
    $key = $keyPrefix . 'relationship/' . substr(hash('sha256', json_encode($properties, JSON_THROW_ON_ERROR)), 0, 20);
    if (isset($this->relationships[$key])) {
      return;
    }
    $this->relationships[$key] = TRUE;
    $this->writeElement($key, $properties, creationInfo: $creationInfo);
  }

  /**
   * Writes an element with its identifier and creation information.
   *
   * @param string $key
   *   Element key, unique in the document.
   * @param array<string, mixed> $properties
   *   The element properties, starting with its type.
   * @param bool $track
   *   Whether the element is listed in the document and SBOM collections.
   * @param string $creationInfo
   *   The creation information node.
   */
  private function writeElement(string $key, array $properties, bool $track = TRUE, string $creationInfo = self::CREATION_INFO): void {
    $iri = $this->iri($key);
    $element = ['type' => $properties['type'], 'spdxId' => $iri, 'creationInfo' => $creationInfo] + $properties;
    $this->writeNode($element, TRUE);
    if ($track) {
      $this->elements[] = $iri;
    }
  }

  /**
   * Writes a node to the graph.
   *
   * @param array<string, mixed> $node
   *   The node.
   * @param bool $separator
   *   Whether a comma precedes the node.
   */
  private function writeNode(array $node, bool $separator): void {
    $this->write(($separator ? ',' : '') . json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * Writes bytes to the stream, failing on a short write.
   *
   * @throws \RuntimeException
   *   When the stream does not accept every byte.
   */
  private function write(string $bytes): void {
    if (fwrite($this->stream, $bytes) !== strlen($bytes)) {
      throw new \RuntimeException('The SBOM document could not be written completely.');
    }
  }

  /**
   * Returns the IRI of a document element.
   */
  private function iri(string $key): string {
    return $this->prefix . $key;
  }

  /**
   * Returns the key of a snapshot element; it always contains "%3A".
   */
  private function localKey(string $id): string {
    return rawurlencode($id);
  }

  /**
   * Returns the IRI of a snapshot package or extension.
   */
  private function packageIri(string $id): string {
    return $this->iri($this->localKey($id));
  }

  /**
   * Returns the SPDX primary purpose of a package.
   */
  private function purpose(Package $package): string {
    return match ($package->ecosystem) {
      Ecosystem::Application => 'application',
      Ecosystem::DrupalExtension => 'module',
      Ecosystem::Composer => $package->name === 'drupal/core' ? 'framework' : 'library',
      Ecosystem::Npm, Ecosystem::DrupalLibrary => 'library',
      Ecosystem::Php => $package->name === 'php' ? 'platform' : 'module',
    };
  }

  /**
   * Returns the SPDX SBOM types of a snapshot.
   *
   * @return list<string>
   *   The types.
   */
  private function sbomTypes(Snapshot $snapshot): array {
    // A scan outside the site describes a build, or only its sources when
    // no Composer installation was there.
    if ($snapshot->siteUuid === NULL) {
      return isset($snapshot->inputs[$snapshot->vendorRoot . '/composer/installed.json']) ? ['build'] : ['source'];
    }
    $declaredOnly = array_filter($snapshot->occurrences, static fn ($occurrence) => $occurrence->presence === Presence::DeclaredOnly);
    return $declaredOnly === [] ? ['deployed'] : ['deployed', 'source'];
  }

  /**
   * Describes a Drupal extension for humans.
   */
  private function extensionComment(Extension $extension): string {
    $state = match ($extension->installed) {
      TRUE => 'enabled',
      FALSE => 'not enabled',
      NULL => 'installed state unknown',
    };
    $comment = sprintf('Drupal %s, %s.', $extension->type->value, $state);
    if ($extension->defaultTheme) {
      $comment .= ' Default theme.';
    }
    if ($extension->adminTheme) {
      $comment .= ' Administration theme.';
    }
    return $comment;
  }

  /**
   * Summarizes the snapshot coverage and the analysis for the document.
   */
  private function documentComment(Snapshot $snapshot, ?AnalysisRun $analysis, ?VexSelection $vex): string {
    $lines = [
      $snapshot->siteUuid === NULL
        ? 'Generated by DruBOM from the codebase files, outside the site: installed state, themes and alters are unknown.'
        : 'Generated by DruBOM from local metadata.',
    ];
    if ($snapshot->selection !== NULL) {
      $lines[] = sprintf('Selection "%s": Drupal extensions that are not enabled and the custom packages and libraries only they bring are left out; Composer and npm packages are kept.', $snapshot->selection);
    }
    foreach ($snapshot->coverage->collectors as $collector) {
      $lines[] = sprintf('Collector %s %s: %s.', $collector->id, $collector->version, $collector->status->value);
    }
    foreach ($snapshot->coverage->gaps as $gap) {
      $lines[] = sprintf('Coverage gap (%s): %s', $gap->kind->value, $gap->detail);
    }
    if ($analysis !== NULL) {
      $counts = array_count_values(array_map(static fn ($outcome) => $outcome->status->value, $analysis->outcomes));
      ksort($counts);
      $outcomes = array_map(static fn ($status, $count) => $count . ' ' . $status, array_keys($counts), $counts);
      $lines[] = sprintf(
        'Advisory analysis %s finished %s using %s. Outcomes: %s.',
        $analysis->id,
        $analysis->finishedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        implode(', ', array_map(static fn ($provider) => $provider->id, $analysis->providers)),
        $outcomes === [] ? 'none' : implode(', ', $outcomes),
      );
    }
    if ($vex !== NULL && $vex->assertions !== []) {
      $lines[] = sprintf('Vulnerability decisions issued by %s: %d, about this site as deployed.', $vex->issuer, count($vex->assertions));
    }
    return implode("\n", $lines);
  }

}
