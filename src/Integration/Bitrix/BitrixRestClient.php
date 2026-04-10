<?php

declare(strict_types=1);

namespace App\Integration\Bitrix;

use App\App\JsonLogger;
use App\Integration\Http\CurlHttpClient;

class BitrixRestClient
{
    private int $nextAllowedAtMs = 0;

     
    private readonly \Closure $nowMs;

     
    private readonly \Closure $jitterMs;

    private readonly string $rateLimitStateFile;

    public function __construct(
        private readonly CurlHttpClient $httpClient,
        private readonly \Closure $sleepMilliseconds,
        private readonly JsonLogger $logger,
        private readonly string $baseUrl,
        private readonly int $requestsPerSecond = 2,
        private readonly int $maxRetries = 5,
        private readonly int $baseDelayMs = 350,
        private readonly int $timeoutMs = 3000,
        ?\Closure $nowMs = null,
        ?\Closure $jitterMs = null,
        ?string $rateLimitStateFile = null,
        private readonly string $accessToken = ''
    ) {
        $this->nowMs = $nowMs ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->jitterMs = $jitterMs ?? static fn (int $attempt): int => random_int(20, 120);
        $this->rateLimitStateFile = $rateLimitStateFile
            ?? sys_get_temp_dir() . '/bitrix-rest-rate-' . sha1($this->baseUrl . '|' . ($this->accessToken !== '' ? 'app' : 'webhook')) . '.state';
    }

    public function call(string $method, array $params = []): array
    {
        return $this->extractResult($this->callRaw($method, $params));
    }

    public function callRaw(string $method, array $params = []): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->waitForRateLimitSlot();

                return $this->sendOnceRaw($method, $params);
            } catch (BitrixApiException $e) {
                $lastError = $e;

                if (!$e->retryable() || $attempt === $this->maxRetries) {
                    throw $e;
                }

                $delay = ($this->baseDelayMs * (2 ** ($attempt - 1))) + ($this->jitterMs)($attempt);

                $this->logger->warning('Запланирован повторный вызов Bitrix REST.', [
                    'method' => $method,
                    'attempt' => $attempt,
                    'delay_ms' => $delay,
                    'retryable' => $e->retryable(),
                    'rate_limited' => $e->rateLimited(),
                    'http_status' => $e->httpStatus(),
                    'error' => $e->getMessage(),
                ]);

                ($this->sleepMilliseconds)($delay);
            }
        }

        throw new BitrixApiException(
            sprintf('Вызов Bitrix REST "%s" не удался после повторных попыток.', $method),
            false,
            false,
            $lastError?->httpStatus(),
            $lastError
        );
    }

    public function batch(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        if (count($commands) > 50) {
            throw new \InvalidArgumentException('Пакетный запрос Bitrix ограничен 50 командами.');
        }

        foreach ($commands as $command) {
            $text = ltrim((string) $command);

            if (str_starts_with($text, 'batch') || str_contains($text, '/batch')) {
                throw new \InvalidArgumentException('Вложенный Bitrix batch не поддерживается.');
            }
        }

        $payload = $this->call('batch', [
            'halt' => 0,
            'cmd' => $commands,
        ]);

        if (isset($payload['result']) && is_array($payload['result'])) {
            return $payload['result'];
        }

        return $payload;
    }

    public function usesApplicationAuth(): bool
    {
        return $this->accessToken !== '';
    }

    private function sendOnceRaw(string $method, array $params): array
    {
        if (trim($this->baseUrl) === '') {
            throw new \RuntimeException(
                $this->accessToken !== ''
                    ? 'Не задан BITRIX_APP_REST_URL для app-auth вызовов Bitrix.'
                    : 'Не задан BITRIX_WEBHOOK_URL для вызовов Bitrix REST.'
            );
        }

        $url = rtrim($this->baseUrl, '/') . '/' . $method . '.json';
        $bodyParams = $params;

        if ($this->accessToken !== '' && !isset($bodyParams['auth'])) {
            $bodyParams['auth'] = $this->accessToken;
        }

        try {
            $response = $this->httpClient->send(
                'POST',
                $url,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query($bodyParams),
                $this->timeoutMs
            );
        } catch (\Throwable $e) {
            throw new BitrixApiException('HTTP-запрос к Bitrix завершился ошибкой: ' . $e->getMessage(), true, false, null, $e);
        }

        if (in_array($response['status_code'], [429, 503], true)) {
            throw new BitrixApiException(
                'Достигнут лимит Bitrix REST: HTTP ' . $response['status_code'],
                true,
                true,
                $response['status_code']
            );
        }

        if ($response['status_code'] >= 500) {
            throw new BitrixApiException(
                'Временная ошибка Bitrix REST: HTTP ' . $response['status_code'],
                true,
                false,
                $response['status_code']
            );
        }

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new BitrixApiException(
                'Bitrix REST отклонил вызов: HTTP ' . $response['status_code'],
                false,
                false,
                $response['status_code']
            );
        }

        try {
             
            $payload = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new BitrixApiException('Bitrix вернул невалидный JSON.', false, false, $response['status_code'], $e);
        }

        if (isset($payload['error'])) {
            $code = (string) $payload['error'];
            $description = (string) ($payload['error_description'] ?? $code);

            if ($code === 'QUERY_LIMIT_EXCEEDED' || str_contains($description, 'QUERY_LIMIT_EXCEEDED')) {
                throw new BitrixApiException($description, true, true, $response['status_code']);
            }

            throw new BitrixApiException($description, false, false, $response['status_code']);
        }

        return $payload;
    }

    private function extractResult(array $payload): array
    {
        if (isset($payload['result']) && is_array($payload['result'])) {
            return $payload['result'];
        }

        return $payload;
    }

    private function waitForRateLimitSlot(): void
    {
        if ($this->requestsPerSecond < 1) {
            return;
        }

        $intervalMs = (int) ceil(1000 / $this->requestsPerSecond);
        $dir = dirname($this->rateLimitStateFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $handle = @fopen($this->rateLimitStateFile, 'c+');

        if ($handle === false) {
            $this->waitForLocalRateLimitSlot($intervalMs);

            return;
        }

        $now = ($this->nowMs)();
        $slotAt = $now;
        $locked = false;

        try {
            $locked = flock($handle, LOCK_EX);

            if (!$locked) {
                throw new \RuntimeException('Не удалось заблокировать файл состояния лимита Bitrix.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $nextAllowedAtMs = is_string($raw) && trim($raw) !== '' ? (int) trim($raw) : 0;
            $slotAt = max($now, $nextAllowedAtMs);
            $newNextAllowedAtMs = $slotAt + $intervalMs;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $newNextAllowedAtMs);
            fflush($handle);
        } catch (\Throwable) {
            if ($locked) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);
            $this->waitForLocalRateLimitSlot($intervalMs);

            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($slotAt > $now) {
            ($this->sleepMilliseconds)($slotAt - $now);
        }
    }

    private function waitForLocalRateLimitSlot(int $intervalMs): void
    {
        $now = ($this->nowMs)();

        if ($this->nextAllowedAtMs > $now) {
            ($this->sleepMilliseconds)($this->nextAllowedAtMs - $now);
            $now = $this->nextAllowedAtMs;
        }

        $this->nextAllowedAtMs = $now + $intervalMs;
    }
}
