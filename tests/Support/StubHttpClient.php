<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integration\Http\CurlHttpClient;

class StubHttpClient extends CurlHttpClient
{
    public array $requests = [];

    public function __construct(private array $responses)
    {
    }

    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutMs = 2000
    ): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout_ms' => $timeoutMs,
        ];

        if ($this->responses === []) {
            throw new \RuntimeException('Больше нет настроенных stub-ответов.');
        }

        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
