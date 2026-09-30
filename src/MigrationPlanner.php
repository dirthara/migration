<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Exception\MigrationPlanException;

use function ksort;
use function usort;
use function strcmp;
use function array_map;
use function array_key_exists;

use const SORT_STRING;

/**
 * Compares a loaded migration set with the migration history and plans the migrations that have not run, grouped per
 * connection in the order they run in. It reads nothing itself: the caller decides where the history comes from, so a
 * run can create the history table first and a preview can leave a missing one alone.
 *
 * @internal
 */
final readonly class MigrationPlanner
{
    public function __construct(
        private MigrationConnectionResolver $connections,
    ) {}

    /**
     * @param array<string, LoadedMigration> $migrations
     * @param list<AppliedMigration>         $history
     *
     * @throws MigrationPlanException
     *
     * @return array<string, list<PendingMigration>>
     */
    public function plan(array $migrations, array $history): array
    {
        $applied = $this->applied($history);
        $contexts = $this->connections->declared($migrations);
        $groups = [];

        foreach ($migrations as $name => $migration) {
            $context = $contexts[$name];
            $connection = $context->database->connection()->name();

            if (array_key_exists($name, $applied)) {
                $this->reconcile($migration, $connection, $applied[$name]);

                continue;
            }

            $groups[$connection][] = new PendingMigration($migration, $connection, $context);
        }

        ksort($groups, SORT_STRING);

        return array_map($this->sort(...), $groups);
    }

    /**
     * @param list<AppliedMigration> $history
     *
     * @throws MigrationPlanException
     *
     * @return array<string, AppliedMigration>
     */
    private function applied(array $history): array
    {
        $applied = [];

        foreach ($history as $migration) {
            $name = MigrationName::canonical($migration->name);

            if (array_key_exists($name, $applied)) {
                throw MigrationPlanException::conflictingHistory($applied[$name]->name, $migration->name);
            }

            $applied[$name] = $migration;
        }

        return $applied;
    }

    /**
     * @throws MigrationPlanException
     */
    private function reconcile(LoadedMigration $migration, string $connection, AppliedMigration $applied): void
    {
        if ($migration->migration->index !== $applied->index) {
            throw MigrationPlanException::indexChanged(
                $migration->migration->name,
                $migration->path,
                $migration->migration->index,
                $applied->index,
            );
        }

        if ($migration->migration->connection !== null && $connection !== $applied->connection) {
            throw MigrationPlanException::connectionChanged(
                $migration->migration->name,
                $migration->path,
                $connection,
                $applied->connection,
            );
        }
    }

    /**
     * @param list<PendingMigration> $migrations
     *
     * @return list<PendingMigration>
     */
    private function sort(array $migrations): array
    {
        usort($migrations, static function (PendingMigration $first, PendingMigration $second): int {
            $order = strcmp($first->migration->migration->index, $second->migration->migration->index);

            return $order !== 0 ? $order : strcmp($first->migration->path, $second->migration->path);
        });

        return $migrations;
    }
}
