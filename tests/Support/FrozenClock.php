<?php

declare(strict_types=1);

namespace Tests\Support;

class FrozenClock
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
