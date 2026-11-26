<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * One physical or declared appearance of a package.
 */
final class Occurrence {

  /**
   * Constructs an Occurrence object.
   *
   * @param string $id
   *   Identifier unique inside the snapshot.
   * @param string $package
   *   Identifier of the package this is an appearance of.
   * @param string|null $path
   *   Install location, or NULL for a declared-only occurrence.
   * @param \Sparkfabrik\DrupalSbom\Model\Presence $presence
   *   Whether the files are present.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Scope> $scopes
   *   The scopes the package is used in at this location.
   * @param \Sparkfabrik\DrupalSbom\Model\ContextKind $contextKind
   *   What kind of metadata the occurrence was found in.
   * @param string $contextSource
   *   The metadata file the occurrence was found in.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   Where the occurrence facts come from.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $package,
    public readonly ?string $path,
    public readonly Presence $presence,
    public readonly array $scopes,
    public readonly ContextKind $contextKind,
    public readonly string $contextSource,
    public readonly array $evidence,
  ) {}

  /**
   * Creates an occurrence from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['id'],
      $data['package'],
      $data['path'],
      Presence::from($data['presence']),
      array_values(array_map(Scope::from(...), $data['scopes'])),
      ContextKind::from($data['context']['kind']),
      $data['context']['source'],
      Evidence::listFromArray($data['evidence']),
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'package' => $this->package,
      'path' => $this->path,
      'presence' => $this->presence->value,
      'scopes' => array_map(static fn (Scope $scope) => $scope->value, $this->scopes),
      'context' => ['kind' => $this->contextKind->value, 'source' => $this->contextSource],
      'evidence' => array_map(static fn (Evidence $evidence) => $evidence->toArray(), $this->evidence),
    ];
  }

}
