<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\Coverage;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\Scope;
use Sparkfabrik\DrupalSbom\Model\Snapshot;

/**
 * Leaves packages used only for development out of a snapshot.
 */
final class DevelopmentExclusion {

  /**
   * Returns the snapshot without development-only packages.
   *
   * A package is development-only when it appears in the development scope
   * and in no other. Packages that hold a Drupal extension stay, since the
   * extension list refers to them. The number left out is recorded as a gap.
   */
  public static function apply(Snapshot $snapshot): Snapshot {
    $owners = [$snapshot->rootPackage => TRUE];
    foreach ($snapshot->extensions as $extension) {
      $owners[$extension->package] = TRUE;
    }
    $scopes = [];
    foreach ($snapshot->occurrences as $occurrence) {
      foreach ($occurrence->scopes as $scope) {
        $scopes[$occurrence->package][$scope->value] = TRUE;
      }
    }
    $removed = [];
    foreach ($scopes as $package => $used) {
      if (array_keys($used) === [Scope::Development->value] && !isset($owners[$package])) {
        $removed[$package] = TRUE;
      }
    }
    if ($removed === []) {
      return $snapshot;
    }

    $relationships = [];
    foreach ($snapshot->relationships as $relationship) {
      $to = array_values(array_filter($relationship->to, static fn (string $id) => !isset($removed[$id])));
      if (!isset($removed[$relationship->from]) && $to !== []) {
        $relationships[] = new Relationship($relationship->from, $relationship->type, $to, $relationship->evidence, $relationship->scopes);
      }
    }
    $gaps = array_values(array_filter($snapshot->coverage->gaps, static fn ($gap) => $gap->subject === NULL || !isset($removed[$gap->subject])));
    $gaps[] = new Gap(GapKind::Excluded, sprintf('Packages used only for development are excluded: %d.', count($removed)));

    return new Snapshot(
      $snapshot->id,
      $snapshot->createdAt,
      $snapshot->creatorVersion,
      $snapshot->siteUuid,
      $snapshot->siteName,
      $snapshot->vendorRoot,
      $snapshot->webRoot,
      $snapshot->inputs,
      $snapshot->rootPackage,
      array_values(array_filter($snapshot->packages, static fn ($package) => !isset($removed[$package->id]))),
      array_values(array_filter($snapshot->occurrences, static fn ($occurrence) => !isset($removed[$occurrence->package]))),
      $snapshot->extensions,
      $relationships,
      new Coverage($snapshot->coverage->collectors, $gaps),
      $snapshot->selection,
    );
  }

}
