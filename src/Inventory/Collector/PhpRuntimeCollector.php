<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Collector;

use Sparkfabrik\DrupalSbom\Inventory\CollectorInterface;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\PhpRuntime;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Confidence;
use Sparkfabrik\DrupalSbom\Model\ContextKind;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Evidence;
use Sparkfabrik\DrupalSbom\Model\Gap;
use Sparkfabrik\DrupalSbom\Model\GapKind;
use Sparkfabrik\DrupalSbom\Model\License;
use Sparkfabrik\DrupalSbom\Model\Occurrence;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Model\Presence;
use Sparkfabrik\DrupalSbom\Model\Relationship;
use Sparkfabrik\DrupalSbom\Model\RelationshipType;
use Sparkfabrik\DrupalSbom\Model\Scope;

/**
 * Collects the PHP runtime, its server API and its loaded extensions.
 *
 * The facts describe the process taking the snapshot. Drush and the web
 * server can run different PHP builds, so the server API is a package too.
 * Extensions use the Composer platform names, such as ext-curl, so Composer
 * requirements resolve to them. Parts built from the PHP source carry a
 * package URL with their php-src directory, such as
 * pkg:generic/php@8.4.27#ext/dom.
 */
final class PhpRuntimeCollector implements CollectorInterface {

  /**
   * Evidence source for runtime facts, which come from no file.
   */
  public const SOURCE = 'php-runtime';

  /**
   * Identifier of the PHP runtime package.
   */
  public const PACKAGE_ID = 'php:php';

  /**
   * Directories in php-src of extensions whose reported name differs.
   */
  private const EXTENSION_DIRECTORIES = [
    'core' => 'Zend',
    'zend opcache' => 'opcache',
  ];

  /**
   * Extensions maintained in the php-src ext directory.
   */
  private const BUNDLED_EXTENSIONS = [
    'bcmath', 'bz2', 'calendar', 'ctype', 'curl', 'date', 'dba', 'dl_test', 'dom', 'enchant', 'exif', 'ffi',
    'fileinfo', 'filter', 'ftp', 'gd', 'gettext', 'gmp', 'hash', 'iconv', 'imap', 'intl', 'json', 'ldap', 'lexbor',
    'libxml', 'mbstring', 'mysqli', 'mysqlnd', 'oci8', 'odbc', 'opcache', 'openssl', 'pcntl', 'pcre', 'pdo', 'pdo_dblib',
    'pdo_firebird', 'pdo_mysql', 'pdo_oci', 'pdo_odbc', 'pdo_pgsql', 'pdo_sqlite', 'pgsql', 'phar', 'posix', 'pspell', 'random',
    'readline', 'reflection', 'session', 'shmop', 'simplexml', 'snmp', 'soap', 'sockets', 'sodium', 'spl', 'sqlite3',
    'standard', 'sysvmsg', 'sysvsem', 'sysvshm', 'tidy', 'tokenizer', 'uri', 'xml', 'xmlreader', 'xmlwriter', 'xsl',
    'zend_test', 'zip', 'zlib',
  ];

  /**
   * The first PHP branch without a bundled extension, which moved to PECL.
   */
  private const UNBUNDLED_EXTENSIONS = [
    'imap' => '8.4',
    'oci8' => '8.4',
    'pdo_oci' => '8.4',
    'pspell' => '8.4',
  ];

  /**
   * Directories in php-src of the server APIs, keyed by PHP_SAPI.
   */
  private const SAPI_DIRECTORIES = [
    'apache2handler' => 'apache2handler',
    'cgi-fcgi' => 'cgi',
    'cli' => 'cli',
    'embed' => 'embed',
    'fpm-fcgi' => 'fpm',
    'litespeed' => 'litespeed',
    'phpdbg' => 'phpdbg',
  ];

  /**
   * Constructs a PhpRuntimeCollector.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\PhpRuntime|null $runtime
   *   The runtime to describe; NULL for the one executing this code.
   */
  public function __construct(
    private readonly ?PhpRuntime $runtime = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'php';
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
    $runtime = $this->runtime ?? PhpRuntime::current();
    $status = CollectorStatus::Complete;
    $version = self::releaseVersion($runtime->version);
    if ($version === NULL) {
      $status = CollectorStatus::Partial;
      $builder->addGap(new Gap(GapKind::UnknownVersion, sprintf('PHP version %s is not a release version.', $runtime->version), subject: self::PACKAGE_ID));
    }
    $purl = static fn (?string $directory = NULL) => $version === NULL ? NULL : 'pkg:generic/php@' . rawurlencode($version) . ($directory === NULL ? '' : '#' . $directory);
    $this->add($builder, new Package(
      self::PACKAGE_ID,
      Ecosystem::Php,
      'php',
      $version,
      $purl(),
      self::license(),
      sprintf('PHP %s, %s server API', $runtime->version, $runtime->sapi),
      'https://www.php.net/',
    ), 'PHP_VERSION');

    $contents = [];
    $sapi = 'php:sapi-' . $runtime->sapi;
    $sapiDirectory = self::SAPI_DIRECTORIES[$runtime->sapi] ?? NULL;
    $this->add($builder, new Package(
      $sapi,
      Ecosystem::Php,
      'sapi-' . $runtime->sapi,
      $version,
      $sapiDirectory === NULL ? NULL : $purl('sapi/' . $sapiDirectory),
      self::license(),
      sprintf('PHP %s server API', $runtime->sapi),
    ), 'PHP_SAPI');
    $contents[$sapi] = $sapi;

    foreach ($runtime->extensions as $name => $reported) {
      $key = strtolower($name);
      $bundled = in_array($key, self::BUNDLED_EXTENSIONS, TRUE)
        && ($version === NULL || !isset(self::UNBUNDLED_EXTENSIONS[$key]) || version_compare($version, self::UNBUNDLED_EXTENSIONS[$key], '<'));
      $directory = self::EXTENSION_DIRECTORIES[$key] ?? ($bundled ? $key : NULL);
      // Extensions built with PHP usually report the PHP version, sometimes
      // with a prefix such as "mysqlnd 8.4.1".
      $extensionVersion = $reported !== NULL && $version !== NULL && str_contains($reported, $runtime->version) ? $version : $reported;
      $id = 'php:' . self::platformName($name);
      $this->add($builder, new Package(
        $id,
        Ecosystem::Php,
        self::platformName($name),
        $extensionVersion,
        $directory === NULL ? NULL : $purl(($directory === 'Zend' ? '' : 'ext/') . $directory),
        self::license(),
        sprintf('PHP extension %s', $name),
      ), 'get_loaded_extensions');
      $contents[$id] = $id;
    }
    ksort($contents);
    $builder->addRelationship(new Relationship(self::PACKAGE_ID, RelationshipType::Contains, array_values($contents), [new Evidence(self::SOURCE, Confidence::High, 'get_loaded_extensions')]));
    return $status;
  }

  /**
   * Returns the Composer platform name of an extension.
   */
  public static function platformName(string $extension): string {
    return 'ext-' . str_replace(' ', '-', strtolower($extension));
  }

  /**
   * Returns the PHP version without a distribution suffix.
   *
   * Prerelease suffixes such as RC1 or -dev are part of the version; others,
   * such as -1ubuntu2.14, name a distribution build.
   *
   * @return string|null
   *   The version, or NULL when it has no release number.
   */
  private static function releaseVersion(string $reported): ?string {
    if (!preg_match('/^(\d+\.\d+\.\d+)(.*)$/', $reported, $matches)) {
      return NULL;
    }
    return preg_match('/^(?:alpha|beta|RC)\d*|^-dev\b/i', $matches[2], $prerelease) ? $matches[1] . $prerelease[0] : $matches[1];
  }

  /**
   * Returns the license information, which the runtime does not report.
   */
  private static function license(): License {
    return LicenseClassifier::composer([], [new Evidence(self::SOURCE, Confidence::High)]);
  }

  /**
   * Adds a runtime package with its occurrence.
   *
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param \Sparkfabrik\DrupalSbom\Model\Package $package
   *   The package.
   * @param string $field
   *   Where the runtime reports the package.
   */
  private function add(InventoryBuilder $builder, Package $package, string $field): void {
    $builder->addPackage($package);
    $builder->addOccurrence(new Occurrence(
      'occurrence:' . $package->id,
      $package->id,
      NULL,
      Presence::Installed,
      [Scope::Production],
      ContextKind::PhpRuntime,
      self::SOURCE,
      [new Evidence(self::SOURCE, Confidence::High, $field)],
    ));
  }

}
