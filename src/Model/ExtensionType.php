<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Drupal extension types.
 */
enum ExtensionType: string {

  case Module = 'module';
  case Theme = 'theme';
  case Profile = 'profile';

}
