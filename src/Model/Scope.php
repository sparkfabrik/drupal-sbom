<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Dependency scopes; one occurrence or edge can carry several.
 */
enum Scope: string {

  case Production = 'production';
  case Development = 'development';
  case Optional = 'optional';
  case Peer = 'peer';

}
