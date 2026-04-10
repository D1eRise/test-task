<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Delivery\RiskScoreCalculator;
use PHPUnit\Framework\TestCase;

class RiskScoreCalculatorTest extends TestCase
{
    public function testCalculatesBaselineRisk(): void
    {
        $calculator = new RiskScoreCalculator();

        $result = $calculator->calculate(false, false, 0, 10000, 'Z1', 2);

        self::assertSame(25, $result['score']);
        self::assertSame(25, $result['components']['base']);
    }

    public function testCalculatesHighRiskWithMultipleFactors(): void
    {
        $calculator = new RiskScoreCalculator();

        $result = $calculator->calculate(true, false, 12, 160000, 'Z3', 7);

        self::assertSame(75, $result['score']);
        self::assertSame(15, $result['components']['missing_phone']);
        self::assertSame(10, $result['components']['overdue']);
        self::assertSame(12, $result['components']['large_sum']);
        self::assertSame(8, $result['components']['zone_risk']);
        self::assertSame(5, $result['components']['eta_risk']);
    }

    public function testCapsOverdueContributionAtTwentyPoints(): void
    {
        $calculator = new RiskScoreCalculator();

        $result = $calculator->calculate(true, true, 24 * 30, 999999, 'Z7', 14);

        self::assertSame(95, $result['score']);
        self::assertSame(20, $result['components']['overdue']);
    }
}
