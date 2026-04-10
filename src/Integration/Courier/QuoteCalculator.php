<?php

declare(strict_types=1);

namespace App\Integration\Courier;

class QuoteCalculator
{
    private array $zones;

    public function __construct(array $rows)
    {
        $this->zones = [];

        foreach ($rows as $row) {
            $cityCode = (string) ($row['city_code'] ?? '');

            $this->zones[$cityCode] = [
                'city_code' => $cityCode,
                'zone' => (string) ($row['zone'] ?? ''),
                'base_eta_days' => (int) ($row['base_eta_days'] ?? 0),
            ];
        }
    }

    public static function fromFile(string $filePath): self
    {
         
        $rows = require $filePath;

        return new self($rows);
    }

    public function calculate(array $request): array
    {
        $zone = $this->zoneByCityCode((string) ($request['city_code_to'] ?? ''));
        $etaDays = (int) $zone['base_eta_days'];
        $weightSurcharge = (float) ($request['weight_kg'] ?? 0) > 12 ? 1 : 0;
        $valueSurcharge = (float) ($request['declared_value'] ?? 0) > 200000 ? 2 : 0;
        $etaDays += $weightSurcharge + $valueSurcharge;
        $etaDays = max(1, min(14, $etaDays));

        return [
            'city_code_to' => (string) ($request['city_code_to'] ?? ''),
            'zone' => (string) $zone['zone'],
            'base_eta_days' => (int) $zone['base_eta_days'],
            'eta_days' => $etaDays,
            'rules' => [
                'weight_surcharge_days' => $weightSurcharge,
                'declared_value_surcharge_days' => $valueSurcharge,
            ],
        ];
    }

    private function zoneByCityCode(string $cityCode): array
    {
        if (!isset($this->zones[$cityCode])) {
            throw new \RuntimeException(sprintf('Неизвестный код города доставки "%s".', $cityCode));
        }

        return $this->zones[$cityCode];
    }
}
