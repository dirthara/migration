<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use Dirthara\Migration\Contract\Migration;

final readonly class LoadedMigration
{
    public function __construct(
        public Migration $migration,
        public string $path,
    ) {}
}
