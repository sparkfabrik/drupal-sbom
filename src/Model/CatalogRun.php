<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Records one download of a known exploited vulnerability catalog.
 */
final class CatalogRun {

  /**
   * Constructs a CatalogRun.
   *
   * @param string $id
   *   The catalog: cisa_kev or euvd.
   * @param string $endpoint
   *   Where the catalog was downloaded from.
   * @param \DateTimeImmutable $retrievedAt
   *   When it was downloaded.
   * @param string|null $error
   *   Why the download failed; NULL when it succeeded.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $endpoint,
    public readonly \DateTimeImmutable $retrievedAt,
    public readonly ?string $error = NULL,
  ) {}

  /**
   * Creates a catalog run from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self($data['id'], $data['endpoint'], new \DateTimeImmutable($data['retrievedAt']), $data['error'] ?? NULL);
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    return array_filter([
      'id' => $this->id,
      'endpoint' => $this->endpoint,
      'retrievedAt' => $this->retrievedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
      'error' => $this->error,
    ], static fn ($value) => $value !== NULL);
  }

}
