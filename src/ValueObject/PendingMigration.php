<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

/**
 * @internal
 */
final readonly class PendingMigration
{
    public function __construct(
        public LoadedMigration $migration,
        public string $connection,
        public MigrationContext $context,
    ) {}
}
