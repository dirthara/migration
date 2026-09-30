<?php

declare(strict_types=1);

namespace Dirthara\Migration\Indexing;

use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class DefaultIndexingStrategy implements IndexingStrategy
{
    public function index(ClockInterface $clock, string $name): string
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y_m_d_His');
    }
}
