<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use Dirthara\Schema\Exceptions\SchemaException;
use Dirthara\Database\Exception\DatabaseException;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\PendingRollback;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\MigrationContext;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;

use function array_map;
use function array_merge;
use function array_key_exists;

/**
 * @internal
 */
final readonly class RollbackPlanner
{
    private MigrationSetValidator $validator;

    public function __construct(
        private MigrationLoader $loader,
        private Database $database,
        private Schema $schema,
    ) {
        $this->validator = new MigrationSetValidator();
    }

    /**
     * @param list<AppliedMigration> $applied
     *
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     * @throws MigrationRollbackException
     *
     * @return list<PendingRollback>
     */
    public function plan(MigrationConfiguration $configuration, array $applied): array
    {
        $loaded = $this->validator->validate(array_merge(...array_map(
            $this->loader->loadDirectory(...),
            $configuration->directories,
        )));

        $contexts = [];
        $rollbacks = [];

        foreach ($applied as $migration) {
            $source =
                $loaded[MigrationName::canonical($migration->name)] ?? throw MigrationRollbackException::missingSource(
                    $migration->name,
                    $migration->connection,
                    $migration->batch,
                );

            $this->reconcile($source, $migration);

            if (!array_key_exists($migration->connection, $contexts)) {
                $contexts[$migration->connection] = $this->context($source, $migration->connection);
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

    /**
     * @throws MigrationPlanException
     */
    private function context(LoadedMigration $source, string $connection): MigrationContext
    {
        try {
            return new MigrationContext(
                database: $this->database->using($connection),
                schema: $this->schema->using($connection),
            );
        } catch (DatabaseException|SchemaException $exception) {
            throw MigrationPlanException::connectionUnavailable(
                $source->migration->name,
                $source->path,
                $connection,
                previous: $exception,
            );
        }
    }
}
