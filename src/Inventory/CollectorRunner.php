<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\CollectorResult;
use Sparkfabrik\DrupalSbom\Model\CollectorStatus;
use Psr\Log\LoggerInterface;

/**
 * Runs collectors into an inventory.
 */
final class CollectorRunner {

  /**
   * Runs the collectors in order into an inventory.
   *
   * @param iterable<\Sparkfabrik\DrupalSbom\Inventory\CollectorInterface> $collectors
   *   The collectors, in priority order.
   * @param \Sparkfabrik\DrupalSbom\Inventory\InventoryBuilder $builder
   *   The inventory.
   * @param list<string> $skipped
   *   Identifiers of the collectors not to run.
   * @param \Psr\Log\LoggerInterface $logger
   *   Receives the failure details, which can contain server paths.
   *
   * @return list<\Sparkfabrik\DrupalSbom\Model\CollectorResult>
   *   One result per collector.
   */
  public static function collect(iterable $collectors, InventoryBuilder $builder, array $skipped, LoggerInterface $logger): array {
    $results = [];
    foreach ($collectors as $collector) {
      if (in_array($collector->id(), $skipped, TRUE)) {
        $results[] = new CollectorResult($collector->id(), $collector->version(), CollectorStatus::Skipped);
        continue;
      }
      try {
        $status = $collector->collect($builder);
      }
      catch (\Throwable $e) {
        $logger->error('The @collector collector failed: @message', [
          '@collector' => $collector->id(),
          '@message' => $e->getMessage(),
        ]);
        $status = CollectorStatus::Failed;
      }
      $results[] = new CollectorResult($collector->id(), $collector->version(), $status);
    }
    return $results;
  }

}
