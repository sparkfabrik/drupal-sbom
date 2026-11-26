<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Where an occurrence was found.
 */
enum ContextKind: string {

  case ComposerVendor = 'composer-vendor';
  case ComposerLock = 'composer-lock';
  case NpmLockfile = 'npm-lockfile';
  case DrupalExtension = 'drupal-extension';
  case DrupalLibrary = 'drupal-library';
  case PhpRuntime = 'php-runtime';

}
