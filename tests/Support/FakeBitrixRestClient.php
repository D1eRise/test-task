<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integration\Bitrix\BitrixRestClient;

class FakeBitrixRestClient extends BitrixRestClient
{
    public array $calls = [];

    public function __construct(
        private readonly \Closure $handler,
        private readonly bool $applicationAuth = true
    )
    {
    }

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
        ];

        $result = ($this->handler)($method, $params, $this->calls);

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function callRaw(string $method, array $params = []): array
    {
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
        ];

        $result = ($this->handler)($method, $params, $this->calls);

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function batch(array $commands): array
    {
        $this->calls[] = [
            'method' => 'batch',
            'params' => $commands,
        ];

        $result = ($this->handler)('batch', $commands, $this->calls);

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function usesApplicationAuth(): bool
    {
        return $this->applicationAuth;
    }
}
