<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * Identity of a package; its physical appearances are occurrences.
 */
final class Package {

  /**
   * Constructs a Package object.
   *
   * @param string $id
   *   Identifier unique inside the snapshot.
   * @param \Sparkfabrik\DrupalSbom\Model\Ecosystem $ecosystem
   *   The package ecosystem.
   * @param string $name
   *   The package name, as the ecosystem spells it.
   * @param string|null $version
   *   The version, or NULL when unknown.
   * @param string|null $purl
   *   Package URL, only when the identity is verified.
   * @param \Sparkfabrik\DrupalSbom\Model\License $license
   *   The declared license information.
   * @param string|null $description
   *   Short description from the package metadata.
   * @param string|null $homepage
   *   Project homepage URL.
   * @param string|null $sourceReference
   *   Source control reference, for example a commit.
   * @param list<string> $patched
   *   Local patches applied on top of the upstream version.
   */
  public function __construct(
    public readonly string $id,
    public readonly Ecosystem $ecosystem,
    public readonly string $name,
    public readonly ?string $version,
    public readonly ?string $purl,
    public readonly License $license,
    public readonly ?string $description = NULL,
    public readonly ?string $homepage = NULL,
    public readonly ?string $sourceReference = NULL,
    public readonly array $patched = [],
  ) {}

  /**
   * Creates a package from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['id'],
      Ecosystem::from($data['ecosystem']),
      $data['name'],
      $data['version'],
      $data['purl'],
      License::fromArray($data['licenses']),
      $data['description'] ?? NULL,
      $data['homepage'] ?? NULL,
      $data['sourceReference'] ?? NULL,
      array_values($data['patched'] ?? []),
    );
  }

  /**
   * Returns the array representation.
   *
   * @return array<string, mixed>
   *   The array representation.
   */
  public function toArray(): array {
    $data = [
      'id' => $this->id,
      'ecosystem' => $this->ecosystem->value,
      'name' => $this->name,
      'version' => $this->version,
      'purl' => $this->purl,
      'description' => $this->description,
      'homepage' => $this->homepage,
      'sourceReference' => $this->sourceReference,
      'patched' => $this->patched === [] ? NULL : $this->patched,
      'licenses' => $this->license->toArray(),
    ];
    // Optional members are omitted rather than set to NULL.
    foreach (['description', 'homepage', 'sourceReference', 'patched'] as $optional) {
      if ($data[$optional] === NULL) {
        unset($data[$optional]);
      }
    }
    return $data;
  }

}
