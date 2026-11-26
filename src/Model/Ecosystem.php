<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Package ecosystems known to the inventory.
 */
enum Ecosystem: string {

  case Application = 'application';
  case Composer = 'composer';
  case Npm = 'npm';
  case DrupalExtension = 'drupal-extension';
  case DrupalLibrary = 'drupal-library';
  case Php = 'php';

}
