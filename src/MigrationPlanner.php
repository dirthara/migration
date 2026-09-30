<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use Dirthara\Schema\Exceptions\SchemaException;
use Dirthara\Database\Exception\DatabaseException;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\MigrationContext;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;

use function ksort;
use function usort;
use function strcmp;
use function array_map;
use function array_merge;
use function array_key_exists;

use const SORT_STRING;

/**
 * @internal
 */
final readonly class MigrationPlanner
{
    private const string DEFAULT_CONNECTION = '';

    private MigrationSetValidator $validator;

    public function __construct(
        private MigrationLoader $loader,
        private MigrationRepository $repository,
        private Database $database,
        private Schema $schema,
    ) {
        $this->validator = new MigrationSetValidator();
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     *
     * @return array<string, list<PendingMigration>>
     */
    public function plan(MigrationConfiguration $configuration): array
    {
        $loaded = array_merge(...array_map($this->loader->loadDirectory(...), $configuration->directories));

        $this->validator->validate($loaded);

        $this->repository->initialise();

        $applied = $this->applied();

        $contexts = $this->contexts($loaded);
        $groups = [];

        foreach ($loaded as $migration) {
            $context = $contexts[$migration->migration->connection ?? self::DEFAULT_CONNECTION];
            $connection = $context->database->connection()->name();

            $name = MigrationName::canonical($migration->migration->name);

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
     * @throws MigrationPlanException
     * @throws MigrationRepositoryException
     *
     * @return array<string, AppliedMigration>
     */
    private function applied(): array
    {
        $applied = [];

        foreach ($this->repository->getApplied() as $migration) {
            $name = MigrationName::canonical($migration->name);

            if (array_key_exists($name, $applied)) {
                throw MigrationPlanException::conflictingHistory($applied[$name]->name, $migration->name);
            }

            $applied[$name] = $migration;
        }

        return $applied;
    }

    /**
     * @param list<LoadedMigration> $loaded
     *
     * @throws MigrationPlanException
     *
     * @return array<string, MigrationContext>
     */
    private function contexts(array $loaded): array
    {
        $contexts = [];
        $resolved = [];

        foreach ($loaded as $migration) {
            $declared = $migration->migration->connection;
            $key = $declared ?? self::DEFAULT_CONNECTION;

            if (array_key_exists($key, $contexts)) {
                continue;
            }

            try {
                $database = $this->database->using($declared);
                $connection = $database->connection()->name();

                $resolved[$connection] ??= new MigrationContext(
                    database: $database,
                    schema: $this->schema->using($connection),
                );
                $contexts[$key] = $resolved[$connection];
            } catch (DatabaseException|SchemaException $exception) {
                throw MigrationPlanException::connectionUnavailable(
                    $migration->migration->name,
                    $migration->path,
                    $declared,
                    previous: $exception,
                );
            }
        }

        return $contexts;
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
