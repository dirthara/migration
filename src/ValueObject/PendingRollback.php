<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

/**
 * @internal
 */
final readonly class PendingRollback
{
    public function __construct(
        public LoadedMigration $migration,
        public AppliedMigration $applied,
        public MigrationContext $context,
    ) {}
}
