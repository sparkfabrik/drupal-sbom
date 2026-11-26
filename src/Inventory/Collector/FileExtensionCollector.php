<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Collector;

use Sparkfabrik\DrupalSbom\Util\InvalidYamlException;
use Sparkfabrik\DrupalSbom\Util\Yaml;
use Sparkfabrik\DrupalSbom\Inventory\CollectorInterface;
use Sparkfabrik\DrupalSbom\Inventory\ExtensionRecorder;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\ExtensionType;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;

/**
 * Collects Drupal modules, themes and profiles from their info files.
 *
 * Used outside the site: extensions are found on disk, in the directories
 * Drupal scans, so their installed state and the site themes are unknown.
 */
final class FileExtensionCollector implements CollectorInterface {

  /**
   * Directories never searched for extensions.
   */
  private const SKIPPED_DIRECTORIES = ['tests', 'node_modules', 'bower_components', 'vendor'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'drupal_extensions';
  }

  /**
   * {@inheritdoc}
   */
  public function version(): string {
    return '1';
  }

  /**
   * {@inheritdoc}
   */
  public function collect(InventoryBuilder $builder): CollectorStatus {
    $web = $builder->roots->web;
    if (!is_dir($web)) {
      $builder->addGap(new Gap(GapKind::UnreadableInput, 'The web root does not exist: Drupal extensions and libraries are not listed.'));
      return CollectorStatus::Partial;
    }
    $directories = ['core', 'profiles', 'modules', 'themes'];
    $sites = glob($web . '/sites/*', GLOB_ONLYDIR);
    foreach ($sites === FALSE ? [] : $sites as $site) {
      $directories[] = 'sites/' . basename($site) . '/modules';
      $directories[] = 'sites/' . basename($site) . '/themes';
    }
    $files = [];
    $visited = [];
    foreach ($directories as $directory) {
      $this->find($builder, $web . '/' . $directory, $files, $visited);
    }

    $status = CollectorStatus::Complete;
    $candidates = [];
    $seen = [];
    foreach ($files as $file) {
      $name = basename($file, '.info.yml');
      $path = substr(dirname($file), strlen($web) + 1);
      try {
        $info = Yaml::decode((string) $builder->read($file));
      }
      catch (InvalidYamlException $e) {
        $builder->addGap(new Gap(GapKind::UnreadableInput, 'Invalid YAML: ' . $e->getMessage(), path: $builder->roots->relative($file)));
        $status = CollectorStatus::Partial;
        continue;
      }
      $type = is_array($info) && is_string($info['type'] ?? NULL) ? ExtensionType::tryFrom($info['type']) : NULL;
      // Theme engines and other info files are not extensions DruBOM lists.
      if ($type === NULL || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        continue;
      }
      if (isset($seen[$name])) {
        $builder->addGap(new Gap(GapKind::Excluded, sprintf('The extension %s is also found in %s; only %s is listed.', $name, $path, $seen[$name]), path: $builder->roots->relative($file)));
        $status = CollectorStatus::Partial;
        continue;
      }
      $seen[$name] = $path;
      $theme = $type === ExtensionType::Theme;
      $candidates[] = [
        'name' => $name,
        'type' => $type,
        'installed' => NULL,
        'path' => $path,
        'infoFile' => basename($file),
        'info' => $info,
        'defaultTheme' => $theme ? NULL : FALSE,
        'adminTheme' => $theme ? NULL : FALSE,
      ];
    }
    $recorded = ExtensionRecorder::record($builder, $candidates);
    return $recorded === CollectorStatus::Complete ? $status : $recorded;
  }

  /**
   * Finds the info files below a directory.
   *
   * Symbolic links are followed only when they stay inside the application
   * root, and every directory is searched once.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param string $directory
   *   The directory to search.
   * @param list<string> $files
   *   The info files found so far.
   * @param array<string, true> $visited
   *   The resolved directories already searched.
   */
  private function find(InventoryBuilder $builder, string $directory, array &$files, array &$visited): void {
    $resolved = realpath($directory);
    if ($resolved === FALSE || !is_dir($resolved) || isset($visited[$resolved])) {
      return;
    }
    if ($builder->roots->relative($resolved) === NULL) {
      $builder->addGap(new Gap(GapKind::Excluded, sprintf('%s links outside the application root and is not searched.', basename($directory))));
      return;
    }
    $visited[$resolved] = TRUE;
    $entries = scandir($directory);
    foreach ($entries === FALSE ? [] : $entries as $entry) {
      if ($entry[0] === '.' || in_array($entry, self::SKIPPED_DIRECTORIES, TRUE)) {
        continue;
      }
      $path = $directory . '/' . $entry;
      if (is_dir($path)) {
        $this->find($builder, $path, $files, $visited);
      }
      elseif (str_ends_with($entry, '.info.yml') && is_file($path)) {
        $files[] = $path;
      }
    }
  }

}
