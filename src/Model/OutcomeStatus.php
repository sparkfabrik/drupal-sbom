<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Result of querying one provider for one package.
 */
enum OutcomeStatus: string {

  case Matched = 'matched';
  case Clean = 'clean';
  case NoData = 'no-data';
  case Unsupported = 'unsupported';
  case Error = 'error';

}
