<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Integration;

use PDO;
use DateTimeZone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Dirthara\Schema\ConnectedSchema;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Schema\Grammar\SchemaGrammar;
use Dirthara\Migration\MigrationRepository;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Database\Connection\PdoConnection;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Exception\NamedLockException;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Schema\Exception\InvalidSchemaException;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Database\Exception\UnsupportedLockException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;

use function getenv;
use function sprintf;
use function in_array;
use function array_map;
use function str_repeat;

/**
 * @require-extends TestCase
 */
trait MigrationRepositoryConformance
{
    private const string TABLE = 'conformance_migrations';

    private const string LOCK = 'dirthara:migrations';

    private ConnectedDatabase $database;

    private ConnectedSchema $schema;

    private MigrationRepository $repository;

    abstract protected function driverName(): DriverName;

    abstract protected function driver(): Driver;

    abstract protected function schemaGrammar(): SchemaGrammar;

    abstract protected function queryGrammar(): QueryGrammar;

    abstract protected function config(): ConnectionConfig;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->driverIsAvailable()) {
            self::markTestSkipped(sprintf('The %s PDO driver is not installed.', $this->driverName()->value));
        }

        $connection = new PdoConnection($this->config(), $this->driver());
        $this->database = new ConnectedDatabase($connection, $this->queryGrammar());
        $this->schema = new ConnectedSchema($connection, $this->schemaGrammar());
        $this->repository = new MigrationRepository($this->database, $this->schema, self::TABLE);

        $this->schema->dropIfExists(self::TABLE);
    }

    protected function tearDown(): void
    {
        if ($this->driverIsAvailable()) {
            $this->schema->dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    protected function requireNamedLocks(): void
    {
        if ($this->driver()->namedLockGrammar() === null) {
            self::markTestSkipped(sprintf('The %s driver does not support named locks.', $this->driverName()->value));
        }
    }

    protected function driverIsAvailable(): bool
    {
        return in_array($this->driverName()->value, PDO::getAvailableDrivers(), strict: true);
    }

    protected function env(string $name, string $fallback): string
    {
        $value = getenv($name);

        return $value === false ? $fallback : $value;
    }

    #[Test]
    public function it_creates_the_migration_table(): void
    {
        $this->repository->initialise();

        self::assertTrue($this->schema->hasTable(self::TABLE));
    }

    #[Test]
    public function it_reports_whether_the_migration_table_exists(): void
    {
        self::assertFalse($this->repository->exists());
        self::assertFalse($this->schema->hasTable(self::TABLE));

        $this->repository->initialise();

        self::assertTrue($this->repository->exists());
    }

    #[Test]
    public function it_drops_the_migration_table(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));

        $this->repository->drop();
        $this->repository->drop();

        self::assertFalse($this->schema->hasTable(self::TABLE));
    }

    #[Test]
    public function it_keeps_what_was_recorded_when_initialised_again(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));

        $this->repository->initialise();

        self::assertTrue($this->repository->hasRun('create_users'));
    }

    #[Test]
    public function it_records_every_detail_of_an_applied_migration(): void
    {
        $this->repository->initialise();

        $this->repository->record(new AppliedMigration(
            name: 'create_users',
            index: '20260101000000',
            description: 'Creates the users table',
            connection: 'default',
            batch: 3,
            appliedAt: new DateTimeImmutable('2026-01-01 12:30:45', new DateTimeZone('UTC')),
        ));

        $row = $this->row('create_users');

        self::assertSame('20260101000000', $row['index']);
        self::assertSame('Creates the users table', $row['description']);
        self::assertSame('default', $row['connection']);
        self::assertSame(3, (int) $row['batch']);
        self::assertSame('2026-01-01 12:30:45', $this->storedTime($row['applied_at']));
    }

    #[Test]
    public function it_records_a_migration_without_a_description(): void
    {
        $this->repository->initialise();

        $this->repository->record($this->applied('create_users', batch: 1));

        self::assertNull($this->row('create_users')['description']);
    }

    #[Test]
    public function it_records_a_description_longer_than_a_string_column(): void
    {
        $description = str_repeat('Creates the users table. ', times: 40);
        $this->repository->initialise();

        $this->repository->record($this->applied('create_users', batch: 1, description: $description));

        self::assertSame($description, $this->row('create_users')['description']);
    }

    #[Test]
    public function it_records_the_time_a_migration_was_applied_in_utc(): void
    {
        $this->repository->initialise();

        $this->repository->record($this->applied(
            'create_users',
            batch: 1,
            appliedAt: new DateTimeImmutable('2026-07-01 14:00:00', new DateTimeZone('Europe/Amsterdam')),
        ));

        self::assertSame('2026-07-01 12:00:00', $this->storedTime($this->row('create_users')['applied_at']));
    }

    #[Test]
    public function it_refuses_to_record_the_same_migration_twice(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));

        $exception = $this->failure(fn() => $this->repository->record($this->applied('create_users', batch: 2)));

        self::assertSame(
            'Unable to record migration "create_users" in the migration table "conformance_migrations".',
            $exception->getMessage(),
        );
        self::assertSame(['table' => self::TABLE, 'migration' => 'create_users'], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_create_the_migration_table(): void
    {
        $repository = new MigrationRepository($this->database, $this->schema, 'invalid table');

        $exception = $this->failure($repository->initialise(...));

        self::assertSame('Unable to create the migration table "invalid table".', $exception->getMessage());
        self::assertSame(['table' => 'invalid table'], $exception->context);
        self::assertInstanceOf(InvalidSchemaException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_check_whether_the_migration_table_exists(): void
    {
        $repository = new MigrationRepository($this->database, $this->schema, 'invalid table');

        $exception = $this->failure($repository->exists(...));

        self::assertSame(
            'Unable to check whether the migration table "invalid table" exists.',
            $exception->getMessage(),
        );
        self::assertSame(['table' => 'invalid table'], $exception->context);
        self::assertInstanceOf(InvalidSchemaException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_drop_the_migration_table(): void
    {
        $repository = new MigrationRepository($this->database, $this->schema, 'invalid table');

        $exception = $this->failure($repository->drop(...));

        self::assertSame('Unable to drop the migration table "invalid table".', $exception->getMessage());
        self::assertSame(['table' => 'invalid table'], $exception->context);
        self::assertInstanceOf(InvalidSchemaException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_record_a_migration(): void
    {
        $exception = $this->failure(fn() => $this->repository->record($this->applied('create_users', batch: 1)));

        self::assertSame(['table' => self::TABLE, 'migration' => 'create_users'], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_forget_a_migration(): void
    {
        $exception = $this->failure(fn() => $this->repository->forget('create_users'));

        self::assertSame(
            'Unable to remove migration "create_users" from the migration table "conformance_migrations".',
            $exception->getMessage(),
        );
        self::assertSame(['table' => self::TABLE, 'migration' => 'create_users'], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_check_whether_a_migration_has_run(): void
    {
        $exception = $this->failure(fn() => $this->repository->hasRun('create_users'));

        self::assertSame(
            'Unable to check whether migration "create_users" has run in the migration table "conformance_migrations".',
            $exception->getMessage(),
        );
        self::assertSame(['table' => self::TABLE, 'migration' => 'create_users'], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_determine_the_next_batch(): void
    {
        $exception = $this->failure($this->repository->getNextBatch(...));

        self::assertSame(
            'Unable to determine the next batch from the migration table "conformance_migrations".',
            $exception->getMessage(),
        );
        self::assertSame(['table' => self::TABLE], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_escapes_control_characters_in_a_failure_message(): void
    {
        $exception = $this->failure(fn() => $this->repository->hasRun("create_users\nforged line"));

        self::assertStringContainsString('"create_users\\nforged line"', $exception->getMessage());
        self::assertSame(['table' => self::TABLE, 'migration' => "create_users\nforged line"], $exception->context);
    }

    #[Test]
    public function it_reports_only_recorded_migrations_as_run(): void
    {
        $this->repository->initialise();

        $this->repository->record($this->applied('create_users', batch: 1));

        self::assertTrue($this->repository->hasRun('create_users'));
        self::assertFalse($this->repository->hasRun('create_posts'));
    }

    #[Test]
    public function it_forgets_only_the_given_migration(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));
        $this->repository->record($this->applied('create_posts', batch: 1));

        $this->repository->forget('create_users');

        self::assertFalse($this->repository->hasRun('create_users'));
        self::assertTrue($this->repository->hasRun('create_posts'));
    }

    #[Test]
    public function it_ignores_forgetting_a_migration_that_never_ran(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));

        $this->repository->forget('create_posts');

        self::assertTrue($this->repository->hasRun('create_users'));
    }

    #[Test]
    public function it_starts_with_the_first_batch(): void
    {
        $this->repository->initialise();

        self::assertSame(1, $this->repository->getNextBatch());
    }

    #[Test]
    public function it_reads_no_applied_migrations_from_an_empty_history(): void
    {
        $this->repository->initialise();

        self::assertSame([], $this->repository->getApplied());
    }

    #[Test]
    public function it_reads_every_applied_migration_in_the_order_it_was_recorded(): void
    {
        $users = new AppliedMigration(
            name: 'create_users',
            index: '2026_01_02_000000',
            description: 'Creates the users table',
            connection: 'default',
            batch: 1,
            appliedAt: new DateTimeImmutable('2026-01-02 08:00:00', new DateTimeZone('UTC')),
        );
        $posts = new AppliedMigration(
            name: 'create_posts',
            index: '2026_01_01_000000',
            description: null,
            connection: 'reporting',
            batch: 2,
            appliedAt: new DateTimeImmutable('2026-01-03 09:30:15', new DateTimeZone('UTC')),
        );
        $this->repository->initialise();
        $this->repository->record($users);
        $this->repository->record($posts);

        $applied = $this->repository->getApplied();

        self::assertEquals([$users, $posts], $applied);
        self::assertSame('UTC', $applied[0]->appliedAt->getTimezone()->getName());
    }

    #[Test]
    public function it_reads_the_time_a_migration_was_applied_back_in_utc(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied(
            'create_users',
            batch: 1,
            appliedAt: new DateTimeImmutable('2026-07-01 14:00:00', new DateTimeZone('Europe/Amsterdam')),
        ));

        $appliedAt = $this->repository->getApplied()[0]->appliedAt;

        self::assertSame('2026-07-01 12:00:00 UTC', $appliedAt->format('Y-m-d H:i:s T'));
    }

    #[Test]
    public function it_wraps_a_failure_to_read_the_applied_migrations(): void
    {
        $exception = $this->failure($this->repository->getApplied(...));

        self::assertSame(
            'Unable to read the applied migrations from the migration table "conformance_migrations".',
            $exception->getMessage(),
        );
        self::assertSame(['table' => self::TABLE], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_locks_migration_runs_against_other_sessions(): void
    {
        $this->requireNamedLocks();

        $other = new ConnectedDatabase(new PdoConnection($this->config(), $this->driver()), $this->queryGrammar());
        $lock = $this->repository->acquireLock();

        try {
            self::assertSame(self::LOCK, $lock->name);
            self::assertNull($other->tryAcquireLock(self::LOCK));
        } finally {
            $this->repository->releaseLock($lock);
        }

        $reacquired = $other->tryAcquireLock(self::LOCK);

        self::assertNotNull($reacquired);

        $reacquired->release();
        $other->connection()->disconnect();
    }

    #[Test]
    public function it_wraps_a_failure_to_release_the_migration_lock(): void
    {
        $this->requireNamedLocks();

        $lock = $this->repository->acquireLock();
        $this->repository->releaseLock($lock);

        try {
            $this->repository->releaseLock($lock);
            self::fail(sprintf('Releasing the lock twice did not throw %s.', MigrationLockException::class));
        } catch (MigrationLockException $exception) {
            self::assertSame(
                'Unable to release migration lock "dirthara:migrations" on connection "conformance".',
                $exception->getMessage(),
            );
            self::assertSame(['lock' => self::LOCK, 'connection' => 'conformance'], $exception->context);
            self::assertInstanceOf(NamedLockException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function it_refuses_to_lock_migration_runs_without_named_locks(): void
    {
        if ($this->driver()->namedLockGrammar() !== null) {
            self::markTestSkipped(sprintf('The %s driver supports named locks.', $this->driverName()->value));
        }

        try {
            $this->repository->acquireLock();
            self::fail(sprintf('Locking did not throw %s.', MigrationLockException::class));
        } catch (MigrationLockException $exception) {
            self::assertSame(['lock' => self::LOCK, 'connection' => 'conformance'], $exception->context);
            self::assertInstanceOf(UnsupportedLockException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function it_selects_nothing_to_roll_back_from_an_empty_history(): void
    {
        $this->repository->initialise();

        self::assertSame([], $this->repository->getLatestBatch());
        self::assertSame([], $this->repository->getLatest(1));
    }

    #[Test]
    public function it_selects_the_latest_batch_latest_applied_first(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));
        $this->repository->record($this->applied('create_posts', batch: 2));
        $this->repository->record($this->applied('create_comments', batch: 2));

        self::assertSame(['create_comments', 'create_posts'], $this->names($this->repository->getLatestBatch()));
    }

    #[Test]
    public function it_selects_the_latest_migrations_across_batches_latest_applied_first(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));
        $this->repository->record($this->applied('create_posts', batch: 1));
        $this->repository->record($this->applied('create_comments', batch: 2));

        self::assertSame(['create_comments'], $this->names($this->repository->getLatest(1)));
        self::assertSame(['create_comments', 'create_posts'], $this->names($this->repository->getLatest(2)));
        self::assertSame(
            ['create_comments', 'create_posts', 'create_users'],
            $this->names($this->repository->getLatest(10)),
        );
    }

    #[Test]
    public function it_wraps_a_failure_to_select_the_latest_batch(): void
    {
        $exception = $this->failure($this->repository->getLatestBatch(...));

        self::assertSame(['table' => self::TABLE], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_select_the_latest_migrations(): void
    {
        $exception = $this->failure(fn() => $this->repository->getLatest(1));

        self::assertSame(['table' => self::TABLE], $exception->context);
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_continues_after_the_highest_batch(): void
    {
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', batch: 1));
        $this->repository->record($this->applied('create_posts', batch: 2));
        $this->repository->record($this->applied('create_comments', batch: 2));

        self::assertSame(3, $this->repository->getNextBatch());
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failure(callable $operation): MigrationRepositoryException
    {
        try {
            $operation();
        } catch (MigrationRepositoryException $exception) {
            return $exception;
        }

        self::fail(sprintf('The operation did not throw %s.', MigrationRepositoryException::class));
    }

    /**
     * @param list<AppliedMigration> $migrations
     *
     * @return list<string>
     */
    private function names(array $migrations): array
    {
        return array_map(static fn(AppliedMigration $migration): string => $migration->name, $migrations);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name): array
    {
        $row = $this->database->table(self::TABLE)->where('name', '=', $name)->first();

        self::assertNotNull($row, sprintf('No migration named "%s" was recorded.', $name));

        return $row;
    }

    private function storedTime(mixed $value): string
    {
        self::assertIsString($value);

        return new DateTimeImmutable($value, new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function applied(
        string $name,
        int $batch,
        ?string $description = null,
        ?DateTimeImmutable $appliedAt = null,
    ): AppliedMigration {
        return new AppliedMigration(
            name: $name,
            index: '20260101000000',
            description: $description,
            connection: 'default',
            batch: $batch,
            appliedAt: $appliedAt ?? new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        );
    }
}
