<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * What a snapshot inspected and what it could not cover.
 */
final class Coverage {

  /**
   * Constructs a Coverage object.
   *
   * @param list<\Sparkfabrik\DrupalSbom\Model\CollectorResult> $collectors
   *   One result per collector.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Gap> $gaps
   *   The coverage gaps.
   */
  public function __construct(
    public readonly array $collectors,
    public readonly array $gaps,
  ) {}

  /**
   * Whether every collector completed without gaps.
   */
  public function isComplete(): bool {
    foreach ($this->collectors as $collector) {
      if ($collector->status !== CollectorStatus::Complete) {
        return FALSE;
      }
    }
    return $this->gaps === [];
  }

  /**
   * Creates coverage from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      array_values(array_map(CollectorResult::fromArray(...), $data['collectors'])),
      array_values(array_map(Gap::fromArray(...), $data['gaps'])),
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
      'collectors' => array_map(static fn (CollectorResult $result) => $result->toArray(), $this->collectors),
      'gaps' => array_map(static fn (Gap $gap) => $gap->toArray(), $this->gaps),
    ];
  }

}
