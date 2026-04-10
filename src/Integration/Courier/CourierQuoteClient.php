<?php

declare(strict_types=1);

namespace App\Integration\Courier;

use App\App\JsonLogger;
use App\Integration\Http\CurlHttpClient;

class CourierQuoteClient
{
    public function __construct(
        private readonly CurlHttpClient $httpClient,
        private readonly JsonLogger $logger,
        private readonly \Closure $sleepMilliseconds,
        private readonly string $endpoint,
        private readonly int $timeoutMs,
        private readonly int $maxRetries = 3
    ) {
    }

    public function quote(array $request): array
    {
        $lastException = null;
        $traceId = (string) ($request['trace_id'] ?? '');
        $body = [
            'city_code_to' => (string) ($request['city_code_to'] ?? ''),
            'weight_kg' => (float) ($request['weight_kg'] ?? 0),
            'declared_value' => (float) ($request['declared_value'] ?? 0),
        ];

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->httpClient->send(
                    'POST',
                    $this->endpoint,
                    [
                        'Content-Type' => 'application/json',
                        'X-Trace-Id' => $traceId,
                    ],
                    json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $this->timeoutMs
                );

                if ($response['status_code'] >= 500) {
                    throw new \RuntimeException('Временная ошибка courier mock: HTTP ' . $response['status_code']);
                }

                if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
                    throw new \RuntimeException('Сервис courier mock отклонил запрос: HTTP ' . $response['status_code']);
                }

                 
                $payload = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
                $payload['zone'] = (string) ($payload['zone'] ?? '');
                $payload['eta_days'] = (int) ($payload['eta_days'] ?? 0);
                $payload['base_eta_days'] = (int) ($payload['base_eta_days'] ?? 0);

                return $payload;
            } catch (\Throwable $exception) {
                $lastException = $exception;

                $this->logger->warning('Попытка получить расчёт доставки завершилась ошибкой.', [
                    'attempt' => $attempt,
                    'trace_id' => $traceId,
                    'error' => $exception->getMessage(),
                ]);

                if ($attempt === $this->maxRetries) {
                    break;
                }

                $delay = (100 * (2 ** ($attempt - 1))) + random_int(10, 90);
                ($this->sleepMilliseconds)($delay);
            }
        }

        throw new \RuntimeException('Не удалось получить расчёт доставки после повторных попыток.', 0, $lastException);
    }
}
