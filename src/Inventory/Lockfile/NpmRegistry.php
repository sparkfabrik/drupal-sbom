<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Lockfile;

use Sparkfabrik\DrupalSbom\Util\InvalidYamlException;
use Sparkfabrik\DrupalSbom\Util\Yaml;

/**
 * Tells packages of the public npm registry from private and local ones.
 *
 * Only packages of the public registry get a public identity, so only they
 * can be sent to advisory sources.
 */
final class NpmRegistry {

  /**
   * Hosts of the public npm registry and its Yarn mirror.
   */
  private const PUBLIC_HOSTS = ['registry.npmjs.org', 'registry.yarnpkg.com'];

  /**
   * Constructs an NpmRegistry.
   *
   * @param string|null $default
   *   The registry the project configures, or NULL for the public one.
   * @param array<string, string> $scopes
   *   Registries configured per scope, keyed by scope without the "@".
   * @param list<string> $privateScopes
   *   Scopes whose packages must not be disclosed.
   * @param bool $authenticated
   *   Whether npm credentials make scoped packages private.
   */
  public function __construct(
    private readonly ?string $default = NULL,
    private readonly array $scopes = [],
    private readonly array $privateScopes = [],
    private readonly bool $authenticated = FALSE,
  ) {}

  /**
   * Reads registry overrides from the application root to a directory.
   *
   * Reads .npmrc (npm and pnpm) and .yarnrc.yml (Yarn Berry). Configuration
   * outside the application root, including symlinked files, is not read.
   *
   * @param string $directory
   *   The lockfile directory.
   * @param string|null $application
   *   The application boundary; defaults to the lockfile directory.
   * @param list<string> $privateScopes
   *   Scopes whose packages must not be disclosed.
   */
  public static function fromDirectory(string $directory, ?string $application = NULL, array $privateScopes = []): self {
    $unknown = new self('unknown:', privateScopes: $privateScopes, authenticated: TRUE);
    $application = realpath($application ?? $directory);
    $directory = realpath($directory);
    if ($application === FALSE || $directory === FALSE || ($directory !== $application && !str_starts_with($directory, $application . DIRECTORY_SEPARATOR))) {
      return $unknown;
    }
    $directories = [];
    for ($current = $directory;; $current = dirname($current)) {
      array_unshift($directories, $current);
      if ($current === $application) {
        break;
      }
    }
    $state = [
      'default' => NULL,
      'npmDefault' => NULL,
      'scopes' => [],
      'authenticated' => FALSE,
      'defaultCredentials' => FALSE,
    ];
    foreach ($directories as $directory) {
      // The files may hold registry tokens: they are read, never recorded.
      $npmrc = self::read($directory . '/.npmrc');
      if ($npmrc === FALSE) {
        return $unknown;
      }
      if ($npmrc !== NULL) {
        self::applyNpmrc($npmrc, $state);
      }
      $yarnrc = self::read($directory . '/.yarnrc.yml');
      if ($yarnrc === FALSE) {
        return $unknown;
      }
      if ($yarnrc !== NULL && !self::applyYarnrc($yarnrc, $state)) {
        return $unknown;
      }
    }
    $authenticated = $state['authenticated'] || ($state['defaultCredentials'] && ($state['npmDefault'] === NULL || self::isPublicUrl($state['npmDefault'])));
    return new self($state['default'], $state['scopes'], $privateScopes, $authenticated);
  }

  /**
   * Reads a configuration file.
   *
   * @return string|false|null
   *   The contents, NULL when there is no file, or FALSE when the file is a
   *   symlink or cannot be read.
   */
  private static function read(string $file): string|false|null {
    if (is_link($file) || (is_file($file) && !is_readable($file))) {
      return FALSE;
    }
    return is_file($file) ? file_get_contents($file) : NULL;
  }

  /**
   * Applies the registries and credentials of an .npmrc file.
   *
   * @param string $contents
   *   The file contents.
   * @param array{default: ?string, npmDefault: ?string, scopes: array<string, string>, authenticated: bool, defaultCredentials: bool} $state
   *   The configuration read so far.
   */
  private static function applyNpmrc(string $contents, array &$state): void {
    $lines = preg_split('/\R/', $contents);
    foreach ($lines === FALSE ? [] : $lines as $line) {
      if (preg_match('#^\s*(?://([^/:]+)(?::443)?/[^:]*:)?_(?:authToken|auth|password)\s*=#i', $line, $matches) === 1) {
        if (($matches[1] ?? '') === '') {
          $state['defaultCredentials'] = TRUE;
        }
        elseif (self::isPublicHost($matches[1])) {
          $state['authenticated'] = TRUE;
        }
      }
      if (preg_match('/^\s*(?:@([^:\s]+):)?registry\s*=\s*"?([^"\s]+)"?\s*$/', $line, $matches) === 1) {
        if ($matches[1] === '') {
          $state['default'] = $state['npmDefault'] = $matches[2];
        }
        else {
          $state['scopes'][$matches[1]] = $matches[2];
        }
      }
    }
  }

  /**
   * Applies the registries and credentials of a .yarnrc.yml file.
   *
   * @param string $contents
   *   The file contents.
   * @param array{default: ?string, npmDefault: ?string, scopes: array<string, string>, authenticated: bool, defaultCredentials: bool} $state
   *   The configuration read so far.
   *
   * @return bool
   *   FALSE when the file cannot be parsed.
   */
  private static function applyYarnrc(string $contents, array &$state): bool {
    try {
      $data = Yaml::decode($contents);
    }
    catch (InvalidYamlException) {
      // An unreadable configuration may name any registry.
      return FALSE;
    }
    if (!is_array($data)) {
      return TRUE;
    }
    if (is_string($data['npmRegistryServer'] ?? NULL)) {
      $state['default'] = $data['npmRegistryServer'];
    }
    $fallback = $state['default'] ?? 'https://registry.npmjs.org';
    if (self::hasCredentials($data) && self::isPublicUrl($fallback)) {
      $state['authenticated'] = TRUE;
    }
    foreach (is_array($data['npmRegistries'] ?? NULL) ? $data['npmRegistries'] : [] as $registry => $settings) {
      if (is_array($settings) && self::hasCredentials($settings) && self::isPublicUrl((string) $registry)) {
        $state['authenticated'] = TRUE;
      }
    }
    foreach (is_array($data['npmScopes'] ?? NULL) ? $data['npmScopes'] : [] as $scope => $settings) {
      if (!is_array($settings)) {
        continue;
      }
      if (is_string($settings['npmRegistryServer'] ?? NULL)) {
        $state['scopes'][ltrim((string) $scope, '@')] = $settings['npmRegistryServer'];
      }
      if (self::hasCredentials($settings) && self::isPublicUrl($settings['npmRegistryServer'] ?? $fallback)) {
        $state['authenticated'] = TRUE;
      }
    }
    return TRUE;
  }

  /**
   * Whether Yarn settings carry credentials.
   *
   * @param array<mixed> $settings
   *   The settings.
   */
  private static function hasCredentials(array $settings): bool {
    return array_key_exists('npmAuthToken', $settings) || array_key_exists('npmAuthIdent', $settings);
  }

  /**
   * Whether a registry URL points to the public registry.
   */
  private static function isPublicUrl(string $url): bool {
    return self::isPublicHost((string) parse_url($url, PHP_URL_HOST));
  }

  /**
   * Whether a tarball URL is a package of the public registry.
   */
  public static function isPublicTarball(string $url): bool {
    return preg_match('#^https://([^/]+)/.+/-/[^/]+\.tgz(\#.*)?$#', $url, $matches) === 1
      && self::isPublicHost($matches[1]);
  }

  /**
   * Whether a package resolved from the configured registry is public.
   */
  public function isPublic(string $name): bool {
    $scope = str_starts_with($name, '@') ? substr($name, 1, (int) strpos($name, '/') - 1) : NULL;
    $registry = ($scope === NULL ? NULL : ($this->scopes[$scope] ?? NULL)) ?? $this->default;
    return $this->allowsDisclosure($name) && ($registry === NULL || self::isPublicUrl($registry));
  }

  /**
   * Whether local privacy rules allow disclosure of a package.
   */
  public function allowsDisclosure(string $name): bool {
    if (!str_starts_with($name, '@')) {
      return TRUE;
    }
    $scope = strstr($name, '/', TRUE);
    return !$this->authenticated && !in_array($scope, $this->privateScopes, TRUE);
  }

  /**
   * Whether a host serves the public registry.
   */
  private static function isPublicHost(string $host): bool {
    return in_array(strtolower($host), self::PUBLIC_HOSTS, TRUE);
  }

}
