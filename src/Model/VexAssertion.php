<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * One recorded decision, pinned to the revision that is exported.
 */
final class VexAssertion {

  /**
   * Constructs a VexAssertion.
   *
   * @param string $statement
   *   The UUID of the decision.
   * @param int $revision
   *   The exported revision; it grows whenever the decision changes.
   * @param string $finding
   *   The finding the decision applies to.
   * @param string $status
   *   The decision: not_affected, affected, fixed or under_investigation.
   * @param string|null $justification
   *   Why the site is not affected, as SPDX names it.
   * @param string|null $explanation
   *   What was checked and found.
   * @param string|null $action
   *   What will be done, for an affected site.
   * @param \DateTimeImmutable $decidedAt
   *   When this revision was made.
   */
  public function __construct(
    public readonly string $statement,
    public readonly int $revision,
    public readonly string $finding,
    public readonly string $status,
    public readonly ?string $justification,
    public readonly ?string $explanation,
    public readonly ?string $action,
    public readonly \DateTimeImmutable $decidedAt,
  ) {}

}
