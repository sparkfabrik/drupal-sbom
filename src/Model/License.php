<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

use Composer\Spdx\SpdxLicenses;

/**
 * License information as declared by package metadata.
 */
final class License {

  /**
   * Constructs a License object.
   *
   * @param list<string> $declared
   *   License values exactly as the metadata declares them.
   * @param \Sparkfabrik\DrupalSbom\Model\LicenseClassification $classification
   *   How usable the declared values are.
   * @param string|null $expression
   *   Valid SPDX license expression, only for the spdx-expression class.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   Where the license information comes from.
   */
  public function __construct(
    public readonly array $declared,
    public readonly LicenseClassification $classification,
    public readonly ?string $expression,
    public readonly array $evidence,
  ) {
    if (($classification === LicenseClassification::SpdxExpression) !== ($expression !== NULL)) {
      throw new \InvalidArgumentException('A license expression is required for, and only for, the spdx-expression classification.');
    }
    if ($expression !== NULL && !self::isValidExpression($expression)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a valid SPDX license expression.', $expression));
    }
  }

  /**
   * Whether a value is a valid SPDX license expression.
   *
   * Identifiers are checked against the SPDX license list. NONE and
   * NOASSERTION are not license expressions in SPDX 3.
   */
  public static function isValidExpression(string $value): bool {
    static $licenses;
    if (preg_match('/(^|[\s()])(NONE|NOASSERTION)($|[\s()])/i', $value)) {
      return FALSE;
    }
    $licenses ??= new SpdxLicenses();
    return $licenses->validate($value);
  }

  /**
   * Creates a license from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      array_values($data['declared']),
      LicenseClassification::from($data['classification']),
      $data['expression'],
      Evidence::listFromArray($data['evidence']),
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
      'declared' => $this->declared,
      'classification' => $this->classification->value,
      'expression' => $this->expression,
      'evidence' => array_map(static fn (Evidence $evidence) => $evidence->toArray(), $this->evidence),
    ];
  }

}
