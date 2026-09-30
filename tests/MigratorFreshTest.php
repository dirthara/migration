<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use Closure;
use Throwable;
use DateTimeZone;
use RuntimeException;
use DateTimeImmutable;
use Dirthara\Schema\Table;
use PHPUnit\Framework\TestCase;
use Dirthara\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use Dirthara\Migration\Tests\Fixtures\MigrationLog;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Schema\Exception\SchemaExecutionException;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\MigrationFreshException;
use Dirthara\Migration\Tests\Fixtures\LockingSQLiteDriver;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Migration\Exception\InvalidMigrationFileException;
use Dirthara\Migration\Tests\Fixtures\ScriptedNamedLockGrammar;

use function count;
use function explode;
use function sprintf;
use function array_keys;
use function var_export;
use function array_slice;
use function array_filter;
use function str_contains;
use function str_starts_with;

final class MigratorFreshTest extends TestCase
{
    use MigrationEnvironment;

    private const string LOCK = 'dirthara:migrations';

    private const string TABLES = 'SELECT "m"."name" AS "name", "f"."foreign_keys" AS "foreign_keys"';

    private ScriptedNamedLockGrammar $locks;

    private LockingSQLiteDriver $driver;

    private int $statementsBefore = 0;

    private int $queriesBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locks = new ScriptedNamedLockGrammar();
        $this->driver = new LockingSQLiteDriver($this->locks);
        $this->setUpEnvironment($this->driver);
        MigrationLog::$events = [];
    }

    #[Test]
    public function it_drops_every_table_and_runs_every_migration_again(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->addTable('users/b.php', 'CreatePosts', '2026_01_02_000000', 'posts');
        $this->migrateBeforehand();
        $this->createTable('legacy_accounts');

        $this->fresh();

        self::assertSame(['CreateUsers:up:primary', 'CreatePosts:up:primary'], MigrationLog::$events);
        self::assertTrue($this->schema->hasTable('users'));
        self::assertTrue($this->schema->hasTable('posts'));
        self::assertFalse($this->schema->hasTable('legacy_accounts'));
    }

    #[Test]
    public function it_drops_a_table_a_migration_left_behind_without_history(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->createTable('users');
        $this->createTable('orphans');

        $this->fresh();

        self::assertSame(['CreateUsers:up:primary'], MigrationLog::$events);
        self::assertFalse($this->schema->hasTable('orphans'));
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_runs_no_rollback_code(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: "MigrationLog::record('up');",
            beforeUp: "MigrationLog::record('beforeUp');",
            afterUp: "MigrationLog::record('afterUp');",
            down: "MigrationLog::record('down');",
            beforeDown: "MigrationLog::record('beforeDown');",
            afterDown: "MigrationLog::record('afterDown');",
        );
        $this->migrateBeforehand();

        $this->fresh();

        self::assertSame(['beforeUp', 'up', 'afterUp'], MigrationLog::$events);
    }

    #[Test]
    public function it_runs_migrations_fresh_even_when_a_rollback_would_fail(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: $this->create('users') . $this->log('CreateUsers'),
            down: "throw new RuntimeException('Broken rollback');",
        );
        $this->migrateBeforehand();

        $this->fresh();

        self::assertSame(['CreateUsers:up:primary'], MigrationLog::$events);
        self::assertTrue($this->schema->hasTable('users'));
    }

    #[Test]
    public function it_records_a_new_history_in_the_first_batch(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users', description: 'Creates the users');
        $this->migrateBeforehand();
        $this->addTable('users/b.php', 'CreatePosts', '2026_01_02_000000', 'posts');
        $this->migrate();
        $this->repository->record($this->applied('RemovedMigration', '2025_01_01_000000', batch: 2));

        $this->fresh();

        self::assertEquals(
            [
                $this->applied('CreateUsers', '2026_01_01_000000', batch: 1, description: 'Creates the users'),
                $this->applied('CreatePosts', '2026_01_02_000000', batch: 1),
            ],
            $this->repository->getApplied(),
        );
    }

    #[Test]
    public function it_resets_every_connection_the_migrations_run_on(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->addTable('reports/a.php', 'CreateReports', '2026_01_02_000000', 'reports', connection: 'reporting');
        $this->migrateBeforehand(['users', 'reports']);
        $this->createTable('legacy_accounts');
        $this->createTable('legacy_reports', 'reporting');

        $this->fresh(['users', 'reports']);

        self::assertSame(['primary' => 1, 'reporting' => 1], $this->resets());
        self::assertFalse($this->schema->hasTable('legacy_accounts'));
        self::assertFalse($this->schema->hasTable('legacy_reports', 'reporting'));
        self::assertTrue($this->schema->hasTable('reports', 'reporting'));
        self::assertSame(['CreateUsers' => 'primary', 'CreateReports' => 'reporting'], $this->connections());
    }

    #[Test]
    public function it_resets_a_connection_once_whichever_way_its_migrations_name_it(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->addTable('users/b.php', 'CreatePosts', '2026_01_02_000000', 'posts', connection: 'primary');
        $this->migrateBeforehand();

        $this->fresh();

        self::assertSame(['primary' => 1], $this->resets());
        self::assertSame(['CreateUsers:up:primary', 'CreatePosts:up:primary'], MigrationLog::$events);
    }

    #[Test]
    public function it_leaves_a_configured_connection_without_migrations_alone(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();
        $this->createTable('reports', 'reporting');

        $this->fresh();

        self::assertSame(['primary' => 1], $this->resets());
        self::assertTrue($this->schema->hasTable('reports', 'reporting'));
    }

    #[Test]
    public function it_clears_a_history_kept_on_a_connection_it_does_not_reset(): void
    {
        $this->addTable('reports/a.php', 'CreateReports', '2026_01_01_000000', 'reports', connection: 'reporting');
        $this->migrateBeforehand(['reports']);
        $this->repository->record($this->applied('RemovedReport', '2025_01_01_000000', 1, connection: 'reporting'));
        $this->createTable('accounts');

        $this->fresh(['reports']);

        self::assertSame(['reporting' => 1], $this->resets());
        self::assertTrue($this->schema->hasTable('accounts'));
        self::assertEquals(
            [$this->applied('CreateReports', '2026_01_01_000000', 1, connection: 'reporting')],
            $this->repository->getApplied(),
        );
    }

    #[Test]
    public function it_only_clears_the_history_without_migrations(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();

        $this->fresh([]);

        self::assertSame([], $this->resets());
        self::assertTrue($this->schema->hasTable('users'));
        self::assertTrue($this->schema->hasTable(self::HISTORY_TABLE));
        self::assertSame([], $this->repository->getApplied());
    }

    #[Test]
    public function it_drops_nothing_from_an_invalid_migration_set(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();
        $this->addTable('billing/a.php', 'createusers', '2026_01_02_000000', 'invoices');

        $this->expectFailure(MigrationPlanException::class, fn() => $this->fresh(['users', 'billing']));

        $this->assertNothingDropped();
    }

    #[Test]
    public function it_drops_nothing_when_a_migration_file_is_invalid(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();
        $this->files['users/b.php'] = "<?php\n\nreturn 42;\n";

        $this->expectFailure(InvalidMigrationFileException::class, $this->fresh(...));

        $this->assertNothingDropped();
    }

    #[Test]
    public function it_drops_nothing_when_a_migration_names_a_connection_that_is_not_configured(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();
        $this->addTable('users/b.php', 'CreateArchive', '2026_01_02_000000', 'archive', connection: 'archive');

        $exception = $this->expectFailure(MigrationPlanException::class, $this->fresh(...));

        self::assertSame(
            ['migration' => 'CreateArchive', 'path' => 'users/b.php', 'connection' => 'archive'],
            $exception->context,
        );
        $this->assertNothingDropped();
    }

    #[Test]
    public function it_holds_one_lock_from_the_first_reset_to_the_last_migration(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: "MigrationLog::record(implode(',', \$context->database->connection()->locks()->held()));",
        );
        $this->migrateBeforehand();

        $this->fresh();

        $queries = $this->queries();
        $locking = array_keys(array_filter($queries, static fn(string $query): bool => str_contains(
            $query,
            ScriptedNamedLockGrammar::GRANTED,
        )));

        self::assertSame([self::LOCK], MigrationLog::$events);
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        self::assertSame([0, count($queries) - 1], $locking);
        self::assertSame(['primary' => 1], $this->resets());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_a_connection_cannot_be_reset(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->addTable('reports/a.php', 'CreateReports', '2026_01_02_000000', 'reports', connection: 'reporting');
        $this->migrateBeforehand(['users', 'reports']);
        $this->driver->failingQuery = 'reporting: DROP';

        $exception = $this->expectFailure(MigrationFreshException::class, fn() => $this->fresh(['users', 'reports']));

        self::assertSame(
            'Unable to run the migrations fresh: the tables of connection "reporting" could not be dropped.',
            $exception->getMessage(),
        );
        self::assertSame(['connection' => 'reporting'], $exception->context);
        self::assertInstanceOf(SchemaExecutionException::class, $exception->getPrevious());
        self::assertFalse($this->schema->hasTable('users'));
        self::assertTrue($this->schema->hasTable('reports', 'reporting'));
        self::assertSame([], MigrationLog::$events);
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_a_migration_fails_to_run_again(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();
        $this->addMigration(
            'users/b.php',
            'CreatePosts',
            '2026_01_02_000000',
            up: "throw new RuntimeException('Migration failed');",
        );

        $exception = $this->expectFailure(RuntimeException::class, $this->fresh(...));

        self::assertSame('Migration failed', $exception->getMessage());
        self::assertSame(['CreateUsers' => 1], $this->batches());
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_takes_no_lock_when_locking_is_disabled(): void
    {
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrateBeforehand();

        $this->fresh(locking: false);

        self::assertSame([], $this->lockStatements());
        self::assertSame(['primary' => 1], $this->resets());
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_runs_migrations_fresh_on_sqlite_only_with_locking_disabled(): void
    {
        $this->setUpEnvironment();
        $this->addTable('users/a.php', 'CreateUsers', '2026_01_01_000000', 'users');
        $this->migrate(locking: false);
        $this->createTable('legacy_accounts');

        $this->expectFailure(MigrationLockException::class, $this->fresh(...));

        self::assertTrue($this->schema->hasTable('legacy_accounts'));

        $this->fresh(locking: false);

        self::assertFalse($this->schema->hasTable('legacy_accounts'));
        self::assertTrue($this->schema->hasTable('users'));
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    private function addTable(
        string $path,
        string $name,
        string $index,
        string $table,
        ?string $connection = null,
        ?string $description = null,
    ): void {
        $this->addMigration(
            $path,
            $name,
            $index,
            connection: $connection,
            description: $description,
            up: $this->create($table) . $this->log($name),
            down: sprintf('$context->schema->dropIfExists(%s);', var_export($table, return: true)),
        );
    }

    private function create(string $table): string
    {
        return sprintf('$context->schema->createIfNotExists(%s, static function (\Dirthara\Schema\Table $table): void { '
        . '$table->id(); });', var_export($table, return: true));
    }

    private function log(string $migration): string
    {
        return sprintf("MigrationLog::record(%s . ':up:' . \$context->database->connection()->name());", var_export(
            $migration,
            return: true,
        ));
    }

    private function createTable(string $table, ?string $connection = null): void
    {
        $this->schema->create(
            $table,
            static function (Table $table): void {
                $table->id();
            },
            $connection,
        );
    }

    /**
     * @param list<string> $directories
     */
    private function migrateBeforehand(array $directories = ['users']): void
    {
        $this->migrate($directories);
        MigrationLog::$events = [];
        $this->statementsBefore = count($this->locks->statements);
        $this->queriesBefore = count($this->driver->queries);
    }

    /**
     * @param list<string> $directories
     */
    private function migrate(array $directories = ['users'], bool $locking = true): void
    {
        $this->migrator()->migrate(new MigrationConfiguration($directories, locking: $locking));
    }

    /**
     * @param list<string> $directories
     */
    private function fresh(array $directories = ['users'], bool $locking = true): void
    {
        $this->migrator()->fresh(new MigrationConfiguration($directories, locking: $locking));
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->loader,
            $this->repository,
            $this->database,
            $this->schema,
            new FrozenClock(new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC'))),
        );
    }

    private function applied(
        string $name,
        string $index,
        int $batch,
        string $connection = 'primary',
        ?string $description = null,
    ): AppliedMigration {
        return new AppliedMigration(
            name: $name,
            index: $index,
            description: $description,
            connection: $connection,
            batch: $batch,
            appliedAt: new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @return list<string>
     */
    private function lockStatements(): array
    {
        return array_slice($this->locks->statements, $this->statementsBefore);
    }

    /**
     * @return list<string>
     */
    private function queries(): array
    {
        return array_slice($this->driver->queries, $this->queriesBefore);
    }

    /**
     * @return array<string, int>
     */
    private function resets(): array
    {
        $resets = [];

        foreach ($this->queries() as $query) {
            [$connection, $sql] = explode(': ', $query, limit: 2);

            if (!str_starts_with($sql, self::TABLES)) {
                continue;
            }

            $resets[$connection] = ($resets[$connection] ?? 0) + 1;
        }

        return $resets;
    }

    private function assertNothingDropped(): void
    {
        self::assertSame([], $this->resets());
        self::assertSame([], MigrationLog::$events);
        self::assertTrue($this->schema->hasTable('users'));
        self::assertSame(['CreateUsers' => 1], $this->batches());
        $this->assertLockReleased();
    }

    private function assertLockReleased(): void
    {
        self::assertSame([], $this->database->connection()->locks()->held());
    }

    /**
     * @return array<string, int>
     */
    private function batches(): array
    {
        $batches = [];

        foreach ($this->repository->getApplied() as $migration) {
            $batches[$migration->name] = $migration->batch;
        }

        return $batches;
    }

    /**
     * @return array<string, string>
     */
    private function connections(): array
    {
        $connections = [];

        foreach ($this->repository->getApplied() as $migration) {
            $connections[$migration->name] = $migration->connection;
        }

        return $connections;
    }

    /**
     * @template T of Throwable
     *
     * @param class-string<T> $type
     * @param Closure(): mixed $operation
     *
     * @return T
     */
    private function expectFailure(string $type, Closure $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            if ($exception instanceof $type) {
                return $exception;
            }

            throw $exception;
        }

        self::fail(sprintf('The operation did not throw %s.', $type));
    }
}
