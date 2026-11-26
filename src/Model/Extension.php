<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Model;

/**
 * A Drupal module, theme or profile found in the codebase.
 */
final class Extension {

  /**
   * Constructs an Extension object.
   *
   * @param string $id
   *   Identifier unique inside the snapshot.
   * @param string $machineName
   *   The extension machine name.
   * @param \Sparkfabrik\DrupalSbom\Model\ExtensionType $type
   *   The extension type.
   * @param bool|null $installed
   *   Whether the extension was installed when the snapshot was taken; NULL
   *   when unknown, as in a scan outside the site.
   * @param string $package
   *   Identifier of the package that ships the extension.
   * @param string $path
   *   Extension directory, relative to the application root.
   * @param string|null $baseTheme
   *   Base theme machine name, for themes.
   * @param bool|null $defaultTheme
   *   Whether this is the default theme; NULL when unknown.
   * @param bool|null $adminTheme
   *   Whether this is the administration theme; NULL when unknown.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $machineName,
    public readonly ExtensionType $type,
    public readonly ?bool $installed,
    public readonly string $package,
    public readonly string $path,
    public readonly ?string $baseTheme = NULL,
    public readonly ?bool $defaultTheme = FALSE,
    public readonly ?bool $adminTheme = FALSE,
  ) {}

  /**
   * Creates an extension from its array representation.
   *
   * @param array<string, mixed> $data
   *   The array representation.
   */
  public static function fromArray(array $data): self {
    return new self(
      $data['id'],
      $data['machineName'],
      ExtensionType::from($data['type']),
      $data['installed'],
      $data['package'],
      $data['path'],
      $data['baseTheme'] ?? NULL,
      array_key_exists('defaultTheme', $data) ? $data['defaultTheme'] : FALSE,
      array_key_exists('adminTheme', $data) ? $data['adminTheme'] : FALSE,
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
      'machineName' => $this->machineName,
      'type' => $this->type->value,
      'installed' => $this->installed,
      'package' => $this->package,
      'path' => $this->path,
    ];
    if ($this->type === ExtensionType::Theme) {
      $data += [
        'baseTheme' => $this->baseTheme,
        'defaultTheme' => $this->defaultTheme,
        'adminTheme' => $this->adminTheme,
      ];
    }
    return $data;
  }

}
