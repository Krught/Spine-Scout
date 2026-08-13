<?php

declare(strict_types=1);

namespace App\Tests\Integration\MyAnonamouse;

use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use PHPUnit\Framework\TestCase;

final class MyAnonamouseConfigTest extends TestCase
{
    public function testDefaultsLeaveBothWedgeKnobsOff(): void
    {
        $config = MyAnonamouseConfig::default();

        self::assertFalse($config->alwaysUseWedge);
        self::assertNull($config->autoWedgeMinGb);
    }

    public function testWedgeDecisionNeverSpendsOnAnAlreadyFreeRelease(): void
    {
        // alreadyFree beats both "always" and a met threshold.
        $config = new MyAnonamouseConfig(alwaysUseWedge: true, autoWedgeMinGb: 1.0);

        self::assertFalse($config->wedgeDecision(5 * 1024 ** 3, true));
        self::assertFalse($config->wedgeDecision(null, true));
    }

    public function testWedgeDecisionAlwaysWinsOverThresholdAndUnknownSize(): void
    {
        $config = new MyAnonamouseConfig(alwaysUseWedge: true);

        self::assertTrue($config->wedgeDecision(null, false));
        self::assertTrue($config->wedgeDecision(1, false));

        // "Always" spends even when a threshold exists and the release is below it.
        $withThreshold = new MyAnonamouseConfig(alwaysUseWedge: true, autoWedgeMinGb: 100.0);
        self::assertTrue($withThreshold->wedgeDecision(1024, false));
    }

    public function testWedgeDecisionThresholdBoundary(): void
    {
        $config = new MyAnonamouseConfig(autoWedgeMinGb: 2.0);
        $threshold = (int) (2.0 * 1024 ** 3);

        self::assertFalse($config->wedgeDecision($threshold - 1, false));
        self::assertTrue($config->wedgeDecision($threshold, false));
        self::assertTrue($config->wedgeDecision($threshold + 1, false));
    }

    public function testWedgeDecisionHandlesFractionalGbThreshold(): void
    {
        $config = new MyAnonamouseConfig(autoWedgeMinGb: 0.5);

        self::assertTrue($config->wedgeDecision(512 * 1024 ** 2, false));
        self::assertFalse($config->wedgeDecision(512 * 1024 ** 2 - 1, false));
    }

    public function testWedgeDecisionFalseWhenSizeUnknownOrThresholdUnset(): void
    {
        // Threshold set but size unknown — can't apply it.
        self::assertFalse((new MyAnonamouseConfig(autoWedgeMinGb: 1.0))->wedgeDecision(null, false));
        // No threshold, no "always" — nothing triggers, whatever the size.
        self::assertFalse(MyAnonamouseConfig::default()->wedgeDecision(PHP_INT_MAX, false));
        self::assertFalse(MyAnonamouseConfig::default()->wedgeDecision(null, false));
    }

    public function testConstructorClampsZeroOrNegativeThresholdToNull(): void
    {
        self::assertNull((new MyAnonamouseConfig(autoWedgeMinGb: 0.0))->autoWedgeMinGb);
        self::assertNull((new MyAnonamouseConfig(autoWedgeMinGb: -3.5))->autoWedgeMinGb);
        self::assertSame(0.1, (new MyAnonamouseConfig(autoWedgeMinGb: 0.1))->autoWedgeMinGb);
    }

    public function testWedgeFieldsRoundTripThroughToArrayAndFromArray(): void
    {
        $config = new MyAnonamouseConfig(alwaysUseWedge: true, autoWedgeMinGb: 2.5);

        $rehydrated = MyAnonamouseConfig::fromArray($config->toArray());

        self::assertTrue($rehydrated->alwaysUseWedge);
        self::assertSame(2.5, $rehydrated->autoWedgeMinGb);
    }

    public function testFromArrayDefaultsAndClampsWedgeFields(): void
    {
        // Older stored blobs predate the keys entirely.
        $legacy = MyAnonamouseConfig::fromArray(['baseUrl' => 'https://example.test']);
        self::assertFalse($legacy->alwaysUseWedge);
        self::assertNull($legacy->autoWedgeMinGb);

        self::assertNull(MyAnonamouseConfig::fromArray(null)->autoWedgeMinGb);
        self::assertFalse(MyAnonamouseConfig::fromArray(null)->alwaysUseWedge);

        // Junk stored values clamp back to "off".
        self::assertNull(MyAnonamouseConfig::fromArray(['autoWedgeMinGb' => 0])->autoWedgeMinGb);
        self::assertNull(MyAnonamouseConfig::fromArray(['autoWedgeMinGb' => -1])->autoWedgeMinGb);
        self::assertNull(MyAnonamouseConfig::fromArray(['autoWedgeMinGb' => 'junk'])->autoWedgeMinGb);
        self::assertSame(4.0, MyAnonamouseConfig::fromArray(['autoWedgeMinGb' => '4'])->autoWedgeMinGb);
    }

    public function testToArrayPersistsWedgeDefaults(): void
    {
        $blob = MyAnonamouseConfig::default()->toArray();

        self::assertFalse($blob['alwaysUseWedge']);
        self::assertNull($blob['autoWedgeMinGb']);
    }
}
