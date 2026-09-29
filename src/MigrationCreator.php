<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Psr\Clock\ClockInterface;
use League\Flysystem\FilesystemWriter;

final readonly class MigrationCreator
{
    public function __construct(
        private ClockInterface $clock,
        private FilesystemWriter $filesystem,
    ) {}

    public function create(string $directory, string $name, string $description, ?string $connection = null): void
    {
        // ...
    }
}
