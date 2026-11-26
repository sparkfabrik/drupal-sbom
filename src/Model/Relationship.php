<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * A typed edge between inventory elements.
 */
final class Relationship {

  /**
   * Constructs a Relationship object.
   *
   * @param string $from
   *   Identifier of the source element.
   * @param \Sparkfabrik\DrupalSbom\Model\RelationshipType $type
   *   The relationship type.
   * @param list<string> $to
   *   Identifiers of the target elements.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   Where the relationship comes from.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Scope> $scopes
   *   The scopes of a dependency edge.
   */
  public function __construct(
    public readonly string $from,
    public readonly RelationshipType $type,
    public readonly array $to,
    public readonly array $evidence,
    public readonly array $scopes = [],
  ) {}

  /**
   * Creates a relationship from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['from'],
      RelationshipType::from($data['type']),
      array_values($data['to']),
      Evidence::listFromArray($data['evidence']),
      array_values(array_map(Scope::from(...), $data['scopes'] ?? [])),
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    $data = [
      'from' => $this->from,
      'type' => $this->type->value,
      'to' => $this->to,
    ];
    if ($this->scopes !== []) {
      $data['scopes'] = array_map(static fn (Scope $scope) => $scope->value, $this->scopes);
    }
    $data['evidence'] = array_map(static fn (Evidence $evidence) => $evidence->toArray(), $this->evidence);
    return $data;
  }

}
