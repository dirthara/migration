<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Closure;
use Throwable;
use Dirthara\Schema\Schema;
use Psr\Clock\ClockInterface;
use Dirthara\Database\Database;
use Dirthara\Migration\Contract\MigrationHooks;
use Dirthara\Migration\ValueObject\PendingRollback;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\ValueObject\MigrationDecision;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;
use Dirthara\Migration\Exception\InvalidRollbackStepsException;

final readonly class Migrator
{
    private MigrationPlanner $planner;

    private RollbackPlanner $rollbackPlanner;

    public function __construct(
        MigrationLoader $loader,
        private MigrationRepository $repository,
        Database $database,
        Schema $schema,
        private ClockInterface $clock,
    ) {
        $this->planner = new MigrationPlanner($loader, $repository, $database, $schema);
        $this->rollbackPlanner = new RollbackPlanner($loader, $database, $schema);
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationLockException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    public function migrate(MigrationConfiguration $configuration): void
    {
        $this->locked($configuration, fn() => $this->run($configuration));
    }

    /**
     * @throws InvalidRollbackStepsException
     * @throws InvalidMigrationFileException
     * @throws MigrationLockException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws MigrationRollbackException
     * @throws Throwable
     */
    public function rollback(MigrationConfiguration $configuration, ?int $steps = null): void
    {
        if ($steps !== null && $steps < 1) {
            throw InvalidRollbackStepsException::notPositive($steps);
        }

        $this->locked($configuration, fn() => $this->reverse($configuration, $steps));
    }

    /**
     * @param Closure(): void $operation
     *
     * @throws MigrationLockException
     * @throws Throwable
     */
    private function locked(MigrationConfiguration $configuration, Closure $operation): void
    {
        if (!$configuration->locking) {
            $operation();

            return;
        }

        $lock = $this->repository->acquireLock();

        try {
            $operation();
        } finally {
            $this->repository->releaseLock($lock);
        }
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    private function run(MigrationConfiguration $configuration): void
    {
        $plan = $this->planner->plan($configuration);

        if ($plan === []) {
            return;
        }

        $batch = $this->repository->getNextBatch();

        foreach ($plan as $migrations) {
            foreach ($migrations as $migration) {
                if ($this->up($migration, $batch) === MigrationAction::Stop) {
                    return;
                }
            }
        }
    }

    /**
     * @param positive-int|null $steps
     *
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws MigrationRollbackException
     * @throws Throwable
     */
    private function reverse(MigrationConfiguration $configuration, ?int $steps): void
    {
        $this->repository->initialise();

        $applied = $steps === null ? $this->repository->getLatestBatch() : $this->repository->getLatest($steps);

        if ($applied === []) {
            return;
        }

        foreach ($this->rollbackPlanner->plan($configuration, $applied) as $rollback) {
            if ($this->down($rollback) === MigrationAction::Stop) {
                return;
            }
        }
    }

    /**
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    private function up(PendingMigration $pending, int $batch): MigrationAction
    {
        $migration = $pending->migration->migration;

        if ($migration instanceof MigrationHooks) {
            $decision = MigrationDecision::continue();

            $migration->beforeUp($pending->context, $decision);

            if ($decision->decision !== MigrationAction::Continue) {
                return $decision->decision;
            }
        }

        $this->repository->record(new AppliedMigration(
            name: $migration->name,
            index: $migration->index,
            description: $migration->description,
            connection: $pending->connection,
            batch: $batch,
            appliedAt: $this->clock->now(),
        ));

        $completed = false;

        try {
            $migration->up($pending->context);

            if ($migration instanceof MigrationHooks) {
                $migration->afterUp($pending->context);
            }

            $completed = true;
        } finally {
            if (!$completed) {
                $this->repository->forget($migration->name);
            }
        }

        return MigrationAction::Continue;
    }

    /**
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    private function down(PendingRollback $pending): MigrationAction
    {
        $migration = $pending->migration->migration;

        if ($migration instanceof MigrationHooks) {
            $decision = MigrationDecision::continue();

            $migration->beforeDown($pending->context, $decision);

            if ($decision->decision !== MigrationAction::Continue) {
                return $decision->decision;
            }
        }

        $this->repository->forget($pending->applied->name);

        $completed = false;

        try {
            $migration->down($pending->context);

            if ($migration instanceof MigrationHooks) {
                $migration->afterDown($pending->context);
            }

            $completed = true;
        } finally {
            if (!$completed) {
                $this->repository->record($pending->applied);
            }
        }

        return MigrationAction::Continue;
    }
}
