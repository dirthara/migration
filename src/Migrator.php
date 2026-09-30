<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Throwable;
use Dirthara\Schema\Schema;
use Psr\Clock\ClockInterface;
use Dirthara\Database\Database;
use Dirthara\Migration\Contract\MigrationHooks;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\ValueObject\MigrationDecision;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;

/**
 * Runs the pending migrations of every configured directory as one batch.
 *
 * Migrations are ordered within a connection, by index and then by path. No execution order is guaranteed between
 * different connections, so a migration must not depend on one that runs on another connection.
 */
final readonly class Migrator
{
    private MigrationPlanner $planner;

    public function __construct(
        MigrationLoader $loader,
        private MigrationRepository $repository,
        Database $database,
        Schema $schema,
        private ClockInterface $clock,
    ) {
        $this->planner = new MigrationPlanner($loader, $repository, $database, $schema);
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    public function migrate(MigrationConfiguration $configuration): void
    {
        $plan = $this->planner->plan($configuration);

        if ($plan === []) {
            return;
        }

        $batch = $this->repository->getNextBatch();

        foreach ($plan as $migrations) {
            foreach ($migrations as $migration) {
                $action = $this->up($migration);

                if ($action === MigrationAction::Stop) {
                    return;
                }

                if ($action === MigrationAction::Continue) {
                    $this->record($migration, $batch);
                }
            }
        }
    }

    public function rollback(MigrationConfiguration $configuration): void
    {
        // ...
    }

    /**
     * @throws Throwable
     */
    private function up(PendingMigration $pending): MigrationAction
    {
        $migration = $pending->migration->migration;

        if (!$migration instanceof MigrationHooks) {
            $migration->up($pending->context);

            return MigrationAction::Continue;
        }

        $decision = MigrationDecision::continue();

        $migration->beforeUp($pending->context, $decision);

        if ($decision->decision !== MigrationAction::Continue) {
            return $decision->decision;
        }

        $migration->up($pending->context);
        $migration->afterUp($pending->context);

        return MigrationAction::Continue;
    }

    /**
     * @throws MigrationRepositoryException
     */
    private function record(PendingMigration $pending, int $batch): void
    {
        $migration = $pending->migration->migration;

        $this->repository->record(new AppliedMigration(
            name: $migration->name,
            index: $migration->index,
            description: $migration->description,
            connection: $pending->connection,
            batch: $batch,
            appliedAt: $this->clock->now(),
        ));
    }
}
