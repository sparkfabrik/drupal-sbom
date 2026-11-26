<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * A package version that matches an advisory's affected range.
 */
final class Finding {

  /**
   * Constructs a Finding object.
   *
   * @param string $id
   *   Identifier unique inside the analysis run.
   * @param string $package
   *   Identifier of the matching package.
   * @param string $provider
   *   The provider plugin ID that reported the match.
   * @param \Sparkfabrik\DrupalSbom\Model\Advisory $advisory
   *   The matched advisory.
   * @param list<string> $affected
   *   Affected version ranges as the provider states them.
   * @param list<string> $fixed
   *   Versions that fix the issue.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Severity> $severities
   *   Severity ratings with their provenance.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Exploitation> $exploited
   *   Known exploited vulnerability catalog entries that list the advisory.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $package,
    public readonly string $provider,
    public readonly Advisory $advisory,
    public readonly array $affected,
    public readonly array $fixed,
    public readonly array $severities,
    public readonly array $exploited = [],
  ) {}

  /**
   * Creates a finding from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['id'],
      $data['package'],
      $data['provider'],
      Advisory::fromArray($data['advisory']),
      array_values($data['affected']),
      array_values($data['fixed']),
      array_values(array_map(Severity::fromArray(...), $data['severities'])),
      array_values(array_map(Exploitation::fromArray(...), $data['exploited'] ?? [])),
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
      'provider' => $this->provider,
      'advisory' => $this->advisory->toArray(),
      'affected' => $this->affected,
      'fixed' => $this->fixed,
      'severities' => array_map(static fn (Severity $severity) => $severity->toArray(), $this->severities),
    ] + ($this->exploited === [] ? [] : ['exploited' => array_map(static fn (Exploitation $exploitation) => $exploitation->toArray(), $this->exploited)]);
  }

}
