<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use Dirthara\Migration\MigrationDirection;

/**
 * One migration that an operation would attempt, in the order it would attempt it. A preview going up describes a
 * pending migration by its source, on the connection it resolves to now, with the batch a run would record it in. A
 * preview going down describes an applied migration as the history recorded it, on the connection it ran on, with
 * the batch it was applied in.
 */
final readonly class MigrationPreview
{
    public function __construct(
        public string $name,
        public string $index,
        public ?string $description,
        public string $connection,
        public MigrationDirection $direction,
        public int $batch,
        public string $path,
    ) {}
}
