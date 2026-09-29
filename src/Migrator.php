<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Migration\Config\MigrationConfiguration;

final readonly class Migrator
{
    public function migrate(MigrationConfiguration $configuration): void
    {
        // ...
    }

    public function rollback(MigrationConfiguration $configuration): void
    {
        // ...
    }
}
