<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * The decisions an export carries, and the organization that issues them.
 */
final class VexSelection {

  /**
   * Constructs a VexSelection.
   *
   * @param string $issuer
   *   The organization named as the author; empty when none is set.
   * @param string|null $contact
   *   A web or email address of the organization.
   * @param list<\Sparkfabrik\DrupalSbom\Model\VexAssertion> $assertions
   *   The decisions that apply to the exported analysis.
   */
  public function __construct(
    public readonly string $issuer,
    public readonly ?string $contact,
    public readonly array $assertions,
  ) {}

  /**
   * Identifies exactly which decision revisions the selection holds.
   */
  public function fingerprint(): string {
    $revisions = array_map(static fn (VexAssertion $assertion) => $assertion->statement . ':' . $assertion->revision, $this->assertions);
    sort($revisions);
    return substr(hash('sha256', $this->issuerId() . "\n" . implode("\n", $revisions)), 0, 16);
  }

  /**
   * Identifies the issuing organization as named and reachable now.
   */
  public function issuerId(): string {
    return substr(hash('sha256', $this->issuer . "\n" . ($this->contact ?? '')), 0, 16);
  }

}
