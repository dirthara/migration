<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Closure;
use Throwable;
use Dirthara\Schema\Schema;
use Psr\Clock\ClockInterface;
use Dirthara\Database\Database;
use Dirthara\Database\Connection\Lock\AcquiredLock;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\PendingRollback;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\MigrationPreview;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRefreshException;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;
use Dirthara\Migration\Exception\InvalidRollbackStepsException;

use function array_map;
use function array_reverse;

final readonly class Migrator
{
    private MigrationSetLoader $migrations;

    private MigrationConnectionResolver $connections;

    private MigrationPlanner $planner;

    private RollbackPlanner $rollbackPlanner;

    private MigrationRunner $runner;

    public function __construct(
        MigrationLoader $loader,
        private MigrationRepository $repository,
        Database $database,
        Schema $schema,
        ClockInterface $clock,
    ) {
        $this->migrations = new MigrationSetLoader($loader);
        $this->connections = new MigrationConnectionResolver($database, $schema);
        $this->planner = new MigrationPlanner($this->connections);
        $this->rollbackPlanner = new RollbackPlanner($this->connections);
        $this->runner = new MigrationRunner($repository, $clock);
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
        $this->locked($configuration, fn() => $this->run($this->migrations->load($configuration)));
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
        $steps = $this->steps($steps);

        $this->locked($configuration, function () use ($configuration, $steps): void {
            $this->repository->initialise();

            $applied = $this->select($steps);

            if ($applied === []) {
                return;
            }

            $this->runner->reverse($this->rollbackPlanner->plan($this->migrations->load($configuration), $applied));
        });
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationLockException
     * @throws MigrationPlanException
     * @throws MigrationRefreshException
     * @throws MigrationRepositoryException
     * @throws MigrationRollbackException
     * @throws Throwable
     */
    public function refresh(MigrationConfiguration $configuration): void
    {
        $this->locked($configuration, function () use ($configuration): void {
            $migrations = $this->migrations->load($configuration);
            $this->connections->declared($migrations);

            $this->repository->initialise();
            $this->runner->reverse($this->rollbackPlanner->plan(
                $migrations,
                array_reverse($this->repository->getApplied()),
            ));

            $remaining = $this->repository->getApplied();

            if ($remaining !== []) {
                throw MigrationRefreshException::rollbackIncomplete(array_map(
                    static fn(AppliedMigration $migration): string => $migration->name,
                    $remaining,
                ));
            }

            $this->run($migrations);
        });
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     *
     * @return list<MigrationPreview>
     */
    public function preview(MigrationConfiguration $configuration): array
    {
        $migrations = $this->migrations->load($configuration);
        $recorded = $this->repository->exists();

        $plan = $this->planner->plan($migrations, $recorded ? $this->repository->getApplied() : []);
        $batch = $recorded ? $this->repository->getNextBatch() : 1;
        $previews = [];

        foreach ($plan as $pending) {
            foreach ($pending as $migration) {
                $previews[] = $this->previewUp($migration, $batch);
            }
        }

        return $previews;
    }

    /**
     * @throws InvalidRollbackStepsException
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws MigrationRollbackException
     *
     * @return list<MigrationPreview>
     */
    public function previewRollback(MigrationConfiguration $configuration, ?int $steps = null): array
    {
        $steps = $this->steps($steps);

        if (!$this->repository->exists()) {
            return [];
        }

        $applied = $this->select($steps);

        if ($applied === []) {
            return [];
        }

        return array_map(
            $this->previewDown(...),
            $this->rollbackPlanner->plan($this->migrations->load($configuration), $applied),
        );
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
        $completed = false;

        try {
            $operation();

            $completed = true;
        } finally {
            if (!$completed) {
                $this->releaseAfterFailure($lock);
            }
        }

        $this->repository->releaseLock($lock);
    }

    private function releaseAfterFailure(AcquiredLock $lock): void
    {
        try {
            $this->repository->releaseLock($lock);
        } catch (MigrationLockException) {
            return;
        }
    }

    /**
     * @throws InvalidRollbackStepsException
     *
     * @return positive-int|null
     */
    private function steps(?int $steps): ?int
    {
        if ($steps !== null && $steps < 1) {
            throw InvalidRollbackStepsException::notPositive($steps);
        }

        return $steps;
    }

    /**
     * @param positive-int|null $steps
     *
     * @throws MigrationRepositoryException
     *
     * @return list<AppliedMigration>
     */
    private function select(?int $steps): array
    {
        return $steps === null ? $this->repository->getLatestBatch() : $this->repository->getLatest($steps);
    }

    /**
     * @param array<string, LoadedMigration> $migrations
     *
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    private function run(array $migrations): void
    {
        $this->repository->initialise();

        $plan = $this->planner->plan($migrations, $this->repository->getApplied());

        if ($plan === []) {
            return;
        }

        $this->runner->apply($plan, $this->repository->getNextBatch());
    }

    private function previewUp(PendingMigration $pending, int $batch): MigrationPreview
    {
        $migration = $pending->migration->migration;

        return new MigrationPreview(
            name: $migration->name,
            index: $migration->index,
            description: $migration->description,
            connection: $pending->connection,
            direction: MigrationDirection::Up,
            batch: $batch,
            path: $pending->migration->path,
        );
    }

    private function previewDown(PendingRollback $pending): MigrationPreview
    {
        return new MigrationPreview(
            name: $pending->applied->name,
            index: $pending->applied->index,
            description: $pending->applied->description,
            connection: $pending->applied->connection,
            direction: MigrationDirection::Down,
            batch: $pending->applied->batch,
            path: $pending->migration->path,
        );
    }
}
