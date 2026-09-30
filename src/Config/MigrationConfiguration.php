<?php

declare(strict_types=1);

namespace Dirthara\Migration\Config;

final readonly class MigrationConfiguration
{
    /**
     * @param list<string> $directories
     */
    public function __construct(
        public array $directories,
    ) {}
}
