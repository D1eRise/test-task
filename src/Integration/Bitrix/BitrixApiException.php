<?php

declare(strict_types=1);

namespace App\Integration\Bitrix;

class BitrixApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        private readonly bool $rateLimited = false,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function rateLimited(): bool
    {
        return $this->rateLimited;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
