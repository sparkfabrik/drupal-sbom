# drupal-sbom

Builds a software bill of materials (SBOM) of a Drupal codebase as an [SPDX 3.0.1](https://spdx.github.io/spdx-spec/v3.0.1/) JSON-LD document, without running any code of the codebase.

It is the inventory engine of [DruBOM](https://www.drupal.org/project/drubom), extracted so that other tools can use it outside a Drupal site: DruBOM's module and scanner, and any agent that needs the dependency inventory of a Drupal site.

## What it reads

- **Composer:** `composer.json`, `composer.lock` and `vendor/composer/installed.json`.
- **Drupal:** the `*.info.yml` files of modules, themes and profiles, and the `*.libraries.yml` files.
- **npm, Yarn and pnpm** lockfiles of themes and modules.
- **PHP runtime:** the version and extensions of the PHP running the scan, unless it is skipped.

It never loads `settings.php`, `vendor/autoload.php` or any other PHP file of the codebase, and never connects to a database. Sources it cannot read in full are listed as coverage gaps in the document.

Without `vendor/`, the scan works from `composer.lock` and the document is typed `source`; with it, `build`.

## Requirements

PHP 8.1 or later. The codebase can be any Drupal from 8 to 12.

## Command line

```shell
composer require sparkfabrik/drupal-sbom
vendor/bin/drupal-sbom --root /path/to/project --skip php --output sbom.spdx.json
```

- `--root` is the Composer project root to inspect.
- `--web` sets the web root, relative to the project root, when it is not the Drupal scaffold web root.
- `--skip php` leaves out the PHP runtime, when the scan does not run on the site's PHP.
- `--include-dev` keeps packages used only for development.

Exit codes: `0` written; `2` written, but a source could not be fully read; `1` failed, nothing written.

## As a library

```php
use Sparkfabrik\DrupalSbom\Scan\ScanCommand;

$code = ScanCommand::run(['--root', $projectRoot, '--skip', 'php', '--output', $file]);
```

The collectors, the inventory builder and the SPDX formatter are also usable on their own, from the `Sparkfabrik\DrupalSbom\Inventory` and `Sparkfabrik\DrupalSbom\SbomFormatter` namespaces.

## What a scan does not know

A scan sees only files. It cannot tell which extensions are enabled or which PHP serves the site, and it does not check vulnerabilities: give the document to a tool that reads SPDX 3.0.1, or use DruBOM on the site.

Both a scan and DruBOM describe package metadata. Neither proves that the files on disk are unmodified.

## Development

```shell
composer install
composer qa
```

`composer qa` runs the code style check (Drupal coding standards), PHPStan and the tests, as CI does on PHP 8.1 to 8.4.

## Releases

Add the version's section to `CHANGELOG.md`, then push a tag such as `0.1.0`. The release workflow publishes the GitHub release with that section as its notes.

## Maintainers

Developed by [SparkFabrik](https://www.sparkfabrik.com/). Maintainers: Paolo Mainardi and Marco Primitivo.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
