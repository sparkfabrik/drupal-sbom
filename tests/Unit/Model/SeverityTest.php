<?php

declare(strict_types=1);

namespace Sparkfabrik\DrupalSbom\Tests\Unit\Model;

use Sparkfabrik\DrupalSbom\Model\Severity;
use Sparkfabrik\DrupalSbom\Model\SeverityLabel;
use Sparkfabrik\DrupalSbom\Model\SeveritySystem;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared qualitative severity interpretation.
 */
#[CoversClass(Severity::class)]
#[Group('drubom')]
final class SeverityTest extends TestCase {

  /**
   * CVSS supplies missing labels and never reduces a higher supplied one.
   */
  public function testQualitativeScore(): void {
    $this->assertSame(SeverityLabel::Critical, (new Severity(SeveritySystem::CvssV3, 'example', score: 9.8))->qualitative());
    $this->assertSame(SeverityLabel::Critical, (new Severity(SeveritySystem::CvssV3, 'example', score: 9.8, label: SeverityLabel::Low))->qualitative());
    $this->assertSame(SeverityLabel::Critical, (new Severity(SeveritySystem::CvssV4, 'example', score: 4.0, label: SeverityLabel::Critical))->qualitative());
    foreach ([
      0 => SeverityLabel::None,
      1 => SeverityLabel::Low,
      4 => SeverityLabel::Medium,
      7 => SeverityLabel::High,
      9 => SeverityLabel::Critical,
    ] as $score => $label) {
      $this->assertSame($label, (new Severity(SeveritySystem::CvssV3, 'example', score: (float) $score))->qualitative());
    }
    $this->assertSame(SeverityLabel::High, (new Severity(SeveritySystem::CvssV2, 'example', score: 9.8))->qualitative());
    foreach ([-1.0, 11.0, NAN, INF] as $score) {
      $this->assertSame(SeverityLabel::Unknown, (new Severity(SeveritySystem::CvssV3, 'example', score: $score))->qualitative());
    }
  }

}
