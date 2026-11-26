<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory;

use Sparkfabrik\DrupalSbom\Model\CollectorStatus;

/**
 * Reads one kind of local metadata into an inventory.
 */
interface CollectorInterface {

  /**
   * The collector identifier, recorded in the snapshot coverage.
   */
  public function id(): string;

  /**
   * The collector version; bump it when the collected facts change.
   */
  public function version(): string;

  /**
   * Adds the collected facts to the inventory.
   *
   * Collectors run in service priority order, so later collectors can attach
   * to packages added by earlier ones.
   */
  public function collect(InventoryBuilder $builder): CollectorStatus;

}
