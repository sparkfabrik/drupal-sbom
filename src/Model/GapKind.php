<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Kinds of coverage gaps.
 */
enum GapKind: string {

  case UnsupportedFormat = 'unsupported-format';
  case UnreadableInput = 'unreadable-input';
  case UnknownVersion = 'unknown-version';
  case UnresolvedReference = 'unresolved-reference';
  case LimitExceeded = 'limit-exceeded';
  case VersionConflict = 'version-conflict';
  case Excluded = 'excluded';

}
