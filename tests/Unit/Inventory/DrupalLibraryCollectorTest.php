<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\Collector\DrupalLibraryCollector;
use Sparkfabrik\DrupalSbom\Inventory\Collector\FileExtensionCollector;
use Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Inventory\ProjectRoots;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Sparkfabrik\DrupalSbom\Model\Ecosystem;
use Sparkfabrik\DrupalSbom\Model\Package;
use Sparkfabrik\DrupalSbom\Util\YamlDecoderInterface;

/**
 * Tests reading library definitions from files.
 */
#[CoversClass(DrupalLibraryCollector::class)]
final class DrupalLibraryCollectorTest extends TestCase {

  /**
   * The temporary project.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = realpath(sys_get_temp_dir()) . '/drupal-sbom-libraries-' . bin2hex(random_bytes(4));
    $module = $this->root . '/web/modules/custom/example';
    mkdir($module, 0777, TRUE);
    file_put_contents($module . '/example.info.yml', "name: Example\ntype: module\n");
    file_put_contents($module . '/example.libraries.yml', "chart:\n  remote: https://example.com/chart\n  version: !php/const PHP_MAJOR_VERSION\n  js:\n    https://cdn.example.com/chart.js: { type: external }\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
    parent::tearDown();
  }

  /**
   * The default decoder rejects PHP constants and reports the file as a gap.
   */
  public function testDefaultDecoderRejectsConstants(): void {
    [$status, $snapshot] = $this->collect(new DrupalLibraryCollector());
    $this->assertSame(CollectorStatus::Partial, $status);
    $this->assertNull($snapshot->package('drupal-library:example/chart'));
    $this->assertNotSame([], $snapshot->coverage->gaps);
  }

  /**
   * A decoder supplied by the caller reads what the default one cannot.
   */
  public function testSuppliedDecoder(): void {
    $decoder = new class() implements YamlDecoderInterface {

      /**
       * The documents decoded.
       *
       * @var list<string>
       */
      public array $decoded = [];

      /**
       * {@inheritdoc}
       */
      public function decode(string $raw): mixed {
        $this->decoded[] = $raw;
        return [
          'chart' => [
            'remote' => 'https://example.com/chart',
            'version' => '3.2.1',
            'js' => ['https://cdn.example.com/chart.js' => ['type' => 'external']],
          ],
        ];
      }

    };
    [$status, $snapshot] = $this->collect(new DrupalLibraryCollector(NULL, $decoder));
    $this->assertSame(CollectorStatus::Complete, $status);
    $this->assertCount(1, $decoder->decoded);
    $this->assertSame('3.2.1', $snapshot->package('drupal-library:example/chart')?->version);
  }

  /**
   * Runs the extension and library collectors on the temporary project.
   *
   * @return array{\Sparkfabrik\DrupalSbom\Model\CollectorStatus, \Sparkfabrik\DrupalSbom\Model\Snapshot}
   *   The library collector's status and the snapshot.
   */
  private function collect(DrupalLibraryCollector $collector): array {
    $builder = new InventoryBuilder(new ProjectRoots($this->root, $this->root . '/vendor', $this->root . '/web'));
    $builder->setRootPackage(new Package('application:test', Ecosystem::Application, 'test', NULL, NULL, LicenseClassifier::composer([], [])));
    (new FileExtensionCollector())->collect($builder);
    $status = $collector->collect($builder);
    $snapshot = $builder->build('0b9d2c1e-6f1a-4c3b-9a8e-2d4f5e6a7b8c', new \DateTimeImmutable(), 'test', '5f3c2a1b-0d9e-4f8a-b7c6-1e2d3c4b5a69', 'Test', []);
    return [$status, $snapshot];
  }

}
