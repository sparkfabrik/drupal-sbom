<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Util;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/**
 * Decodes YAML the way Drupal's YAML component does, without Drupal.
 */
final class Yaml {

  /**
   * Decodes a YAML string.
   *
   * @throws \Sparkfabrik\DrupalSbom\Util\InvalidYamlException
   *   When the string is not valid YAML.
   */
  public static function decode(string $raw): mixed {
    try {
      return SymfonyYaml::parse($raw, SymfonyYaml::PARSE_EXCEPTION_ON_INVALID_TYPE | SymfonyYaml::PARSE_CUSTOM_TAGS);
    }
    catch (ParseException $e) {
      throw new InvalidYamlException($e->getMessage(), $e->getCode(), $e);
    }
  }

}
