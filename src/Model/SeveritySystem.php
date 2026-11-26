<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Severity scoring systems.
 */
enum SeveritySystem: string {

  case CvssV2 = 'cvss-v2';
  case CvssV3 = 'cvss-v3';
  case CvssV4 = 'cvss-v4';
  case DrupalRisk = 'drupal-risk';
  case Label = 'label';

}
