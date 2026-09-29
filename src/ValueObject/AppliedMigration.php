<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use DateTimeImmutable;

final readonly class AppliedMigration
{
    public function __construct(
        public string $name,
        public string $index,
        public ?string $description,
        public string $connection,
        public int $batch,
        public DateTimeImmutable $appliedAt,
    ) {}
}
