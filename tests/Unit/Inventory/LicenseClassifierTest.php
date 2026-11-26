<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use Sparkfabrik\DrupalSbom\Inventory\LicenseClassifier;
use Sparkfabrik\DrupalSbom\Model\License;
use Sparkfabrik\DrupalSbom\Model\LicenseClassification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests license classification and the SPDX expression grammar.
 */
#[CoversClass(LicenseClassifier::class)]
#[CoversClass(License::class)]
#[Group('drubom')]
final class LicenseClassifierTest extends TestCase {

  /**
   * Composer license values are classified and combined.
   *
   * @param list<string> $declared
   *   The declared values.
   * @param \Sparkfabrik\DrupalSbom\Model\LicenseClassification $classification
   *   The expected classification.
   * @param string|null $expression
   *   The expected expression.
   */
  #[DataProvider('composerProvider')]
  public function testComposer(array $declared, LicenseClassification $classification, ?string $expression): void {
    $license = LicenseClassifier::composer($declared, []);

    $this->assertSame($classification, $license->classification);
    $this->assertSame($expression, $license->expression);
  }

  /**
   * Provides Composer license values.
   *
   * @return array<string, array{list<string>, \Sparkfabrik\DrupalSbom\Model\LicenseClassification, string|null}>
   *   Declared values, classification and expression.
   */
  public static function composerProvider(): array {
    return [
      'single identifier' => [['MIT'], LicenseClassification::SpdxExpression, 'MIT'],
      'choice' => [['MIT', 'Apache-2.0'], LicenseClassification::SpdxExpression, 'MIT OR Apache-2.0'],
      'choice with compound term' => [
        ['MIT', 'GPL-2.0-only WITH Classpath-exception-2.0'],
        LicenseClassification::SpdxExpression,
        'MIT OR (GPL-2.0-only WITH Classpath-exception-2.0)',
      ],
      'empty' => [[], LicenseClassification::Missing, NULL],
      'blank' => [['  '], LicenseClassification::Missing, NULL],
      'proprietary' => [['proprietary'], LicenseClassification::NonStandard, NULL],
      'free text' => [['GPL v2 or later'], LicenseClassification::NonStandard, NULL],
    ];
  }

  /**
   * The grammar accepts valid expressions and rejects malformed ones.
   */
  #[DataProvider('expressionProvider')]
  public function testExpressionGrammar(string $expression, bool $valid): void {
    $this->assertSame($valid, License::isValidExpression($expression));
  }

  /**
   * Provides license expressions.
   *
   * @return array<string, array{string, bool}>
   *   Expression and validity.
   */
  public static function expressionProvider(): array {
    return [
      'identifier' => ['GPL-2.0-or-later', TRUE],
      'or later suffix' => ['GPL-2.0+', TRUE],
      'conjunction' => ['MIT AND BSD-3-Clause', TRUE],
      'nested' => ['(MIT OR Apache-2.0) AND BSD-2-Clause', TRUE],
      'exception' => ['GPL-2.0-only WITH Classpath-exception-2.0', TRUE],
      'license reference' => ['LicenseRef-Acme-1', TRUE],
      'dangling operator' => ['MIT AND', FALSE],
      'leading operator' => ['OR MIT', FALSE],
      'two identifiers' => ['MIT Apache-2.0', FALSE],
      'unbalanced' => ['(MIT OR Apache-2.0', FALSE],
      'empty group' => ['()', FALSE],
      'exception with suffix' => ['GPL-2.0 WITH Classpath-exception-2.0+', FALSE],
      'proprietary' => ['proprietary', FALSE],
      'invalid character' => ['MIT/X11', FALSE],
      'unknown identifier' => ['GNU-GPL-2.0-or-later', FALSE],
      'no assertion' => ['NOASSERTION', FALSE],
      'none' => ['NONE', FALSE],
      'reference named like a sentinel' => ['LicenseRef-NONE', TRUE],
      'sentinel in an expression' => ['MIT OR NOASSERTION', FALSE],
    ];
  }

}
