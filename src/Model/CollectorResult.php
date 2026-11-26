<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Outcome of one collector during a snapshot.
 */
final class CollectorResult {

  /**
   * Constructs a CollectorResult object.
   *
   * @param string $id
   *   The collector identifier.
   * @param string $version
   *   The collector version.
   * @param \Sparkfabrik\DrupalSbom\Model\CollectorStatus $status
   *   How far the collector got.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $version,
    public readonly CollectorStatus $status,
  ) {}

  /**
   * Creates a collector result from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self($data['id'], $data['version'], CollectorStatus::from($data['status']));
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string>
   *   The array representation.
   */
  public function toArray(): array {
    return ['id' => $this->id, 'version' => $this->version, 'status' => $this->status->value];
  }

}
