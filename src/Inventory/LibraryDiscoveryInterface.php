<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

/**
 * Gives the asset libraries a running Drupal site has discovered.
 *
 * The Drubom module adapts Drupal's library discovery to it; outside a site
 * the collector reads the *.libraries.yml files instead.
 */
interface LibraryDiscoveryInterface {

  /**
   * Returns the libraries of an extension, keyed by library name.
   *
   * A library without a version of its own must keep "VERSION" or no
   * version: Drupal's discovery replaces "VERSION" with the core version,
   * which an adapter has to put back, as it says nothing about the library.
   *
   * @return array<string, array<mixed>>
   *   The library definitions, as Drupal's library discovery returns them.
   */
  public function getLibrariesByExtension(string $extension): array;

}
