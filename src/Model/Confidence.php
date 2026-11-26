<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Confidence in a piece of evidence.
 */
enum Confidence: string {

  case High = 'high';
  case Medium = 'medium';

}
