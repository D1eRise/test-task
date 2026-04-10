<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\App\JsonLogger;
use App\Integration\Courier\CourierQuoteClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSleeper;
use Tests\Support\FrozenClock;
use Tests\Support\StubHttpClient;

class CourierQuoteClientTest extends TestCase
{
    public function testRetriesTemporaryErrorsAndKeepsTraceId(): void
    {
        $http = new StubHttpClient([
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(200, json_encode([
                'zone' => 'Z3',
                'eta_days' => 7,
                'base_eta_days' => 6,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $sleeper = new FakeSleeper();
        $client = new CourierQuoteClient(
            $http,
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            $sleeper->sleepMilliseconds(...),
            'http://courier.test/v1/quote',
            1500
        );

        $quote = $client->quote([
            'city_code_to' => 'KZ-ALA-01',
            'weight_kg' => 15,
            'declared_value' => 210000,
            'trace_id' => 'trace-123',
        ]);

        self::assertSame('Z3', $quote['zone']);
        self::assertSame(7, $quote['eta_days']);
        self::assertCount(2, $http->requests);
        self::assertSame('trace-123', $http->requests[0]['headers']['X-Trace-Id'] ?? null);
        self::assertCount(1, $sleeper->delays);
    }

    public function testFailsAfterRetryLimit(): void
    {
        $http = new StubHttpClient([
            $this->response(503, json_encode(['error' => 'busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(503, json_encode(['error' => 'still_busy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $this->response(503, json_encode(['error' => 'nope'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $sleeper = new FakeSleeper();
        $client = new CourierQuoteClient(
            $http,
            new JsonLogger((new FrozenClock(new \DateTimeImmutable('2026-04-07T12:00:00+03:00')))->now(...)),
            $sleeper->sleepMilliseconds(...),
            'http://courier.test/v1/quote',
            1500,
            3
        );

        try {
            $client->quote([
                'city_code_to' => 'RU-MOW-77',
                'weight_kg' => 4,
                'declared_value' => 12000,
                'trace_id' => 'trace-456',
            ]);
            self::fail('Ожидалось исключение RuntimeException, но оно не было выброшено.');
        } catch (\RuntimeException $e) {
            self::assertSame('Не удалось получить расчёт доставки после повторных попыток.', $e->getMessage());
            self::assertCount(2, $sleeper->delays);
            self::assertCount(3, $http->requests);
        }
    }

    private function response(int $statusCode, string $body, array $headers = []): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
            'headers' => $headers,
        ];
    }
}
