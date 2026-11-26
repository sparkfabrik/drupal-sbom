<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\SbomFormatter;

use Sparkfabrik\DrupalSbom\Model\AnalysisRun;
use Sparkfabrik\DrupalSbom\Model\Snapshot;
use Sparkfabrik\DrupalSbom\Model\VexSelection;

/**
 * Turns an inventory snapshot into a standard SBOM document.
 */
interface SbomFormatterInterface {

  /**
   * Writes the document for a snapshot to a stream.
   *
   * @param \Sparkfabrik\DrupalSbom\Model\Snapshot $snapshot
   *   The snapshot to format.
   * @param \Sparkfabrik\DrupalSbom\Model\AnalysisRun|null $analysis
   *   Findings to include, from an analysis run of the same snapshot.
   * @param resource $stream
   *   Writable stream that receives the document.
   * @param \Sparkfabrik\DrupalSbom\Model\VexSelection|null $vex
   *   Decisions recorded on the findings of the analysis, if any.
   *
   * @return \Sparkfabrik\DrupalSbom\SbomFormatter\LossReport
   *   The snapshot facts the format could not carry.
   *
   * @throws \InvalidArgumentException
   *   When the snapshot is inconsistent or the analysis belongs to another
   *   snapshot.
   */
  public function format(Snapshot $snapshot, ?AnalysisRun $analysis, $stream, ?VexSelection $vex = NULL): LossReport;

}
