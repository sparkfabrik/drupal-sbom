<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\Coverage;
use Sparkfabrik\DrupalSbom\Model\Extension;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Occurrence;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\Snapshot;

/**
 * Accumulates collector output into a snapshot.
 */
final class InventoryBuilder {

  /**
   * Largest metadata file a collector reads, in bytes.
   */
  public const MAX_FILE_SIZE = 20 * 1024 * 1024;

  /**
   * Packages keyed by identifier.
   *
   * @var array<string, \Sparkfabrik\DrupalSbom\Model\Package>
   */
  private array $packages = [];

  /**
   * Occurrences keyed by identifier.
   *
   * @var array<string, \Sparkfabrik\DrupalSbom\Model\Occurrence>
   */
  private array $occurrences = [];

  /**
   * Extensions keyed by identifier.
   *
   * @var array<string, \Sparkfabrik\DrupalSbom\Model\Extension>
   */
  private array $extensions = [];

  /**
   * The relationships.
   *
   * @var list<\Sparkfabrik\DrupalSbom\Model\Relationship>
   */
  private array $relationships = [];

  /**
   * The coverage gaps.
   *
   * @var list<\Sparkfabrik\DrupalSbom\Model\Gap>
   */
  private array $gaps = [];

  /**
   * SHA-256 digests of the inputs, keyed by relative path.
   *
   * @var array<string, string>
   */
  private array $inputs = [];

  /**
   * Identifier of the package that represents the project.
   */
  private ?string $rootPackageId = NULL;

  /**
   * Constructs an InventoryBuilder.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\ProjectRoots $roots
   *   The inspected project.
   * @param bool $customLockfilesOnly
   *   Whether npm lockfiles of Drupal core and contributed code are skipped.
   * @param list<string> $privateNpmScopes
   *   Npm scopes whose packages must not be disclosed.
   */
  public function __construct(
    public readonly ProjectRoots $roots,
    public readonly bool $customLockfilesOnly = FALSE,
    public readonly array $privateNpmScopes = [],
  ) {}

  /**
   * Reads and decodes a JSON metadata file, recording its digest.
   *
   * @return array<mixed>|null
   *   The decoded data, or NULL when the file is missing or unusable; a gap
   *   is recorded for an unusable file.
   */
  public function readJson(string $path): ?array {
    $contents = $this->read($path);
    if ($contents === NULL) {
      return NULL;
    }
    try {
      $data = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->addGap(new Gap(GapKind::UnreadableInput, 'Invalid JSON: ' . $e->getMessage(), path: $this->roots->relative($path)));
      return NULL;
    }
    return is_array($data) ? $data : NULL;
  }

  /**
   * Reads a metadata file within the size limit, recording its digest.
   *
   * @return string|null
   *   The contents, or NULL when the file is missing or unusable; a gap is
   *   recorded for an unusable file.
   */
  public function read(string $path): ?string {
    if (!is_file($path)) {
      return NULL;
    }
    $relative = $this->roots->relative($path);
    if ($relative === NULL) {
      $this->addGap(new Gap(GapKind::UnreadableInput, sprintf('%s is outside the application root.', basename($path))));
      return NULL;
    }
    if (filesize($path) > self::MAX_FILE_SIZE) {
      $this->addGap(new Gap(GapKind::LimitExceeded, sprintf('File larger than %d bytes.', self::MAX_FILE_SIZE), path: $relative));
      return NULL;
    }
    $contents = @file_get_contents($path);
    if ($contents === FALSE) {
      $this->addGap(new Gap(GapKind::UnreadableInput, 'The file cannot be read.', path: $relative));
      return NULL;
    }
    $this->inputs[$relative] = hash('sha256', $contents);
    return $contents;
  }

  /**
   * Sets the package that represents the project; the first one wins.
   */
  public function setRootPackage(Package $package): void {
    $this->addPackage($package);
    $this->rootPackageId ??= $package->id;
  }

  /**
   * Returns the identifier of the package that represents the project.
   */
  public function rootPackageId(): ?string {
    return $this->rootPackageId;
  }

  /**
   * Adds a package; an existing package with the same identifier wins.
   */
  public function addPackage(Package $package): void {
    $this->packages[$package->id] ??= $package;
  }

  /**
   * Returns a package by identifier.
   */
  public function package(string $id): ?Package {
    return $this->packages[$id] ?? NULL;
  }

  /**
   * Adds an occurrence.
   */
  public function addOccurrence(Occurrence $occurrence): void {
    $this->occurrences[$occurrence->id] = $occurrence;
  }

  /**
   * Returns the package installed at the deepest directory containing a path.
   *
   * @param string $path
   *   A path relative to the application root.
   */
  public function packageContaining(string $path): ?string {
    $best = NULL;
    $bestLength = -1;
    foreach ($this->occurrences as $occurrence) {
      if ($occurrence->presence !== Presence::Installed || $occurrence->path === NULL) {
        continue;
      }
      $prefix = $occurrence->path . '/';
      if (($path === $occurrence->path || str_starts_with($path . '/', $prefix)) && strlen($prefix) > $bestLength) {
        $best = $occurrence->package;
        $bestLength = strlen($prefix);
      }
    }
    return $best;
  }

  /**
   * Adds an extension.
   */
  public function addExtension(Extension $extension): void {
    $this->extensions[$extension->id] = $extension;
  }

  /**
   * Returns the extensions collected so far.
   *
   * @return list<\Sparkfabrik\DrupalSbom\Model\Extension>
   *   The extensions.
   */
  public function extensions(): array {
    return array_values($this->extensions);
  }

  /**
   * Adds a relationship.
   */
  public function addRelationship(Relationship $relationship): void {
    $this->relationships[] = $relationship;
  }

  /**
   * Adds a coverage gap.
   */
  public function addGap(Gap $gap): void {
    $this->gaps[] = $gap;
  }

  /**
   * Checks that no input changed since it was read.
   *
   * @throws \RuntimeException
   *   When a metadata file changed or disappeared during collection.
   */
  public function verifyInputs(): void {
    foreach ($this->inputs as $path => $sha256) {
      $current = @hash_file('sha256', $this->roots->application . '/' . $path);
      if ($current !== $sha256) {
        throw new \RuntimeException(sprintf('%s changed while the snapshot was taken; take it again.', $path));
      }
    }
  }

  /**
   * Builds the snapshot.
   *
   * @param string $id
   *   The snapshot UUID.
   * @param \DateTimeImmutable $createdAt
   *   When the snapshot was taken.
   * @param string $creatorVersion
   *   The DruBOM version.
   * @param string|null $siteUuid
   *   The site UUID.
   * @param string $siteName
   *   The site name.
   * @param list<\Sparkfabrik\DrupalSbom\Model\CollectorResult> $collectors
   *   One result per collector.
   */
  public function build(string $id, \DateTimeImmutable $createdAt, string $creatorVersion, ?string $siteUuid, string $siteName, array $collectors): Snapshot {
    if ($this->rootPackageId === NULL) {
      throw new \LogicException('No collector set the root package.');
    }
    ksort($this->inputs);
    return new Snapshot(
      $id,
      $createdAt,
      $creatorVersion,
      $siteUuid,
      $siteName,
      $this->roots->relative($this->roots->vendor) ?? 'vendor',
      $this->roots->relative($this->roots->web) ?? '.',
      $this->inputs,
      $this->rootPackageId,
      array_values($this->packages),
      array_values($this->occurrences),
      array_values($this->extensions),
      $this->relationships,
      new Coverage($collectors, $this->gaps),
    );
  }

}
