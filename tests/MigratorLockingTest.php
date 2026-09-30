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
use Dirthara\Database\Exception\NamedLockException;
use Dirthara\Migration\Tests\Fixtures\MigrationLog;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationLockException;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Database\Exception\UnsupportedLockException;
use Dirthara\Migration\Tests\Fixtures\LockingSQLiteDriver;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Migration\Exception\MigrationRepositoryException;
use Dirthara\Migration\Tests\Fixtures\ScriptedNamedLockGrammar;

use function sprintf;
use function var_export;

final class MigratorLockingTest extends TestCase
{
    use MigrationEnvironment;

    private const string LOCK = 'dirthara:migrations';

    private const string HELD = "MigrationLog::record(%s . ':' . implode(',', \$context->database->connection()->locks()->held()));";

    private ScriptedNamedLockGrammar $locks;

    protected function setUp(): void
    {
        parent::setUp();

        MigrationLog::$events = [];
    }

    #[Test]
    public function it_holds_the_lock_on_the_history_connection_while_migrations_run(): void
    {
        $this->useLocks();
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000', up: $this->held('CreateUsers'));
        $this->addMigration(
            'reports/a.php',
            'CreateReports',
            '2026_01_01_000000',
            connection: 'reporting',
            up: $this->held('CreateReports'),
        );

        $this->migrate(['users', 'reports']);

        self::assertSame(['CreateUsers:' . self::LOCK, 'CreateReports:'], MigrationLog::$events);
        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
        self::assertSame(['CreateUsers', 'CreateReports'], $this->appliedNames());
    }

    #[Test]
    public function it_releases_the_lock_when_nothing_is_pending(): void
    {
        $this->useLocks();

        $this->migrate([]);

        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_planning_fails(): void
    {
        $this->useLocks();
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addMigration('billing/a.php', 'createusers', '2026_01_02_000000');

        $this->expectFailure(MigrationPlanException::class, fn() => $this->migrate(['users', 'billing']));

        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingSteps(): iterable
    {
        $failure = "throw new RuntimeException('Migration failed');";

        yield 'beforeUp' => [$failure, '', ''];
        yield 'up' => ['', $failure, ''];
        yield 'afterUp' => ['', '', $failure];
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function it_releases_the_lock_when_a_migration_fails(string $beforeUp, string $up, string $afterUp): void
    {
        $this->useLocks();
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: $up,
            beforeUp: $beforeUp,
            afterUp: $afterUp,
        );

        $this->expectFailure(RuntimeException::class, fn() => $this->migrate(['users']));

        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
        self::assertSame([], $this->appliedNames());
    }

    #[Test]
    public function it_releases_the_lock_when_recording_a_migration_fails(): void
    {
        $this->useLocks();
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: sprintf('$context->schema->drop(%s);', var_export(self::HISTORY_TABLE, return: true)),
        );
        $this->addMigration('users/b.php', 'CreatePosts', '2026_01_02_000000');

        $this->expectFailure(MigrationRepositoryException::class, fn() => $this->migrate(['users']));

        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_a_migration_stops_the_run(): void
    {
        $this->useLocks();
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            beforeUp: "\$decision = MigrationDecision::stop('Not yet');",
        );

        $this->migrate(['users']);

        self::assertSame(['acquire', 'release'], $this->locks->statements);
        self::assertLockReleased();
    }

    #[Test]
    public function it_takes_no_lock_when_locking_is_disabled(): void
    {
        $this->useLocks();
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000', up: $this->held('CreateUsers'));

        $this->migrate(['users'], locking: false);

        self::assertSame([], $this->locks->statements);
        self::assertSame(['CreateUsers:'], MigrationLog::$events);
        self::assertSame(['CreateUsers'], $this->appliedNames());
    }

    #[Test]
    public function it_refuses_to_run_unlocked_on_a_connection_without_named_locks(): void
    {
        $this->setUpEnvironment();
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000', up: $this->held('CreateUsers'));

        $exception = $this->expectFailure(MigrationLockException::class, fn() => $this->migrate(['users']));

        self::assertSame(
            'Unable to lock migration runs with lock "dirthara:migrations": connection "primary" does not support named '
            . 'locks. Disable locking in the migration configuration to run migrations without a lock.',
            $exception->getMessage(),
        );
        self::assertSame(['lock' => self::LOCK, 'connection' => 'primary'], $exception->context);
        self::assertInstanceOf(UnsupportedLockException::class, $exception->getPrevious());
        self::assertSame([], MigrationLog::$events);
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_runs_unlocked_on_a_connection_without_named_locks_when_locking_is_disabled(): void
    {
        $this->setUpEnvironment();
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000', up: $this->held('CreateUsers'));

        $this->migrate(['users'], locking: false);

        self::assertSame(['CreateUsers:'], MigrationLog::$events);
        self::assertSame(['CreateUsers'], $this->appliedNames());
    }

    #[Test]
    public function it_wraps_a_failure_to_acquire_the_lock(): void
    {
        $this->useLocks(new ScriptedNamedLockGrammar(acquire: ScriptedNamedLockGrammar::FAILED));
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000', up: $this->held('CreateUsers'));

        $exception = $this->expectFailure(MigrationLockException::class, fn() => $this->migrate(['users']));

        self::assertSame(
            'Unable to acquire migration lock "dirthara:migrations" on connection "primary".',
            $exception->getMessage(),
        );
        self::assertSame(['lock' => self::LOCK, 'connection' => 'primary'], $exception->context);
        self::assertInstanceOf(NamedLockException::class, $exception->getPrevious());
        self::assertSame(['acquire'], $this->locks->statements);
        self::assertSame([], MigrationLog::$events);
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_wraps_a_failure_to_release_the_lock(): void
    {
        $this->useLocks(new ScriptedNamedLockGrammar(release: ScriptedNamedLockGrammar::FAILED));
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000');

        $exception = $this->expectFailure(MigrationLockException::class, fn() => $this->migrate(['users']));

        self::assertSame(
            'Unable to release migration lock "dirthara:migrations" on connection "primary".',
            $exception->getMessage(),
        );
        self::assertSame(['lock' => self::LOCK, 'connection' => 'primary'], $exception->context);
        self::assertInstanceOf(NamedLockException::class, $exception->getPrevious());
        self::assertSame(['CreateUsers'], $this->appliedNames());
    }

    #[Test]
    public function it_reports_a_migration_failure_rather_than_a_failure_to_release_the_lock(): void
    {
        $this->useLocks(new ScriptedNamedLockGrammar(release: ScriptedNamedLockGrammar::FAILED));
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            up: "throw new RuntimeException('Migration failed');",
        );

        $exception = $this->expectFailure(RuntimeException::class, fn() => $this->migrate(['users']));

        self::assertNotInstanceOf(MigrationLockException::class, $exception);
        self::assertSame('Migration failed', $exception->getMessage());
        self::assertNull($exception->getPrevious());
        self::assertSame(['acquire', 'release'], $this->locks->statements);
    }

    private function useLocks(ScriptedNamedLockGrammar $locks = new ScriptedNamedLockGrammar()): void
    {
        $this->locks = $locks;
        $this->setUpEnvironment(new LockingSQLiteDriver($locks));
    }

    private function held(string $migration): string
    {
        return sprintf(self::HELD, var_export($migration, return: true));
    }

    private function assertLockReleased(): void
    {
        self::assertSame([], $this->database->connection()->locks()->held());
    }

    /**
     * @param list<string> $directories
     */
    private function migrate(array $directories, bool $locking = true): void
    {
        new Migrator(
            $this->loader,
            $this->repository,
            $this->database,
            $this->schema,
            new FrozenClock(new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC'))),
        )->migrate(new MigrationConfiguration($directories, locking: $locking));
    }

    /**
     * @template T of Throwable
     *
     * @param class-string<T> $type
     * @param Closure(): void $operation
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

        self::fail(sprintf('The run did not throw %s.', $type));
    }

    /**
     * @return list<string>
     */
    private function appliedNames(): array
    {
        $names = [];

        foreach ($this->repository->getApplied() as $migration) {
            $names[] = $migration->name;
        }

        return $names;
    }
}
