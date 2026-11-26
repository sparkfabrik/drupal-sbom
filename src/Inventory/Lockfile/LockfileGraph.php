<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Inventory\Lockfile;

/**
 * The packages of one Yarn or pnpm lockfile and how they depend on each other.
 */
final class LockfileGraph {

  /**
   * Constructs a LockfileGraph.
   *
   * @param array<string, array{name: string, version: string, registry: bool, dependencies: list<string>}> $entries
   *   Locked packages keyed by resolution. Dependencies use those keys.
   * @param array<string, list<string>> $roots
   *   Entry keys of the project's direct dependencies, keyed by group:
   *   dependencies, devDependencies or optionalDependencies.
   * @param list<string> $unresolved
   *   Requirements that no entry satisfies.
   */
  public function __construct(
    public readonly array $entries,
    public readonly array $roots,
    public readonly array $unresolved = [],
  ) {}

  /**
   * Splits "name@version" or "name@range" into the name and the rest.
   *
   * @return array{0: string, 1: string}
   *   The name and what follows the separating "@".
   */
  public static function split(string $spec): array {
    // The name ends at the first "@" after the optional scope prefix.
    $at = strpos($spec, '@', 1);
    if ($at === FALSE) {
      return [$spec, ''];
    }
    return [substr($spec, 0, $at), substr($spec, $at + 1)];
  }

}
