<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

/**
 * The PHP runtime a snapshot is taken with.
 */
final class PhpRuntime {

  /**
   * Constructs a PhpRuntime object.
   *
   * @param string $version
   *   The PHP version, with any distribution suffix.
   * @param string $sapi
   *   The server API, for example fpm-fcgi or cli.
   * @param array<string, string|null> $extensions
   *   Extension versions keyed by the name PHP reports, NULL when unknown.
   */
  public function __construct(
    public readonly string $version,
    public readonly string $sapi,
    public readonly array $extensions,
  ) {}

  /**
   * Describes the PHP runtime executing this code.
   */
  public static function current(): self {
    $extensions = [];
    foreach (get_loaded_extensions() as $name) {
      $version = phpversion($name);
      $extensions[$name] = $version === FALSE ? NULL : $version;
    }
    return new self(PHP_VERSION, PHP_SAPI, $extensions);
  }

}
