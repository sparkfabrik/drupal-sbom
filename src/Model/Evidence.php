<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Where a fact in the inventory comes from.
 */
final class Evidence {

  /**
   * Constructs an Evidence object.
   *
   * @param string $source
   *   Metadata file, relative to the application root.
   * @param \Sparkfabrik\DrupalSbom\Model\Confidence $confidence
   *   How much the fact can be trusted.
   * @param string|null $field
   *   Field inside the source that holds the fact.
   */
  public function __construct(
    public readonly string $source,
    public readonly Confidence $confidence,
    public readonly ?string $field = NULL,
  ) {}

  /**
   * Creates evidence from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self($data['source'], Confidence::from($data['confidence']), $data['field'] ?? NULL);
  }

  /**
   * Creates a list of evidence from its array representation.
   *
   * @param array<int, array<string, mixed>> $items
   *   The array representation.
   *
   * @return list<self>
   *   The evidence.
   */
  public static function listFromArray(array $items): array {
    return array_values(array_map(self::fromArray(...), $items));
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string>
   *   The array representation.
   */
  public function toArray(): array {
    return array_filter([
      'source' => $this->source,
      'field' => $this->field,
      'confidence' => $this->confidence->value,
    ], static fn ($value) => $value !== NULL);
  }

}
