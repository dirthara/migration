<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use DateTimeZone;
use Dirthara\Schema\Table;
use Dirthara\Schema\ConnectedSchema;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Schema\Exceptions\SchemaException;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Database\Connection\Exceptions\QueryException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Database\Connection\Exceptions\ConnectionException;

final readonly class MigrationRepository
{
    public function __construct(
        private ConnectedDatabase $database,
        private ConnectedSchema $schema,
        private string $migrationTableName,
    ) {}

    /**
     * @throws MigrationRepositoryException
     */
    public function initialise(): void
    {
        try {
            $this->schema->createIfNotExists($this->migrationTableName, static function (Table $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('index');
                $table->text('description')->nullable();
                $table->string('connection');
                $table->integer('batch')->unsigned();
                $table->dateTime('applied_at');

                $table->index('batch');
                $table->index('applied_at');
                $table->index('index');
            });
        } catch (SchemaException $exception) {
            throw MigrationRepositoryException::initialiseFailed($this->migrationTableName, previous: $exception);
        }
    }

    /**
     * @throws MigrationRepositoryException
     */
    public function record(AppliedMigration $migration): void
    {
        try {
            $this->database
                ->table($this->migrationTableName)
                ->insert([
                    'name' => $migration->name,
                    'index' => $migration->index,
                    'description' => $migration->description,
                    'connection' => $migration->connection,
                    'batch' => $migration->batch,
                    'applied_at' => $migration->appliedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ]);
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::recordFailed(
                $this->migrationTableName,
                $migration->name,
                previous: $exception,
            );
        }
    }

    /**
     * @throws MigrationRepositoryException
     */
    public function forget(string $name): void
    {
        try {
            $this->database->table($this->migrationTableName)->where('name', '=', $name)->delete();
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::forgetFailed($this->migrationTableName, $name, previous: $exception);
        }
    }

    /**
     * @throws MigrationRepositoryException
     */
    public function hasRun(string $name): bool
    {
        try {
            return $this->database->table($this->migrationTableName)->where('name', '=', $name)->exists();
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::lookupFailed($this->migrationTableName, $name, previous: $exception);
        }
    }

    /**
     * @throws MigrationRepositoryException
     */
    public function getNextBatch(): int
    {
        try {
            $batch = $this->database->table($this->migrationTableName)->max('batch');
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::nextBatchFailed($this->migrationTableName, previous: $exception);
        }

        return $batch === null ? 1 : (int) $batch + 1;
    }
}
