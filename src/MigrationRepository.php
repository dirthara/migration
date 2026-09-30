<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use DateTimeZone;
use DateTimeImmutable;
use Dirthara\Schema\Table;
use Dirthara\Schema\ConnectedSchema;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Schema\Exception\SchemaException;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Query\Sql\OrderDirection;
use Dirthara\Database\Connection\Lock\AcquiredLock;
use Dirthara\Database\Exception\NamedLockException;
use Dirthara\Database\Exception\ConnectionException;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Database\Exception\InvalidLockNameException;
use Dirthara\Database\Exception\UnsupportedLockException;
use Dirthara\Migration\Exception\MigrationRepositoryException;

use function substr;
use function array_map;
use function is_scalar;

final readonly class MigrationRepository
{
    private const string LOCK = 'dirthara:migrations';

    public function __construct(
        private ConnectedDatabase $database,
        private ConnectedSchema $schema,
        private string $migrationTableName,
    ) {}

    /**
     * @throws MigrationLockException
     */
    public function acquireLock(): AcquiredLock
    {
        try {
            return $this->database->acquireLock(self::LOCK);
        } catch (UnsupportedLockException $exception) {
            throw MigrationLockException::lockingUnsupported(self::LOCK, $this->connection(), previous: $exception);
        } catch (NamedLockException|ConnectionException|InvalidLockNameException $exception) {
            throw MigrationLockException::acquireFailed(self::LOCK, $this->connection(), previous: $exception);
        }
    }

    /**
     * @throws MigrationLockException
     */
    public function releaseLock(AcquiredLock $lock): void
    {
        try {
            $lock->release();
        } catch (NamedLockException $exception) {
            throw MigrationLockException::releaseFailed($lock->name, $this->connection(), previous: $exception);
        }
    }

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
    public function exists(): bool
    {
        try {
            return $this->schema->hasTable($this->migrationTableName);
        } catch (SchemaException $exception) {
            throw MigrationRepositoryException::inspectFailed($this->migrationTableName, previous: $exception);
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
     *
     * @return list<AppliedMigration>
     */
    public function getApplied(): array
    {
        try {
            $rows = $this->database->table($this->migrationTableName)->orderBy('id')->get();
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::readFailed($this->migrationTableName, previous: $exception);
        }

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @throws MigrationRepositoryException
     *
     * @return list<AppliedMigration>
     */
    public function getLatestBatch(): array
    {
        try {
            $batch = $this->database->table($this->migrationTableName)->max('batch');

            if ($batch === null) {
                return [];
            }

            $rows = $this->database
                ->table($this->migrationTableName)
                ->where('batch', '=', (int) $batch)
                ->orderBy('id', OrderDirection::Descending)
                ->get();
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::readFailed($this->migrationTableName, previous: $exception);
        }

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param positive-int $count
     *
     * @throws MigrationRepositoryException
     *
     * @return list<AppliedMigration>
     */
    public function getLatest(int $count): array
    {
        try {
            $rows = $this->database
                ->table($this->migrationTableName)
                ->orderBy('id', OrderDirection::Descending)
                ->limit($count)
                ->get();
        } catch (QueryException|ConnectionException $exception) {
            throw MigrationRepositoryException::readFailed($this->migrationTableName, previous: $exception);
        }

        return array_map($this->hydrate(...), $rows);
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

    /**
     * @param array<string, mixed> $row
     *
     * @throws MigrationRepositoryException
     */
    private function hydrate(array $row): AppliedMigration
    {
        return new AppliedMigration(
            name: $this->text($row, 'name'),
            index: $this->text($row, 'index'),
            description: ($row['description'] ?? null) === null ? null : $this->text($row, 'description'),
            connection: $this->text($row, 'connection'),
            batch: (int) $this->text($row, 'batch'),
            appliedAt: $this->appliedAt($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws MigrationRepositoryException
     */
    private function text(array $row, string $column): string
    {
        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;

        return is_scalar($value)
            ? (string) $value
            : throw MigrationRepositoryException::invalidRecord($this->migrationTableName, $column);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws MigrationRepositoryException
     */
    private function appliedAt(array $row): DateTimeImmutable
    {
        $appliedAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            substr($this->text($row, 'applied_at'), offset: 0, length: 19),
            new DateTimeZone('UTC'),
        );

        return $appliedAt === false
            ? throw MigrationRepositoryException::invalidRecord($this->migrationTableName, 'applied_at')
            : $appliedAt;
    }

    private function connection(): string
    {
        return $this->database->connection()->name();
    }
}
