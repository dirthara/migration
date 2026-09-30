<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\PendingRollback;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRollbackException;

use function array_key_exists;

/**
 * @internal
 */
final readonly class RollbackPlanner
{
    public function __construct(
        private MigrationConnectionResolver $connections,
    ) {}

    /**
     * @param array<string, LoadedMigration> $migrations
     * @param list<AppliedMigration>         $applied
     *
     * @throws MigrationPlanException
     * @throws MigrationRollbackException
     *
     * @return list<PendingRollback>
     */
    public function plan(array $migrations, array $applied): array
    {
        $contexts = [];
        $rollbacks = [];

        foreach ($applied as $migration) {
            $source =
                $migrations[MigrationName::canonical($migration->name)] ?? throw MigrationRollbackException::missingSource(
                    $migration->name,
                    $migration->connection,
                    $migration->batch,
                );

            $this->reconcile($source, $migration);

            if (!array_key_exists($migration->connection, $contexts)) {
                $contexts[$migration->connection] = $this->connections->recorded($source, $migration->connection);
            }

            $rollbacks[] = new PendingRollback($source, $migration, $contexts[$migration->connection]);
        }

        return $rollbacks;
    }

    /**
     * @throws MigrationPlanException
     */
    private function reconcile(LoadedMigration $source, AppliedMigration $applied): void
    {
        if ($source->migration->index !== $applied->index) {
            throw MigrationPlanException::indexChanged(
                $source->migration->name,
                $source->path,
                $source->migration->index,
                $applied->index,
            );
        }

        $declared = $source->migration->connection;

        if ($declared !== null && $declared !== $applied->connection) {
            throw MigrationPlanException::connectionChanged(
                $source->migration->name,
                $source->path,
                $declared,
                $applied->connection,
            );
        }
    }
}
