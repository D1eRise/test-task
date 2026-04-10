<?php

declare(strict_types=1);

namespace Tests\Support;

class FakeSleeper
{
    public array $delays = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}
