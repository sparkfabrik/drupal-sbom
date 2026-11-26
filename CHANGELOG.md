# Changelog

All notable changes to this project are documented in this file, following [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- The SPDX formatter and the scanner take the producer name and version, so documents name the tool that made them; the standalone scanner writes `drupal-sbom`.
- The library collector accepts a YAML decoder, so a running site can read library files with Drupal's own decoder.
- CI validates SPDX documents against the schema and SHACL, and tests `symfony/yaml` 7 and 8.

### Changed

- The SPDX formatter defaults to the `drupal-sbom` producer and IRI prefix instead of `DruBOM`; pass `DruBOM` to keep the previous output.
- The library collector no longer reads the global `Drupal::VERSION`; discovery adapters must report libraries without their own version as `VERSION`.

## [0.1.1] - 2026-11-26

### Fixed

- Allow `symfony/yaml` 8, which Drupal 12 requires.

## [0.1.0] - 2026-11-26

### Added

- SPDX 3.0.1 inventory of a Drupal codebase from its Composer, Drupal and npm metadata, extracted from DruBOM 1.0.0-beta4.
- `drupal-sbom` command line scanner.
- Support for PHP 8.1 and later.
- CI with code style, static analysis and tests on PHP 8.1 to 8.4.

[Unreleased]: https://github.com/sparkfabrik/drupal-sbom/compare/0.1.1...HEAD
[0.1.1]: https://github.com/sparkfabrik/drupal-sbom/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/sparkfabrik/drupal-sbom/releases/tag/0.1.0
