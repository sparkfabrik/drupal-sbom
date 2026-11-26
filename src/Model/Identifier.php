<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Builds the identifiers used inside DruBOM documents.
 */
final class Identifier {

  /**
   * Namespaces a well-known advisory identifier, such as CVE-2024-1.
   *
   * @return string|null
   *   The namespaced identifier, or NULL for an unknown scheme.
   */
  public static function advisory(string $id): ?string {
    return match (TRUE) {
      str_starts_with($id, 'CVE-') => 'cve:' . $id,
      str_starts_with($id, 'GHSA-') => 'ghsa:' . $id,
      str_starts_with($id, 'SA-') => 'drupal:' . $id,
      str_starts_with($id, 'PKSA-') => 'pksa:' . $id,
      str_starts_with($id, 'EUVD-') => 'euvd:' . $id,
      default => NULL,
    };
  }

  /**
   * Builds a local identifier from a prefix and free text.
   *
   * @param string $prefix
   *   The prefix, such as "finding:osv/".
   * @param string $value
   *   Text that may hold characters local identifiers do not allow.
   */
  public static function local(string $prefix, string $value): string {
    return $prefix . preg_replace('/[^A-Za-z0-9._@\/+-]/', '-', $value);
  }

}
