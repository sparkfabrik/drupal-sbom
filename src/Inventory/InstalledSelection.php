<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\Coverage;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\ExtensionType;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Snapshot;

/**
 * Narrows a snapshot to the Drupal extensions that were installed.
 *
 * Uninstalled extensions are removed, with the custom packages and Drupal
 * libraries that only they bring. Base themes of kept themes stay. Composer
 * and npm packages always stay: Drupal's installed state says nothing about
 * which PHP or JavaScript code is used.
 */
final class InstalledSelection {

  /**
   * The selection name recorded in the filtered snapshot.
   */
  private const NAME = 'installed';

  /**
   * Returns the filtered snapshot.
   */
  public static function apply(Snapshot $snapshot): Snapshot {
    $removed = self::removed($snapshot, self::kept($snapshot));

    $relationships = [];
    foreach ($snapshot->relationships as $relationship) {
      $to = array_values(array_filter($relationship->to, static fn (string $id) => !isset($removed[$id])));
      if (!isset($removed[$relationship->from]) && $to !== []) {
        $relationships[] = new Relationship($relationship->from, $relationship->type, $to, $relationship->evidence, $relationship->scopes);
      }
    }

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
      array_values(array_filter($snapshot->extensions, static fn ($extension) => !isset($removed[$extension->id]))),
      $relationships,
      new Coverage(
        $snapshot->coverage->collectors,
        array_values(array_filter($snapshot->coverage->gaps, static fn ($gap) => $gap->subject === NULL || !isset($removed[$gap->subject]))),
      ),
      self::NAME,
    );
  }

  /**
   * Returns the installed extensions and the base themes they need.
   *
   * @return array<string, true>
   *   Kept extension identifiers.
   */
  private static function kept(Snapshot $snapshot): array {
    $byName = [];
    foreach ($snapshot->extensions as $extension) {
      $byName[$extension->machineName] = $extension;
    }
    $kept = [];
    $pending = array_filter($snapshot->extensions, static fn ($extension) => $extension->installed);
    while (($extension = array_pop($pending)) !== NULL) {
      $kept[$extension->id] = TRUE;
      // Keep the base theme chain of every kept theme.
      $base = $extension->type === ExtensionType::Theme && $extension->baseTheme !== NULL ? ($byName[$extension->baseTheme] ?? NULL) : NULL;
      if ($base !== NULL && !isset($kept[$base->id])) {
        $pending[] = $base;
      }
    }
    return $kept;
  }

  /**
   * Returns the extensions, custom packages and libraries that go.
   *
   * @param \Sparkfabrik\DrupalSbom\Model\Snapshot $snapshot
   *   The snapshot.
   * @param array<string, true> $kept
   *   Kept extension identifiers.
   *
   * @return array<string, true>
   *   Removed identifiers.
   */
  private static function removed(Snapshot $snapshot, array $kept): array {
    $removed = [];
    $keptOwners = [];
    foreach ($snapshot->extensions as $extension) {
      if (isset($kept[$extension->id])) {
        $keptOwners[$extension->package] = TRUE;
      }
      else {
        $removed[$extension->id] = TRUE;
      }
    }
    // Custom packages go when none of their extensions stays.
    foreach ($snapshot->packages as $package) {
      if ($package->ecosystem === Ecosystem::DrupalExtension && !isset($keptOwners[$package->id])) {
        $removed[$package->id] = TRUE;
      }
    }
    // Libraries go when every element declaring them is gone.
    $declarers = [];
    foreach ($snapshot->relationships as $relationship) {
      if ($relationship->type === RelationshipType::Declares) {
        foreach ($relationship->to as $library) {
          $declarers[$library][] = $relationship->from;
        }
      }
    }
    foreach ($declarers as $library => $from) {
      if (array_filter($from, static fn (string $id) => !isset($removed[$id])) === []) {
        $removed[$library] = TRUE;
      }
    }
    return $removed;
  }

}
