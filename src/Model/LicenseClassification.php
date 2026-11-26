<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * How usable a declared license value is.
 */
enum LicenseClassification: string {

  case SpdxExpression = 'spdx-expression';
  case NonStandard = 'non-standard';
  case Missing = 'missing';

}
