<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Throwable;
use Psr\Clock\ClockInterface;
use Dirthara\Migration\Contract\MigrationHooks;
use Dirthara\Migration\ValueObject\PendingRollback;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\ValueObject\MigrationDecision;
use Dirthara\Migration\Exception\MigrationRepositoryException;

/**
 * Runs planned migrations and rollbacks one at a time, asks their hooks whether to continue, and keeps the history in
 * step with each of them. It neither plans nor locks: the migrator does both, so that one lock can cover every phase
 * of an operation.
 *
 * @internal
 */
final readonly class MigrationRunner
{
    public function __construct(
        private MigrationRepository $repository,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<string, list<PendingMigration>> $plan
     *
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    public function apply(array $plan, int $batch): void
    {
        foreach ($plan as $pending) {
            foreach ($pending as $migration) {
                if ($this->up($migration, $batch) === MigrationAction::Stop) {
                    return;
                }
            }
        }
    }

    /**
     * @param list<PendingRollback> $rollbacks
     *
     * @throws MigrationRepositoryException
     * @throws Throwable
     */
    public function reverse(array $rollbacks): void
    {
        foreach ($rollbacks as $rollback) {
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
