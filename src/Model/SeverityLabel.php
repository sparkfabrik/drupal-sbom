<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Qualitative severity labels.
 */
enum SeverityLabel: string {

  case Critical = 'critical';
  case High = 'high';
  case Medium = 'medium';
  case Low = 'low';
  case None = 'none';
  case Unknown = 'unknown';

}
