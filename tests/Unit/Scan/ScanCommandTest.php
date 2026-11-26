<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Scan;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\Collector\FileExtensionCollector;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Scan\ScanCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the scan of a codebase from outside the site.
 */
#[CoversClass(ScanCommand::class)]
#[CoversClass(FileExtensionCollector::class)]
#[CoversClass(ProjectRoots::class)]
#[Group('drubom')]
final class ScanCommandTest extends TestCase {

  /**
   * A writable copy of the project fixture.
   */
  private string $project;

  /**
   * A directory outside the project.
   */
  private string $outside;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $base = sys_get_temp_dir() . '/drubom-scan-' . bin2hex(random_bytes(6));
    $this->project = $base . '/project';
    $this->outside = $base . '/outside';
    $this->copy((string) realpath(__DIR__ . '/../../fixtures/projects/scan'), $this->project);
    // Every way a site runs code on load records that it ran.
    $canary = "<?php\nfile_put_contents('" . $base . "/EXECUTED', __FILE__ . PHP_EOL, FILE_APPEND);\n";
    $files = [
      'canary.php',
      'vendor/autoload.php',
      'vendor/composer/installed.php',
      'docroot/sites/default/settings.php',
      'docroot/autoload.php',
    ];
    foreach ($files as $file) {
      @mkdir(dirname($this->project . '/' . $file), 0777, TRUE);
      file_put_contents($this->project . '/' . $file, $canary);
    }
    mkdir($this->outside . '/elsewhere', 0777, TRUE);
    file_put_contents($this->outside . '/elsewhere/elsewhere.info.yml', "name: Elsewhere\ntype: module\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove(dirname($this->project));
    parent::tearDown();
  }

  /**
   * The scan reads the codebase and never runs any of its code.
   */
  public function testScan(): void {
    [$code, , $stderr] = $this->scan();
    $this->assertSame(ScanCommand::OK, $code, $stderr);
    $this->assertFileDoesNotExist(dirname($this->project) . '/EXECUTED');

    $graph = $this->graph();
    $sbom = $this->element($graph, 'software_Sbom');
    $this->assertSame(['build'], $sbom['software_sbomType']);
    $document = $this->element($graph, 'SpdxDocument');
    $this->assertSame('example/scan software bill of materials', $document['name']);
    $this->assertStringContainsString('outside the site', $document['comment']);

    $packages = $this->packages($graph);
    $this->assertSame('4.1.0', $packages['drubom_scan_contrib/chart']['software_packageVersion'] ?? NULL);
    $this->assertSame('1.2.0', $packages['drupal/foo']['software_packageVersion'] ?? NULL);
    $this->assertSame('1.0.0', $packages['drubom_scan_custom']['software_packageVersion'] ?? NULL);
    $this->assertArrayHasKey('drubom_scan_theme', $packages);
    $this->assertArrayNotHasKey('drubom_scan_core_test', $packages);
    $this->assertArrayNotHasKey('drubom_scan_custom_test', $packages);
    $this->assertStringContainsString('installed state unknown', $packages['drubom_scan_theme']['comment'] ?? '');
  }

  /**
   * Without a Composer installation, the document describes the sources.
   */
  public function testLockfileOnly(): void {
    unlink($this->project . '/vendor/composer/installed.json');
    [$code, , $stderr] = $this->scan();
    $this->assertContains($code, [ScanCommand::OK, ScanCommand::PARTIAL], $stderr);
    $this->assertSame(['source'], $this->element($this->graph(), 'software_Sbom')['software_sbomType']);
    $this->assertArrayHasKey('drupal/foo', $this->packages($this->graph()));
  }

  /**
   * Discovery stays inside the project and reports what it leaves out.
   */
  public function testDiscoveryLimits(): void {
    symlink($this->outside . '/elsewhere', $this->project . '/docroot/modules/elsewhere');
    symlink($this->project . '/docroot/modules', $this->project . '/docroot/modules/custom/loop');
    mkdir($this->project . '/docroot/sites/default/modules/drubom_scan_custom', 0777, TRUE);
    file_put_contents($this->project . '/docroot/sites/default/modules/drubom_scan_custom/drubom_scan_custom.info.yml', "name: Bar copy\ntype: module\n");

    [$code, , $stderr] = $this->scan();
    $this->assertSame(ScanCommand::PARTIAL, $code, $stderr);
    $this->assertStringContainsString('drupal_extensions', $stderr);
    $packages = $this->packages($this->graph());
    $this->assertArrayNotHasKey('elsewhere', $packages);
    $this->assertSame('1.0.0', $packages['drubom_scan_custom']['software_packageVersion'] ?? NULL);
    $comment = $this->element($this->graph(), 'SpdxDocument')['comment'];
    $this->assertStringContainsString('elsewhere links outside the application root', $comment);
    $this->assertStringContainsString('The extension drubom_scan_custom is also found in sites/default/modules/drubom_scan_custom', $comment);
  }

  /**
   * Invalid command lines and roots fail without writing anything.
   */
  public function testFailures(): void {
    $output = dirname($this->project) . '/sbom.spdx.json';
    $this->assertSame(ScanCommand::FAILED, $this->scan(['--skip', 'grype'])[0]);
    $this->assertSame(ScanCommand::FAILED, $this->scan(['--unknown'])[0]);
    $this->assertSame(ScanCommand::FAILED, ScanCommand::run(['--root', $this->project], ...$this->streams()));
    $this->assertSame(ScanCommand::FAILED, ScanCommand::run(['--root', $this->project . '/missing', '--output', $output], ...$this->streams()));
    $this->assertSame(ScanCommand::FAILED, $this->scan(['--web', '../outside'])[0]);
    $this->assertFileDoesNotExist($output);

    $help = $this->streams();
    $this->assertSame(ScanCommand::OK, ScanCommand::run(['--help'], ...$help));
    rewind($help[0]);
    $this->assertStringContainsString('drupal_extensions', (string) stream_get_contents($help[0]));
  }

  /**
   * Skipping Composer names the project after its directory.
   */
  public function testSkipComposer(): void {
    [$code, , $stderr] = $this->scan(['--skip=composer']);
    $this->assertSame(ScanCommand::OK, $code, $stderr);
    $this->assertSame('project software bill of materials', $this->element($this->graph(), 'SpdxDocument')['name']);
    $this->assertSame(['build'], $this->element($this->graph(), 'software_Sbom')['software_sbomType']);
  }

  /**
   * Without the web root, Composer packages are still listed.
   */
  public function testMissingWebRoot(): void {
    rename($this->project . '/docroot', dirname($this->project) . '/docroot');
    [$code, , $stderr] = $this->scan();
    $this->assertSame(ScanCommand::PARTIAL, $code, $stderr);
    $packages = $this->packages($this->graph());
    $this->assertSame('1.2.0', $packages['drupal/foo']['software_packageVersion'] ?? NULL);
    $this->assertArrayNotHasKey('drubom_scan_custom', $packages);
    $this->assertStringContainsString('The web root does not exist', $this->element($this->graph(), 'SpdxDocument')['comment']);
  }

  /**
   * Runs the scan on the project copy.
   *
   * @param list<string> $arguments
   *   Extra arguments.
   *
   * @return array{int, string, string}
   *   The exit code, the output and the errors.
   */
  private function scan(array $arguments = []): array {
    $streams = $this->streams();
    $output = dirname($this->project) . '/sbom.spdx.json';
    $code = ScanCommand::run(['--root', $this->project, '--output', $output, '--skip', 'php', ...$arguments], ...$streams);
    $contents = [];
    foreach ($streams as $stream) {
      rewind($stream);
      $contents[] = (string) stream_get_contents($stream);
    }
    return [$code, ...$contents];
  }

  /**
   * Opens memory streams for the output and the errors.
   *
   * @return array{resource, resource}
   *   The streams.
   */
  private function streams(): array {
    return [fopen('php://memory', 'w+b'), fopen('php://memory', 'w+b')];
  }

  /**
   * Returns the graph of the written document.
   *
   * @return list<array<string, mixed>>
   *   The graph nodes.
   */
  private function graph(): array {
    $document = json_decode((string) file_get_contents(dirname($this->project) . '/sbom.spdx.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    return $document['@graph'];
  }

  /**
   * Returns the only element of a type.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   * @param string $type
   *   The element type.
   *
   * @return array<string, mixed>
   *   The element.
   */
  private function element(array $graph, string $type): array {
    $elements = array_values(array_filter($graph, static fn ($node) => ($node['type'] ?? NULL) === $type));
    $this->assertCount(1, $elements);
    return $elements[0];
  }

  /**
   * Returns the packages of a graph keyed by name.
   *
   * @param list<array<string, mixed>> $graph
   *   The graph nodes.
   *
   * @return array<string, array<string, mixed>>
   *   The packages.
   */
  private function packages(array $graph): array {
    $packages = [];
    foreach ($graph as $node) {
      if (($node['type'] ?? NULL) === 'software_Package') {
        $packages[$node['name']] = $node;
      }
    }
    return $packages;
  }

  /**
   * Copies a directory tree.
   */
  private function copy(string $source, string $target): void {
    mkdir($target, 0777, TRUE);
    $entries = scandir($source);
    foreach ($entries === FALSE ? [] : $entries as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      is_dir($source . '/' . $entry) ? $this->copy($source . '/' . $entry, $target . '/' . $entry) : copy($source . '/' . $entry, $target . '/' . $entry);
    }
  }

  /**
   * Removes a directory tree without following links.
   */
  private function remove(string $path): void {
    if (is_link($path) || is_file($path)) {
      unlink($path);
      return;
    }
    $entries = scandir($path);
    foreach ($entries === FALSE ? [] : $entries as $entry) {
      if ($entry !== '.' && $entry !== '..') {
        $this->remove($path . '/' . $entry);
      }
    }
    rmdir($path);
  }

}
