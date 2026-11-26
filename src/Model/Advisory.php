<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * A security advisory as published by its source.
 */
final class Advisory {

  /**
   * Constructs an Advisory object.
   *
   * @param string $id
   *   Namespaced identifier, for example drupal:SA-CONTRIB-2026-001.
   * @param list<string> $aliases
   *   Namespaced identifiers the source states as aliases.
   * @param string $title
   *   The advisory title.
   * @param string|null $url
   *   Advisory page URL.
   * @param \DateTimeImmutable|null $publishedAt
   *   Publication time.
   * @param \DateTimeImmutable|null $modifiedAt
   *   Last modification time.
   * @param \DateTimeImmutable|null $withdrawnAt
   *   Withdrawal time.
   */
  public function __construct(
    public readonly string $id,
    public readonly array $aliases,
    public readonly string $title,
    public readonly ?string $url,
    public readonly ?\DateTimeImmutable $publishedAt = NULL,
    public readonly ?\DateTimeImmutable $modifiedAt = NULL,
    public readonly ?\DateTimeImmutable $withdrawnAt = NULL,
  ) {}

  /**
   * The identifier without its namespace.
   */
  public function localId(): string {
    return substr($this->id, strpos($this->id, ':') + 1);
  }

  /**
   * Creates an advisory from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    $date = static fn (?string $value) => $value === NULL ? NULL : new \DateTimeImmutable($value);
    return new self(
      $data['id'],
      array_values($data['aliases']),
      $data['title'],
      $data['url'],
      $date($data['publishedAt'] ?? NULL),
      $date($data['modifiedAt'] ?? NULL),
      $date($data['withdrawnAt'] ?? NULL),
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    $date = static fn (?\DateTimeImmutable $value) => $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    return array_filter([
      'id' => $this->id,
      'aliases' => $this->aliases,
      'title' => $this->title,
      'url' => $this->url,
      'publishedAt' => $date($this->publishedAt),
      'modifiedAt' => $date($this->modifiedAt),
      'withdrawnAt' => $date($this->withdrawnAt),
    ], static fn ($value, $key) => $value !== NULL || $key === 'url', ARRAY_FILTER_USE_BOTH);
  }

}
