<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Util;

/**
 * Decodes the YAML of Drupal metadata files.
 *
 * A running site can supply Drupal's own decoder, which resolves the same
 * tags and constants Drupal does; outside a site the library uses
 * \Sparkfabrik\DrupalSbom\Util\Yaml, which rejects PHP constants.
 */
interface YamlDecoderInterface {

  /**
   * Decodes a YAML string.
   *
   * @throws \Sparkfabrik\DrupalSbom\Util\InvalidYamlException
   *   When the string cannot be decoded.
   */
  public function decode(string $raw): mixed;

}
