<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Typed edges between inventory elements.
 */
enum RelationshipType: string {

  case DependsOn = 'dependsOn';
  case Contains = 'contains';
  case Declares = 'declares';

}
