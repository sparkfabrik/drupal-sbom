<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Result of querying one provider for one package.
 */
final class Outcome {

  /**
   * Constructs an Outcome object.
   *
   * @param string $package
   *   Identifier of the queried package.
   * @param string $provider
   *   The provider plugin ID.
   * @param \Sparkfabrik\DrupalSbom\Model\OutcomeStatus $status
   *   The query result.
   * @param string|null $detail
   *   Human-readable detail, for example an error message.
   */
  public function __construct(
    public readonly string $package,
    public readonly string $provider,
    public readonly OutcomeStatus $status,
    public readonly ?string $detail = NULL,
  ) {}

  /**
   * Creates an outcome from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self($data['package'], $data['provider'], OutcomeStatus::from($data['status']), $data['detail'] ?? NULL);
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string>
   *   The array representation.
   */
  public function toArray(): array {
    return array_filter([
      'package' => $this->package,
      'provider' => $this->provider,
      'status' => $this->status->value,
      'detail' => $this->detail,
    ], static fn ($value) => $value !== NULL);
  }

}
