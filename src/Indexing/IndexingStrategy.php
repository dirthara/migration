<?php

declare(strict_types=1);

namespace Dirthara\Migration\Indexing;

use Psr\Clock\ClockInterface;

interface IndexingStrategy
{
    public function index(ClockInterface $clock, string $name): string;
}
