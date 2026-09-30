<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class CountingClock implements ClockInterface
{
    public private(set) int $reads = 0;

    public function __construct(
        private readonly DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        $this->reads++;

        return $this->now;
    }
}
