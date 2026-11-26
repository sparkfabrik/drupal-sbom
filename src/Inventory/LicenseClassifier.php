<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\License;
use Sparkfabrik\DrupalSbom\Model\LicenseClassification;

/**
 * Classifies declared license values.
 */
final class LicenseClassifier {

  /**
   * Classifies license values declared by Composer metadata.
   *
   * Composer documents a license array as a choice between licenses, so a
   * list of valid expressions becomes their disjunction.
   *
   * @param list<string> $declared
   *   The declared values.
   * @param list<\Sparkfabrik\DrupalSbom\Model\Evidence> $evidence
   *   Where the values come from.
   */
  public static function composer(array $declared, array $evidence): License {
    $declared = array_values(array_filter(array_map('trim', $declared), static fn (string $value) => $value !== ''));
    if ($declared === []) {
      return new License([], LicenseClassification::Missing, NULL, $evidence);
    }
    foreach ($declared as $value) {
      if (!License::isValidExpression($value)) {
        return new License($declared, LicenseClassification::NonStandard, NULL, $evidence);
      }
    }
    if (count($declared) === 1) {
      return new License($declared, LicenseClassification::SpdxExpression, $declared[0], $evidence);
    }
    $terms = array_map(static fn (string $value) => preg_match('/\s/', $value) ? '(' . $value . ')' : $value, $declared);
    return new License($declared, LicenseClassification::SpdxExpression, implode(' OR ', $terms), $evidence);
  }

}
