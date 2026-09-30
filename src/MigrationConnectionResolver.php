<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use Dirthara\Schema\Exception\SchemaException;
use Dirthara\Database\Exception\DatabaseException;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\ValueObject\MigrationContext;
use Dirthara\Migration\Exception\MigrationPlanException;

use function array_key_exists;

/**
 * Resolves the connection a migration runs on into the context it receives. A forward run resolves the connection a
 * migration declares, or the default connection; a rollback resolves the connection the history recorded. Migrations
 * whose declarations resolve to the same connection share one context.
 *
 * @internal
 */
final readonly class MigrationConnectionResolver
{
    private const string DEFAULT_CONNECTION = '';

    public function __construct(
        private Database $database,
        private Schema $schema,
    ) {}

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, LoadedMigration> $migrations
     *
     * @throws MigrationPlanException
     *
     * @return array<TKey, MigrationContext>
     */
    public function declared(array $migrations): array
    {
        $declarations = [];
        $connections = [];
        $contexts = [];

        foreach ($migrations as $key => $migration) {
            $declared = $migration->migration->connection;
            $declaration = $declared ?? self::DEFAULT_CONNECTION;

            if (!array_key_exists($declaration, $declarations)) {
                $context = $this->resolve($migration, $declared);
                $connection = $context->database->connection()->name();

                $connections[$connection] ??= $context;
                $declarations[$declaration] = $connections[$connection];
            }

            $contexts[$key] = $declarations[$declaration];
        }

        return $contexts;
    }

    /**
     * @throws MigrationPlanException
     */
    public function recorded(LoadedMigration $migration, string $connection): MigrationContext
    {
        return $this->resolve($migration, $connection);
    }

    /**
     * @throws MigrationPlanException
     */
    private function resolve(LoadedMigration $migration, ?string $connection): MigrationContext
    {
        try {
            $database = $this->database->using($connection);

            return new MigrationContext(
                database: $database,
                schema: $this->schema->using($database->connection()->name()),
            );
        } catch (DatabaseException|SchemaException $exception) {
            throw MigrationPlanException::connectionUnavailable(
                $migration->migration->name,
                $migration->path,
                $connection,
                previous: $exception,
            );
        }
    }
}
