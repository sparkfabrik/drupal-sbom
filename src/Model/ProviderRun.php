<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * One advisory provider queried during an analysis run.
 */
final class ProviderRun {

  /**
   * Constructs a ProviderRun object.
   *
   * @param string $id
   *   The provider plugin ID.
   * @param string $endpoint
   *   The endpoint that was queried.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $endpoint,
  ) {}

  /**
   * Creates a provider run from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self($data['id'], $data['endpoint']);
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string>
   *   The array representation.
   */
  public function toArray(): array {
    return ['id' => $this->id, 'endpoint' => $this->endpoint];
  }

}
