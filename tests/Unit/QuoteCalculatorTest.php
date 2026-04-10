<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integration\Courier\QuoteCalculator;
use PHPUnit\Framework\TestCase;

class QuoteCalculatorTest extends TestCase
{
    public function testCalculatesEtaUsingDirectoryAndSurcharges(): void
    {
        $calculator = QuoteCalculator::fromFile(dirname(__DIR__, 2) . '/resources/fixtures/zones.php');

        $quote = $calculator->calculate([
            'city_code_to' => 'KZ-ALA-01',
            'weight_kg' => 15.0,
            'declared_value' => 250000.0,
            'trace_id' => 'trace-1',
        ]);

        self::assertSame('Z3', $quote['zone']);
        self::assertSame(6, $quote['base_eta_days']);
        self::assertSame(9, $quote['eta_days']);
        self::assertSame(1, $quote['rules']['weight_surcharge_days']);
        self::assertSame(2, $quote['rules']['declared_value_surcharge_days']);
    }

    public function testClampsEtaToFourteenDays(): void
    {
        $rows = [
            ['city_code' => 'TEST-001', 'zone' => 'Z9', 'base_eta_days' => 14],
        ];
        $calculator = new QuoteCalculator($rows);

        $quote = $calculator->calculate([
            'city_code_to' => 'TEST-001',
            'weight_kg' => 15.0,
            'declared_value' => 250000.0,
            'trace_id' => 'trace-2',
        ]);

        self::assertSame(14, $quote['eta_days']);
    }
}
