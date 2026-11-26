<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Something the inventory could not cover.
 */
final class Gap {

  /**
   * Constructs a Gap object.
   *
   * @param \Sparkfabrik\DrupalSbom\Model\GapKind $kind
   *   The kind of gap.
   * @param string $detail
   *   Human-readable explanation.
   * @param string|null $subject
   *   Identifier of the affected element, if any.
   * @param string|null $path
   *   Affected file, relative to the application root, if any.
   */
  public function __construct(
    public readonly GapKind $kind,
    public readonly string $detail,
    public readonly ?string $subject = NULL,
    public readonly ?string $path = NULL,
  ) {}

  /**
   * Creates a gap from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(GapKind::from($data['kind']), $data['detail'], $data['subject'] ?? NULL, $data['path'] ?? NULL);
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, string>
   *   The array representation.
   */
  public function toArray(): array {
    return array_filter([
      'kind' => $this->kind->value,
      'subject' => $this->subject,
      'path' => $this->path,
      'detail' => $this->detail,
    ], static fn ($value) => $value !== NULL);
  }

}
