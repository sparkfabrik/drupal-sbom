<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * A severity rating with its provenance.
 */
final class Severity {

  /**
   * Constructs a Severity object.
   *
   * @param \Sparkfabrik\DrupalSbom\Model\SeveritySystem $system
   *   The scoring system.
   * @param string $source
   *   Who assigned the rating.
   * @param string|null $version
   *   Scoring system version.
   * @param string|null $vector
   *   Vector string, for CVSS.
   * @param float|null $score
   *   Numeric score.
   * @param \Sparkfabrik\DrupalSbom\Model\SeverityLabel|null $label
   *   Qualitative label.
   */
  public function __construct(
    public readonly SeveritySystem $system,
    public readonly string $source,
    public readonly ?string $version = NULL,
    public readonly ?string $vector = NULL,
    public readonly ?float $score = NULL,
    public readonly ?SeverityLabel $label = NULL,
  ) {}

  /**
   * Returns the higher of the supplied label and the CVSS score rating.
   */
  public function qualitative(): SeverityLabel {
    $derived = SeverityLabel::Unknown;
    if ($this->score !== NULL && is_finite($this->score) && $this->score >= 0 && $this->score <= 10) {
      $derived = match ($this->system) {
        SeveritySystem::CvssV2 => match (TRUE) {
          $this->score >= 7 => SeverityLabel::High,
          $this->score >= 4 => SeverityLabel::Medium,
          default => SeverityLabel::Low,
        },
        SeveritySystem::CvssV3, SeveritySystem::CvssV4 => match (TRUE) {
          $this->score >= 9 => SeverityLabel::Critical,
          $this->score >= 7 => SeverityLabel::High,
          $this->score >= 4 => SeverityLabel::Medium,
          $this->score > 0 => SeverityLabel::Low,
          default => SeverityLabel::None,
        },
        default => SeverityLabel::Unknown,
      };
    }
    $rank = array_flip(array_map(static fn (SeverityLabel $label) => $label->value, SeverityLabel::cases()));
    $label = $this->label ?? SeverityLabel::Unknown;
    return $rank[$label->value] < $rank[$derived->value] ? $label : $derived;
  }

  /**
   * Creates a severity from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      SeveritySystem::from($data['system']),
      $data['source'],
      $data['version'] ?? NULL,
      $data['vector'] ?? NULL,
      isset($data['score']) ? (float) $data['score'] : NULL,
      isset($data['label']) ? SeverityLabel::from($data['label']) : NULL,
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string|float>
   *   The array representation.
   */
  public function toArray(): array {
    return array_filter([
      'system' => $this->system->value,
      'version' => $this->version,
      'vector' => $this->vector,
      'score' => $this->score,
      'label' => $this->label?->value,
      'source' => $this->source,
    ], static fn ($value) => $value !== NULL);
  }

}
