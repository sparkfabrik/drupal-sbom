<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Outcome of one collector run.
 */
enum CollectorStatus: string {

  case Complete = 'complete';
  case Partial = 'partial';
  case Failed = 'failed';
  case Skipped = 'skipped';

}
