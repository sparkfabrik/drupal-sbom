<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

/**
 * Keeps credentials and server paths out of the inventory.
 */
final class Sanitizer {

  /**
   * Removes credentials, query strings and fragments from a URL.
   *
   * A local path is made relative to the application root, or reduced to
   * its file name when it lies outside.
   */
  public static function reference(string $value, ProjectRoots $roots): string {
    // Fragments, query strings and user information never stay.
    $value = (string) preg_replace('/[?#].*$/s', '', $value);
    $parts = parse_url($value);
    if ($parts === FALSE) {
      return basename($value);
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme === 'file') {
      $value = $parts['path'] ?? '';
    }
    elseif (isset($parts['host'])) {
      return ($scheme === '' ? '' : $scheme . ':') . '//' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }
    if (str_starts_with($value, '/')) {
      return $roots->relative($value) ?? basename($value);
    }
    return $value;
  }

}
