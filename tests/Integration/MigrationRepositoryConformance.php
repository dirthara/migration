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
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Schema\Exceptions\InvalidSchemaException;
use Dirthara\Database\Connection\Exceptions\QueryException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;

use function getenv;
use function sprintf;
use function in_array;
use function str_repeat;

/**
 * @require-extends TestCase
 */
trait MigrationRepositoryConformance
{
    private const string TABLE = 'conformance_migrations';

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
