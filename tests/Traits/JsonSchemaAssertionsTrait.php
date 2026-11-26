<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Traits;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates data against the module's JSON schemas.
 */
trait JsonSchemaAssertionsTrait {

  /**
   * Returns the validation errors of data against a schema file.
   *
   * @param mixed $data
   *   Decoded JSON data, or an array that is encoded and decoded first.
   * @param string $schemaFile
   *   Absolute path of the schema file.
   *
   * @return list<string>
   *   The errors, empty when the data is valid.
   */
  protected function jsonSchemaErrors(mixed $data, string $schemaFile): array {
    $schema = json_decode(file_get_contents($schemaFile), FALSE, 512, JSON_THROW_ON_ERROR);
    $validator = new Validator();
    $validator->setMaxErrors(20);
    $id = $schema->{'$id'} ?? 'file://' . realpath($schemaFile);
    $validator->resolver()->registerFile($id, $schemaFile);
    if (is_array($data)) {
      $data = json_decode(json_encode($data, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
    }
    $result = $validator->validate($data, $id);
    if ($result->isValid()) {
      return [];
    }
    $errors = [];
    foreach ((new ErrorFormatter())->format($result->error()) as $pointer => $messages) {
      foreach ($messages as $message) {
        $errors[] = $pointer . ': ' . $message;
      }
    }
    return $errors;
  }

  /**
   * Returns the official SPDX 3.0.1 JSON schema, downloaded once.
   *
   * The schema is not distributed with the module. Outside CI, a test that
   * needs it is skipped when it cannot be downloaded.
   */
  protected function spdxSchemaFile(): string {
    $digest = '582c64e809d5b3ef9bd0c4de13a32391b47b0284a3e8d199569fb96f649234b1';
    $file = sys_get_temp_dir() . '/drubom-spdx-3.0.1-' . $digest . '.json';
    if (!is_file($file)) {
      $schema = @file_get_contents('https://spdx.org/schema/3.0.1/spdx-json-schema.json', context: stream_context_create(['http' => ['timeout' => 30]]));
      if ($schema === FALSE) {
        if (getenv('CI')) {
          $this->fail('The SPDX schema could not be downloaded.');
        }
        $this->markTestSkipped('The SPDX schema could not be downloaded.');
      }
      $this->assertSame($digest, hash('sha256', $schema), 'The downloaded SPDX schema is the pinned one.');
      file_put_contents($file, $schema);
    }
    return $file;
  }

  /**
   * Asserts that data is valid against a schema file.
   */
  protected function assertMatchesJsonSchema(mixed $data, string $schemaFile): void {
    $this->assertSame([], $this->jsonSchemaErrors($data, $schemaFile));
  }

}
