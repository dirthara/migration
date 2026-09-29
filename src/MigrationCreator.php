<?php

declare(strict_types=1);

namespace Dirthara\Migration;

final readonly class MigrationCreator
{
    public function create(
        string $directory,
        string $index,
        string $name,
        string $description,
        ?string $connection = null,
    ): void {
        // ...
    }
}
