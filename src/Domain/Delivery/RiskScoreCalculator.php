<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

class RiskScoreCalculator
{
    public function calculate(
        bool $missingPhone,
        bool $missingEmail,
        float $overdueHours,
        float $sumProducts,
        string $deliveryZone,
        int $etaDays
    ): array
    {
        $components = [
            'base' => 25,
            'missing_phone' => $missingPhone ? 15 : 0,
            'missing_email' => $missingEmail ? 10 : 0,
            'overdue' => min(20, (int) floor(20 * ($overdueHours / 24))),
            'large_sum' => $sumProducts > 150000 ? 12 : 0,
            'zone_risk' => in_array($deliveryZone, ['Z3', 'Z7'], true) ? 8 : 0,
            'eta_risk' => $etaDays >= 7 ? 5 : 0,
        ];

        $score = array_sum($components);
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'components' => $components,
        ];
    }
}
