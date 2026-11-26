<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Whether an occurrence is physically present.
 */
enum Presence: string {

  case Installed = 'installed';
  case DeclaredOnly = 'declared-only';
  case Unknown = 'unknown';

}
