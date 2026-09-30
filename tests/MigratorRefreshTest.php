<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use Closure;
use Throwable;
use DateTimeZone;
use RuntimeException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Dirthara\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use Dirthara\Migration\Tests\Fixtures\MigrationLog;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Tests\Fixtures\LockingSQLiteDriver;
use Dirthara\Migration\Exception\MigrationRefreshException;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Tests\Fixtures\ScriptedNamedLockGrammar;

use function count;
use function sprintf;
use function array_keys;
use function var_export;
use function array_slice;

final class MigratorRefreshTest extends TestCase
{
    use MigrationEnvironment;

    private const string LOCK = 'dirthara:migrations';

    private const string FAILURE = "throw new RuntimeException('Refresh failed');";

    private ScriptedNamedLockGrammar $locks;

    private int $statementsBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locks = new ScriptedNamedLockGrammar();
        $this->setUpEnvironment(new LockingSQLiteDriver($this->locks));
        MigrationLog::$events = [];
    }

    #[Test]
    public function it_rolls_back_every_applied_migration_newest_first_and_applies_them_again_in_order(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrateBeforehand();
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrateBeforehand();
        $this->addLogged('users/d.php', 'AddPostSlug', '2026_01_04_000000');
        $this->migrateBeforehand();

        $this->refresh();

        self::assertSame(
            [
                'AddPostSlug:down:primary',
                'CreateComments:down:primary',
                'CreatePosts:down:primary',
                'CreateUsers:down:primary',
                'CreateUsers:up:primary',
                'CreatePosts:up:primary',
                'CreateComments:up:primary',
                'AddPostSlug:up:primary',
            ],
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_rolls_back_in_the_order_of_the_history_rather_than_the_index(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_02_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_02_000000', batch: 1));
        $this->repository->record($this->applied('CreatePosts', '2026_01_01_000000', batch: 2));

        $this->refresh();

        self::assertSame(
            [
                'CreatePosts:down:primary',
                'CreateUsers:down:primary',
                'CreatePosts:up:primary',
                'CreateUsers:up:primary',
            ],
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_records_the_migrations_again_in_the_first_batch(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000', description: 'Creates the users');
        $this->migrateBeforehand();
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrateBeforehand();
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');

        $this->refresh();

        self::assertEquals(
            [
                $this->applied('CreateUsers', '2026_01_01_000000', batch: 1, description: 'Creates the users'),
                $this->applied('CreatePosts', '2026_01_02_000000', batch: 1),
                $this->applied('CreateComments', '2026_01_03_000000', batch: 1),
            ],
            $this->repository->getApplied(),
        );
    }

    #[Test]
    public function it_runs_every_migration_when_nothing_was_applied(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');

        $this->refresh();

        self::assertSame(['CreateUsers:up:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_rolls_back_on_the_recorded_connection_and_applies_on_the_resolved_one(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_02_000000', connection: 'reporting');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 1, connection: 'reporting'));
        $this->repository->record($this->applied('CreateReports', '2026_01_02_000000', 1, connection: 'reporting'));

        $this->refresh(['users', 'reports']);

        self::assertSame(
            [
                'CreateReports:down:reporting',
                'CreateUsers:down:reporting',
                'CreateUsers:up:primary',
                'CreateReports:up:reporting',
            ],
            MigrationLog::$events,
        );
        self::assertSame(['CreateUsers' => 'primary', 'CreateReports' => 'reporting'], $this->connections());
    }

    #[Test]
    public function it_runs_the_hooks_of_both_phases(): void
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

        $this->refresh();

        self::assertSame(['beforeDown', 'down', 'afterDown', 'beforeUp', 'up', 'afterUp'], MigrationLog::$events);
    }

    #[Test]
    public function it_holds_one_lock_across_both_phases(): void
    {
        $held = "MigrationLog::record('%s:' . implode(',', \$context->database->connection()->locks()->held()));";
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: sprintf($held, 'up'),
            down: sprintf($held, 'down'),
        );
        $this->migrateBeforehand();

        $this->refresh();

        self::assertSame(['down:' . self::LOCK, 'up:' . self::LOCK], MigrationLog::$events);
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function incompleteRollbacks(): iterable
    {
        yield 'skip' => [
            '$decision = MigrationDecision::skip();',
            ['CreateComments:down:primary', 'CreateUsers:down:primary'],
            ['CreatePosts'],
        ];
        yield 'stop' => [
            "\$decision = MigrationDecision::stop('Keep the posts');",
            ['CreateComments:down:primary'],
            ['CreateUsers', 'CreatePosts'],
        ];
    }

    /**
     * @param list<string> $events
     * @param list<string> $remaining
     */
    #[Test]
    #[DataProvider('incompleteRollbacks')]
    public function it_does_not_run_migrations_again_after_an_incomplete_rollback(
        string $beforeDown,
        array $events,
        array $remaining,
    ): void {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000', beforeDown: $beforeDown);
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrateBeforehand();

        $exception = $this->expectFailure(MigrationRefreshException::class, $this->refresh(...));

        self::assertSame(['migrations' => $remaining], $exception->context);
        self::assertSame($events, MigrationLog::$events);
        self::assertSame($remaining, array_keys($this->batches()));
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_reports_every_migration_a_skipping_rollback_left_applied(): void
    {
        $skip = '$decision = MigrationDecision::skip();';
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000', beforeDown: $skip);
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000', beforeDown: $skip);
        $this->migrateBeforehand();

        $exception = $this->expectFailure(MigrationRefreshException::class, $this->refresh(...));

        self::assertSame(
            'Unable to refresh the migrations: a rollback hook skipped or stopped the rollback, so 2 migrations are '
            . 'still applied: "CreateUsers", "CreatePosts". No migration was run again.',
            $exception->getMessage(),
        );
        self::assertSame(['migrations' => ['CreateUsers', 'CreatePosts']], $exception->context);
        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_reports_a_single_migration_a_stopped_rollback_left_applied(): void
    {
        $this->addLogged(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            beforeDown: "\$decision = MigrationDecision::stop('Keep the users');",
        );
        $this->migrateBeforehand();

        $exception = $this->expectFailure(MigrationRefreshException::class, $this->refresh(...));

        self::assertSame(
            'Unable to refresh the migrations: a rollback hook skipped or stopped the rollback, so 1 migration is '
            . 'still applied: "CreateUsers". No migration was run again.',
            $exception->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingRollbackSteps(): iterable
    {
        yield 'beforeDown' => [self::FAILURE, '', ''];
        yield 'down' => ['', self::FAILURE, ''];
        yield 'afterDown' => ['', '', self::FAILURE];
    }

    #[Test]
    #[DataProvider('failingRollbackSteps')]
    public function it_does_not_run_migrations_again_when_a_rollback_fails(
        string $beforeDown,
        string $down,
        string $afterDown,
    ): void {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addMigration(
            'users/b.php',
            'CreatePosts',
            '2026_01_02_000000',
            down: $down,
            beforeDown: $beforeDown,
            afterDown: $afterDown,
        );
        $this->migrateBeforehand();

        $exception = $this->expectFailure(RuntimeException::class, $this->refresh(...));

        self::assertSame('Refresh failed', $exception->getMessage());
        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_rolls_nothing_back_when_the_source_of_an_applied_migration_is_missing(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrateBeforehand();
        unset($this->files['users/a.php']);

        $this->expectFailure(MigrationRollbackException::class, $this->refresh(...));

        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_rolls_nothing_back_when_an_applied_migration_changed_its_index(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_02_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));
        $this->repository->record($this->applied('CreatePosts', '2026_01_02_000000', batch: 1));

        $exception = $this->expectFailure(MigrationPlanException::class, $this->refresh(...));

        self::assertSame('2026_01_01_000000', $exception->context['appliedIndex']);
        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_rolls_nothing_back_when_a_recorded_connection_is_no_longer_configured(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 1, connection: 'archive'));
        $this->repository->record($this->applied('CreatePosts', '2026_01_02_000000', batch: 1));

        $exception = $this->expectFailure(MigrationPlanException::class, $this->refresh(...));

        self::assertSame('archive', $exception->context['connection']);
        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_rolls_nothing_back_when_a_pending_migration_names_a_connection_that_is_not_configured(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrateBeforehand();
        $this->addLogged('users/b.php', 'CreateArchive', '2026_01_02_000000', connection: 'archive');

        $exception = $this->expectFailure(MigrationPlanException::class, $this->refresh(...));

        self::assertSame(
            ['migration' => 'CreateArchive', 'path' => 'users/b.php', 'connection' => 'archive'],
            $exception->context,
        );
        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_rolls_nothing_back_from_an_invalid_migration_set(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrateBeforehand();
        $this->addLogged('billing/a.php', 'createusers', '2026_01_02_000000');

        $this->expectFailure(MigrationPlanException::class, fn() => $this->refresh(['users', 'billing']));

        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingSteps(): iterable
    {
        yield 'beforeUp' => [self::FAILURE, '', ''];
        yield 'up' => ['', self::FAILURE, ''];
        yield 'afterUp' => ['', '', self::FAILURE];
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function it_releases_the_lock_when_a_migration_fails_to_apply_again(
        string $beforeUp,
        string $up,
        string $afterUp,
    ): void {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreatePosts', '2026_01_02_000000', batch: 1));
        $this->addMigration(
            'users/b.php',
            'CreatePosts',
            '2026_01_02_000000',
            up: $up,
            beforeUp: $beforeUp,
            afterUp: $afterUp,
        );

        $exception = $this->expectFailure(RuntimeException::class, $this->refresh(...));

        self::assertSame('Refresh failed', $exception->getMessage());
        self::assertSame(['CreateUsers:up:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_the_history_is_lost_between_the_phases(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            down: sprintf('$context->schema->drop(%s);', var_export(self::HISTORY_TABLE, return: true)),
        );
        $this->migrateBeforehand();

        $this->expectFailure(MigrationRepositoryException::class, $this->refresh(...));

        self::assertSame(['acquire', 'release'], $this->lockStatements());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_takes_no_lock_when_locking_is_disabled(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrateBeforehand();

        $this->refresh(locking: false);

        self::assertSame(['CreateUsers:down:primary', 'CreateUsers:up:primary'], MigrationLog::$events);
        self::assertSame([], $this->lockStatements());
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_refuses_to_refresh_unlocked_on_a_connection_without_named_locks(): void
    {
        $this->setUpEnvironment();
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate(locking: false);

        $this->expectFailure(MigrationLockException::class, $this->refresh(...));

        self::assertSame(['CreateUsers:up:primary'], MigrationLog::$events);

        $this->refresh(locking: false);

        self::assertSame(
            ['CreateUsers:up:primary', 'CreateUsers:down:primary', 'CreateUsers:up:primary'],
            MigrationLog::$events,
        );
    }

    private function addLogged(
        string $path,
        string $name,
        string $index,
        ?string $connection = null,
        ?string $description = null,
        ?string $beforeDown = null,
    ): void {
        $this->addMigration(
            $path,
            $name,
            $index,
            connection: $connection,
            description: $description,
            up: $this->log($name, 'up'),
            down: $this->log($name, 'down'),
            beforeDown: $beforeDown,
        );
    }

    private function log(string $migration, string $step): string
    {
        return sprintf(
            "MigrationLog::record(%s . ':%s:' . \$context->database->connection()->name());",
            var_export($migration, return: true),
            $step,
        );
    }

    private function migrateBeforehand(): void
    {
        $this->migrate();
        MigrationLog::$events = [];
        $this->statementsBefore = count($this->locks->statements);
    }

    /**
     * @return list<string>
     */
    private function lockStatements(): array
    {
        return array_slice($this->locks->statements, $this->statementsBefore);
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
    private function refresh(array $directories = ['users'], bool $locking = true): void
    {
        $this->migrator()->refresh(new MigrationConfiguration($directories, locking: $locking));
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
