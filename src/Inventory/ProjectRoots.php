<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Composer\Autoload\ClassLoader;

/**
 * Absolute locations of the inspected project.
 */
final class ProjectRoots {

  /**
   * Constructs a ProjectRoots object.
   *
   * @param string $application
   *   The Composer project root.
   * @param string $vendor
   *   The Composer vendor directory.
   * @param string $web
   *   The Drupal web root.
   */
  public function __construct(
    public readonly string $application,
    public readonly string $vendor,
    public readonly string $web,
  ) {}

  /**
   * Resolves the roots of the running site.
   *
   * The vendor directory is the one registered for the site's own class
   * loader, so a global Composer or Drush installation is never mistaken
   * for it.
   *
   * @param string $appRoot
   *   The Drupal web root.
   * @param object $classLoader
   *   The site's class loader.
   */
  public static function fromRuntime(string $appRoot, object $classLoader): self {
    $vendor = NULL;
    foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
      if ($loader === $classLoader) {
        $vendor = $directory;
      }
    }
    if ($vendor === NULL) {
      throw new \RuntimeException('The Composer vendor directory of the site cannot be determined.');
    }
    // The site's own installed metadata, already trusted by its autoloader.
    $installed = require $vendor . '/composer/installed.php';
    return new self(
      (string) realpath($installed['root']['install_path']),
      (string) realpath($vendor),
      (string) realpath($appRoot),
    );
  }

  /**
   * Resolves the roots of a codebase from its files, without running it.
   *
   * The vendor directory and the web root come from composer.json, read as
   * data; both must stay inside the application root.
   *
   * @param string $root
   *   The Composer project root.
   * @param string|null $web
   *   The web root relative to the project root, or NULL to read it from
   *   the Drupal scaffold settings.
   *
   * @throws \InvalidArgumentException
   *   When the project root is missing, or another root is outside it.
   */
  public static function fromDirectory(string $root, ?string $web = NULL): self {
    $application = realpath($root);
    if ($application === FALSE || !is_dir($application)) {
      throw new \InvalidArgumentException(sprintf('The project root %s does not exist.', $root));
    }
    $manifest = is_file($application . '/composer.json') ? json_decode((string) file_get_contents($application . '/composer.json'), TRUE) : NULL;
    $manifest = is_array($manifest) ? $manifest : [];
    $vendor = $manifest['config']['vendor-dir'] ?? 'vendor';
    $web ??= $manifest['extra']['drupal-scaffold']['locations']['web-root'] ?? 'web';
    $roots = [];
    foreach (['vendor directory' => $vendor, 'web root' => $web] as $label => $path) {
      if (!is_string($path) || str_starts_with($path, '/') || in_array('..', explode('/', $path), TRUE)) {
        throw new \InvalidArgumentException(sprintf('The %s must be a path inside the project root.', $label));
      }
      $path = trim($path, '/');
      $roots[$label] = $application . '/' . ($path === '' ? '.' : $path);
    }
    // Either can be missing, as in a checkout before "composer install".
    $resolvedWeb = realpath($roots['web root']);
    $resolvedVendor = realpath($roots['vendor directory']);
    $roots = new self($application, $resolvedVendor === FALSE ? $roots['vendor directory'] : $resolvedVendor, $resolvedWeb === FALSE ? $roots['web root'] : $resolvedWeb);
    // A symbolic link could still lead outside.
    if (($resolvedWeb !== FALSE && $roots->relative($resolvedWeb) === NULL) || ($resolvedVendor !== FALSE && $roots->relative($resolvedVendor) === NULL)) {
      throw new \InvalidArgumentException('The vendor directory and the web root must be inside the project root.');
    }
    return $roots;
  }

  /**
   * Returns a path relative to the application root.
   *
   * @return string|null
   *   The relative path, or NULL when the path is outside the root.
   */
  public function relative(string $path): ?string {
    $resolved = realpath($path);
    if ($resolved === FALSE) {
      return NULL;
    }
    if ($resolved === $this->application) {
      return '.';
    }
    $prefix = $this->application . DIRECTORY_SEPARATOR;
    return str_starts_with($resolved, $prefix) ? substr($resolved, strlen($prefix)) : NULL;
  }

}
