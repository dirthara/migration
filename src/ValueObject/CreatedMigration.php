<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

final readonly class CreatedMigration
{
    public function __construct(
        public string $name,
        public string $index,
        public ?string $description,
        public ?string $connection,
        public string $path,
    ) {}
}
