<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Immutable, format-agnostic inventory of a Drupal codebase.
 */
final class Snapshot {

  /**
   * Version of the array representation, see schema/snapshot.schema.json.
   */
  public const SCHEMA_VERSION = '1';

  /**
   * Packages keyed by identifier; the first one wins, as in a linear search.
   *
   * @var array<string, \Sparkfabrik\DrupalSbom\Model\Package>
   */
  private readonly array $index;

  /**
   * Constructs a Snapshot object.
   *
   * @param string $id
   *   The snapshot UUID.
   * @param \DateTimeImmutable $createdAt
   *   When the snapshot was taken.
   * @param string $creatorVersion
   *   The DruBOM version that took the snapshot.
   * @param string|null $siteUuid
   *   The site UUID; NULL for a scan outside the site.
   * @param string $siteName
   *   The site name, or the project name for a scan outside the site.
   * @param string $vendorRoot
   *   Composer vendor directory, relative to the application root.
   * @param string $webRoot
   *   Drupal web root, relative to the application root.
   * @param array<int|string, string> $inputs
   *   SHA-256 digests of every metadata file read, keyed by relative path.
   * @param string $rootPackage
   *   Identifier of the package that represents the site itself.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Package> $packages
   *   The packages.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Occurrence> $occurrences
   *   The package occurrences.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Extension> $extensions
   *   The Drupal extensions.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Relationship> $relationships
   *   The relationships between elements.
   * @param \Sparkfabrik\DrupalSbom\Model\Coverage $coverage
   *   What was inspected and what could not be covered.
   * @param string|null $selection
   *   The filter applied to a stored snapshot, for example "installed".
   */
  public function __construct(
    public readonly string $id,
    public readonly \DateTimeImmutable $createdAt,
    public readonly string $creatorVersion,
    public readonly ?string $siteUuid,
    public readonly string $siteName,
    public readonly string $vendorRoot,
    public readonly string $webRoot,
    public readonly array $inputs,
    public readonly string $rootPackage,
    public readonly array $packages,
    public readonly array $occurrences,
    public readonly array $extensions,
    public readonly array $relationships,
    public readonly Coverage $coverage,
    public readonly ?string $selection = NULL,
  ) {
    $index = [];
    foreach ($packages as $package) {
      $index[$package->id] ??= $package;
    }
    $this->index = $index;
  }

  /**
   * Returns a package by identifier.
   */
  public function package(string $id): ?Package {
    return $this->index[$id] ?? NULL;
  }

  /**
   * Lists integrity problems: duplicate identifiers and dangling references.
   *
   * @return list<string>
   *   One message per problem; empty when the snapshot is consistent.
   */
  public function integrityErrors(): array {
    $errors = [];
    $ids = [];
    $elements = [...$this->packages, ...$this->occurrences, ...$this->extensions];
    foreach ($elements as $element) {
      if (isset($ids[$element->id])) {
        $errors[] = sprintf('Duplicate identifier "%s".', $element->id);
      }
      $ids[$element->id] = $element;
    }
    $packageIds = array_flip(array_map(static fn (Package $package) => $package->id, $this->packages));
    // Relationships connect packages and extensions, never occurrences.
    $nodeIds = $packageIds + array_flip(array_map(static fn (Extension $extension) => $extension->id, $this->extensions));

    $references = [['root package', $this->rootPackage, $packageIds]];
    foreach ($this->occurrences as $occurrence) {
      $references[] = ['occurrence ' . $occurrence->id, $occurrence->package, $packageIds];
    }
    foreach ($this->extensions as $extension) {
      $references[] = ['extension ' . $extension->id, $extension->package, $packageIds];
    }
    foreach ($this->relationships as $relationship) {
      foreach ([$relationship->from, ...$relationship->to] as $id) {
        $references[] = [$relationship->type->value . ' relationship from ' . $relationship->from, $id, $nodeIds];
      }
    }
    foreach ($this->coverage->gaps as $gap) {
      if ($gap->subject !== NULL) {
        $references[] = ['coverage gap', $gap->subject, $ids];
      }
    }
    foreach ($references as [$owner, $id, $known]) {
      if (!isset($known[$id])) {
        $errors[] = sprintf('The %s references the unknown element "%s".', $owner, $id);
      }
    }
    return $errors;
  }

  /**
   * Creates a snapshot from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    if (($data['schemaVersion'] ?? NULL) !== self::SCHEMA_VERSION) {
      throw new \InvalidArgumentException(sprintf('Unsupported snapshot schema version "%s".', $data['schemaVersion'] ?? ''));
    }
    $inputs = [];
    foreach ($data['inputs'] as $input) {
      if (isset($inputs[$input['path']])) {
        throw new \InvalidArgumentException(sprintf('The input %s is listed twice.', $input['path']));
      }
      $inputs[$input['path']] = $input['sha256'];
    }
    return new self(
      $data['id'],
      new \DateTimeImmutable($data['createdAt']),
      $data['creator']['version'],
      $data['site']['uuid'] ?? NULL,
      $data['site']['name'],
      $data['roots']['vendor'],
      $data['roots']['web'],
      $inputs,
      $data['rootPackage'],
      array_values(array_map(Package::fromArray(...), $data['packages'])),
      array_values(array_map(Occurrence::fromArray(...), $data['occurrences'])),
      array_values(array_map(Extension::fromArray(...), $data['extensions'])),
      array_values(array_map(Relationship::fromArray(...), $data['relationships'])),
      Coverage::fromArray($data['coverage']),
      $data['selection'] ?? NULL,
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    $inputs = [];
    foreach ($this->inputs as $path => $sha256) {
      $inputs[] = ['path' => (string) $path, 'sha256' => $sha256];
    }
    return [
      'schemaVersion' => self::SCHEMA_VERSION,
      'id' => $this->id,
      'createdAt' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
      'creator' => ['tool' => 'drubom', 'version' => $this->creatorVersion],
      'site' => ($this->siteUuid === NULL ? [] : ['uuid' => $this->siteUuid]) + ['name' => $this->siteName],
      'roots' => ['vendor' => $this->vendorRoot, 'web' => $this->webRoot],
      'inputs' => $inputs,
      'rootPackage' => $this->rootPackage,
      'packages' => array_map(static fn (Package $package) => $package->toArray(), $this->packages),
      'occurrences' => array_map(static fn (Occurrence $occurrence) => $occurrence->toArray(), $this->occurrences),
      'extensions' => array_map(static fn (Extension $extension) => $extension->toArray(), $this->extensions),
      'relationships' => array_map(static fn (Relationship $relationship) => $relationship->toArray(), $this->relationships),
      'coverage' => $this->coverage->toArray(),
    ] + ($this->selection === NULL ? [] : ['selection' => $this->selection]);
  }

}
