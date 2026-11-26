<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Scan;

use Sparkfabrik\DrupalSbom\Util\Uuid;
use Sparkfabrik\DrupalSbom\Inventory\Collector\ComposerCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\DrupalLibraryCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\FileExtensionCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\NpmCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\PhpRuntimeCollector;
use Sparkfabrik\DrupalSbom\Inventory\DevelopmentExclusion;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Inventory\CollectorRunner;
use Sparkfabrik\DrupalSbom\Model\CollectorResult;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\SbomFormatter\Spdx3Formatter;
use Composer\InstalledVersions;
use Psr\Log\AbstractLogger;

/**
 * Builds the SBOM of a Drupal codebase from its files, outside the site.
 *
 * Nothing of the inspected codebase is executed: its metadata files are
 * only read and parsed.
 */
final class ScanCommand {

  /**
   * The SBOM was written.
   */
  public const OK = 0;

  /**
   * Nothing was written.
   */
  public const FAILED = 1;

  /**
   * The SBOM was written, but a collector could not cover everything.
   */
  public const PARTIAL = 2;

  /**
   * The command help.
   */
  private const USAGE = <<<'TXT'
    Usage: vendor/bin/drupal-sbom --root <project> --output <file> [options]

    Builds an SPDX 3.0.1 SBOM of a Drupal codebase without running its code.

    Options:
      --root <dir>         The Composer project root to inspect.
      --output <file>      Where to write the SPDX JSON-LD document.
      --web <dir>          The web root, relative to the project root.
                           Default: the Drupal scaffold web root, or "web".
      --include-dev        Keep packages used only for development.
      --skip <collector>   Do not run a collector; repeatable. Collectors:
                           %s.
      --help               Show this help.

    Exit codes: 0 written; 2 written, but some coverage is missing;
    1 failed, nothing written.

    TXT;

  /**
   * Runs the command.
   *
   * @param list<string> $arguments
   *   The command line arguments, without the script name.
   * @param resource $stdout
   *   Receives the help.
   * @param resource $stderr
   *   Receives the progress and the errors.
   *
   * @return int
   *   The exit code.
   */
  public static function run(array $arguments, $stdout = STDOUT, $stderr = STDERR): int {
    $collectors = [
      new PhpRuntimeCollector(),
      new ComposerCollector(),
      new FileExtensionCollector(),
      new DrupalLibraryCollector(),
      new NpmCollector(),
    ];
    $ids = array_map(static fn ($collector) => $collector->id(), $collectors);
    try {
      $options = self::options($arguments, $ids);
    }
    catch (\InvalidArgumentException $e) {
      fwrite($stderr, $e->getMessage() . "\n\n" . sprintf(self::USAGE, implode(', ', $ids)));
      return self::FAILED;
    }
    if ($options['help']) {
      fwrite($stdout, sprintf(self::USAGE, implode(', ', $ids)));
      return self::OK;
    }
    try {
      $results = self::write($options, $collectors, $stderr);
    }
    catch (\Throwable $e) {
      fwrite($stderr, 'drupal-sbom: ' . $e->getMessage() . "\n");
      return self::FAILED;
    }
    $partial = array_filter($results, static fn (CollectorResult $result) => $result->status === CollectorStatus::Partial);
    if ($partial !== []) {
      fwrite($stderr, sprintf("drupal-sbom: incomplete coverage from %s; see the coverage gaps in the document comment.\n", implode(', ', array_map(static fn (CollectorResult $result) => $result->id, $partial))));
      return self::PARTIAL;
    }
    return self::OK;
  }

  /**
   * Parses the command line.
   *
   * @param list<string> $arguments
   *   The command line arguments.
   * @param list<string> $collectors
   *   The known collector identifiers.
   *
   * @return array{help: bool, root: string, output: string, web: ?string, includeDev: bool, skip: list<string>}
   *   The options.
   *
   * @throws \InvalidArgumentException
   *   When the command line is invalid.
   */
  private static function options(array $arguments, array $collectors): array {
    $options = ['help' => FALSE, 'root' => NULL, 'output' => NULL, 'web' => NULL, 'includeDev' => FALSE, 'skip' => []];
    $names = ['--root' => 'root', '--output' => 'output', '--web' => 'web', '--skip' => 'skip'];
    while ($arguments !== []) {
      $argument = array_shift($arguments);
      [$name, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, NULL];
      if ($name === '--help' || $name === '--include-dev') {
        $options[$name === '--help' ? 'help' : 'includeDev'] = TRUE;
        continue;
      }
      if (!isset($names[$name])) {
        throw new \InvalidArgumentException(sprintf('Unknown option %s.', $argument));
      }
      $value ??= array_shift($arguments);
      if ($value === NULL || $value === '') {
        throw new \InvalidArgumentException(sprintf('%s needs a value.', $name));
      }
      if ($name === '--skip') {
        if (!in_array($value, $collectors, TRUE)) {
          throw new \InvalidArgumentException(sprintf('Unknown collector %s.', $value));
        }
        $options['skip'][] = $value;
        continue;
      }
      $options[$names[$name]] = $value;
    }
    if (!$options['help'] && ($options['root'] === NULL || $options['output'] === NULL)) {
      throw new \InvalidArgumentException('--root and --output are required.');
    }
    return [
      'help' => $options['help'],
      'root' => (string) $options['root'],
      'output' => (string) $options['output'],
      'web' => $options['web'],
      'includeDev' => $options['includeDev'],
      'skip' => $options['skip'],
    ];
  }

  /**
   * Collects the inventory and writes the document.
   *
   * @param array{help: bool, root: string, output: string, web: ?string, includeDev: bool, skip: list<string>} $options
   *   The options.
   * @param list<\Sparkfabrik\DrupalSbom\Inventory\CollectorInterface> $collectors
   *   The collectors, in order.
   * @param resource $stderr
   *   Receives the collector failures.
   *
   * @return list<\Sparkfabrik\DrupalSbom\Model\CollectorResult>
   *   One result per collector.
   *
   * @throws \RuntimeException
   *   When the inventory cannot be built or written.
   */
  private static function write(array $options, array $collectors, $stderr): array {
    $roots = ProjectRoots::fromDirectory($options['root'], $options['web']);
    $builder = new InventoryBuilder($roots);
    // Without Composer metadata, the directory stands for the project. The
    // installed metadata is still recorded, as it tells a build apart.
    if (in_array('composer', $options['skip'], TRUE)) {
      $builder->read($roots->vendor . '/composer/installed.json');
      $builder->setRootPackage(new Package('application:project', Ecosystem::Application, basename($roots->application), NULL, NULL, LicenseClassifier::composer([], [])));
    }
    $logger = new class($stderr) extends AbstractLogger {

      /**
       * Constructs the logger.
       *
       * @param resource $stream
       *   The stream receiving the messages.
       */
      public function __construct(private $stream) {}

      /**
       * {@inheritdoc}
       *
       * @param mixed $level
       *   The log level.
       * @param string|\Stringable $message
       *   The message, with placeholders.
       * @param array<mixed> $context
       *   The placeholder values.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        fwrite($this->stream, 'drupal-sbom: ' . strtr((string) $message, array_map(strval(...), $context)) . "\n");
      }

    };
    $results = CollectorRunner::collect($collectors, $builder, $options['skip'], $logger);
    $failed = array_filter($results, static fn (CollectorResult $result) => $result->status === CollectorStatus::Failed);
    if ($failed !== []) {
      throw new \RuntimeException(sprintf('The %s collectors failed. Nothing was written.', implode(', ', array_map(static fn (CollectorResult $result) => $result->id, $failed))));
    }
    $builder->verifyInputs();

    $root = $builder->package((string) $builder->rootPackageId());
    $snapshot = $builder->build((new Uuid())->generate(), new \DateTimeImmutable(), self::version(), NULL, $root === NULL ? basename($roots->application) : $root->name, $results);
    if (!$options['includeDev']) {
      $snapshot = DevelopmentExclusion::apply($snapshot);
    }

    // Written aside first, so a failure never leaves a partial document.
    $temporary = $options['output'] . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $stream = @fopen($temporary, 'xb');
    if ($stream === FALSE) {
      throw new \RuntimeException(sprintf('%s cannot be written.', $options['output']));
    }
    try {
      (new Spdx3Formatter())->format($snapshot, NULL, $stream);
      fclose($stream);
      if (!rename($temporary, $options['output'])) {
        throw new \RuntimeException(sprintf('%s cannot be written.', $options['output']));
      }
    }
    finally {
      if (is_resource($stream)) {
        fclose($stream);
      }
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
    return $results;
  }

  /**
   * Returns the version of this library, as Composer installed it.
   */
  private static function version(): string {
    if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('sparkfabrik/drupal-sbom')) {
      return InstalledVersions::getPrettyVersion('sparkfabrik/drupal-sbom') ?? 'dev';
    }
    return 'dev';
  }

}
