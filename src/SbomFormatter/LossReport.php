<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\SbomFormatter;

/**
 * Snapshot facts a formatter could not carry into its output.
 */
final class LossReport {

  /**
   * The lost facts.
   *
   * @var list<array{subject: string|null, fact: string}>
   */
  private array $entries = [];

  /**
   * Records a fact the output does not carry.
   *
   * @param string|null $subject
   *   Identifier of the snapshot element the fact belongs to, if any.
   * @param string $fact
   *   Human-readable description of the lost fact.
   */
  public function add(?string $subject, string $fact): void {
    $this->entries[] = ['subject' => $subject, 'fact' => $fact];
  }

  /**
   * Returns the lost facts.
   *
   * @return list<array{subject: string|null, fact: string}>
   *   The lost facts, in the order they were recorded.
   */
  public function entries(): array {
    return $this->entries;
  }

}
