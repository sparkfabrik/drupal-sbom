<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Immutable result of matching one snapshot against advisory providers.
 */
final class AnalysisRun {

  /**
   * Version of the array representation, see schema/analysis-run.schema.json.
   */
  public const SCHEMA_VERSION = '1';

  /**
   * Constructs an AnalysisRun object.
   *
   * @param string $id
   *   The analysis run UUID.
   * @param string $snapshot
   *   UUID of the analyzed snapshot.
   * @param \DateTimeImmutable $startedAt
   *   When the run started.
   * @param \DateTimeImmutable $finishedAt
   *   When the run finished.
   * @param list<\Sparkfabrik\DrupalSbom\Model\ProviderRun> $providers
   *   The queried providers.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Outcome> $outcomes
   *   One outcome per queried package and provider.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Finding> $findings
   *   The findings.
   * @param list<\Sparkfabrik\DrupalSbom\Model\CatalogRun> $catalogs
   *   The known exploited vulnerability catalogs checked.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $snapshot,
    public readonly \DateTimeImmutable $startedAt,
    public readonly \DateTimeImmutable $finishedAt,
    public readonly array $providers,
    public readonly array $outcomes,
    public readonly array $findings,
    public readonly array $catalogs = [],
  ) {}

  /**
   * Lists inconsistencies within the run and against its snapshot.
   *
   * @return list<string>
   *   One message per problem; empty when the run is consistent.
   */
  public function integrityErrors(Snapshot $snapshot): array {
    $errors = [];
    if ($this->snapshot !== $snapshot->id) {
      $errors[] = sprintf('The analysis run %s belongs to snapshot %s, not %s.', $this->id, $this->snapshot, $snapshot->id);
    }
    if ($this->finishedAt < $this->startedAt) {
      $errors[] = 'The analysis run finished before it started.';
    }
    $providers = [];
    foreach ($this->providers as $provider) {
      if (isset($providers[$provider->id])) {
        $errors[] = sprintf('Provider "%s" is listed twice.', $provider->id);
      }
      $providers[$provider->id] = TRUE;
    }
    $references = [];
    foreach ($this->outcomes as $outcome) {
      $references[] = [$outcome->package, $outcome->provider];
    }
    $findingIds = [];
    foreach ($this->findings as $finding) {
      if (isset($findingIds[$finding->id])) {
        $errors[] = sprintf('Duplicate finding identifier "%s".', $finding->id);
      }
      $findingIds[$finding->id] = TRUE;
      $references[] = [$finding->package, $finding->provider];
      foreach ($finding->exploited as $exploitation) {
        if (!in_array($exploitation->catalog, array_map(static fn (CatalogRun $run) => $run->id, $this->catalogs), TRUE)) {
          $errors[] = sprintf('Finding "%s" cites the unchecked catalog "%s".', $finding->id, $exploitation->catalog);
        }
      }
    }
    foreach ($references as [$package, $provider]) {
      if ($snapshot->package($package) === NULL) {
        $errors[] = sprintf('The analysis run references the unknown package "%s".', $package);
      }
      if (!isset($providers[$provider])) {
        $errors[] = sprintf('The analysis run references the unlisted provider "%s".', $provider);
      }
    }
    return array_values(array_unique($errors));
  }

  /**
   * Creates an analysis run from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    if (($data['schemaVersion'] ?? NULL) !== self::SCHEMA_VERSION) {
      throw new \InvalidArgumentException(sprintf('Unsupported analysis run schema version "%s".', $data['schemaVersion'] ?? ''));
    }
    return new self(
      $data['id'],
      $data['snapshot'],
      new \DateTimeImmutable($data['startedAt']),
      new \DateTimeImmutable($data['finishedAt']),
      array_values(array_map(ProviderRun::fromArray(...), $data['providers'])),
      array_values(array_map(Outcome::fromArray(...), $data['outcomes'])),
      array_values(array_map(Finding::fromArray(...), $data['findings'])),
      array_values(array_map(CatalogRun::fromArray(...), $data['catalogs'] ?? [])),
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    $utc = new \DateTimeZone('UTC');
    return [
      'schemaVersion' => self::SCHEMA_VERSION,
      'id' => $this->id,
      'snapshot' => $this->snapshot,
      'startedAt' => $this->startedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
      'finishedAt' => $this->finishedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
      'providers' => array_map(static fn (ProviderRun $provider) => $provider->toArray(), $this->providers),
      'outcomes' => array_map(static fn (Outcome $outcome) => $outcome->toArray(), $this->outcomes),
      'findings' => array_map(static fn (Finding $finding) => $finding->toArray(), $this->findings),
    ] + ($this->catalogs === [] ? [] : ['catalogs' => array_map(static fn (CatalogRun $run) => $run->toArray(), $this->catalogs)]);
  }

}
