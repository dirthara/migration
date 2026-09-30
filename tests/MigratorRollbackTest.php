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
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Database\Exception\ConnectionRegistryException;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\InvalidRollbackStepsException;
use Dirthara\Migration\Tests\Fixtures\ScriptedNamedLockGrammar;

use function sprintf;
use function var_export;

final class MigratorRollbackTest extends TestCase
{
    use MigrationEnvironment;

    private const string LOCK = 'dirthara:migrations';

    private ScriptedNamedLockGrammar $locks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locks = new ScriptedNamedLockGrammar();
        $this->setUpEnvironment(new LockingSQLiteDriver($this->locks));
        MigrationLog::$events = [];
    }

    #[Test]
    public function it_rolls_back_the_latest_batch_in_reverse_order_of_application(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        $this->addLogged('users/c.php', 'AddUserStatus', '2026_01_03_000000');
        $this->addLogged('users/d.php', 'CreateComments', '2026_01_04_000000');
        $this->addLogged('users/e.php', 'AddPostSlug', '2026_01_05_000000');
        $this->migrate();

        $this->rollback();

        self::assertSame(
            ['AddPostSlug:down:primary', 'CreateComments:down:primary', 'AddUserStatus:down:primary'],
            MigrationLog::$events,
        );
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
    }

    #[Test]
    public function it_follows_the_history_rather_than_the_index_when_rolling_back(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_02_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_02_000000', batch: 1));
        $this->repository->record($this->applied('CreatePosts', '2026_01_01_000000', batch: 1));

        $this->rollback();

        self::assertSame(['CreatePosts:down:primary', 'CreateUsers:down:primary'], MigrationLog::$events);
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_rolls_back_the_latest_steps_across_batches(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        $this->addLogged('users/c.php', 'AddPostSlug', '2026_01_03_000000');
        $this->migrate();

        $this->rollback(steps: 2);

        self::assertSame(['AddPostSlug:down:primary', 'CreatePosts:down:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_rolls_back_one_step_across_every_connection(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_02_000000', connection: 'reporting');
        $this->migrate(['users']);
        $this->migrate(['users', 'reports']);

        $this->rollback(['users', 'reports'], steps: 1);

        self::assertSame(['CreateReports:down:reporting'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_rolls_back_steps_on_the_connection_each_migration_ran_on(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_02_000000', connection: 'reporting');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_03_000000');
        $this->migrate(['users', 'reports']);

        $this->rollback(['users', 'reports'], steps: 3);

        self::assertSame(
            ['CreateReports:down:reporting', 'CreatePosts:down:primary', 'CreateUsers:down:primary'],
            MigrationLog::$events,
        );
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_rolls_back_every_migration_when_asked_for_more_steps_than_exist(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();

        $this->rollback(steps: 10);

        self::assertSame(['CreatePosts:down:primary', 'CreateUsers:down:primary'], MigrationLog::$events);
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_rolls_back_on_the_connection_recorded_in_the_history(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied(
            'CreateUsers',
            '2026_01_01_000000',
            batch: 1,
            connection: 'reporting',
        ));

        $this->rollback();

        self::assertSame(['CreateUsers:down:reporting'], MigrationLog::$events);
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_runs_down_against_the_schema_of_the_recorded_connection(): void
    {
        $createTable = sprintf('$context->schema->create(%s, static function (\Dirthara\Schema\Table $table): void { $table->id(); });', var_export(
            'reports',
            return: true,
        ));
        $this->addMigration(
            'reports/a.php',
            'CreateReports',
            '2026_01_01_000000',
            connection: 'reporting',
            up: $createTable,
            down: sprintf('$context->schema->drop(%s);', var_export('reports', return: true)),
        );
        $this->migrate(['reports']);

        self::assertTrue($this->schema->hasTable('reports', 'reporting'));

        $this->rollback(['reports']);

        self::assertFalse($this->schema->hasTable('reports', 'reporting'));
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_completes_without_history(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');

        $this->rollback();
        $this->rollback(steps: 1);

        self::assertSame([], MigrationLog::$events);
        self::assertTrue($this->schema->hasTable(self::HISTORY_TABLE));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidSteps(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    #[DataProvider('invalidSteps')]
    public function it_rejects_a_number_of_steps_below_one(int $steps): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();

        $exception = $this->expectFailure(InvalidRollbackStepsException::class, fn() => $this->rollback(steps: $steps));

        self::assertSame(
            sprintf(
                'Unable to roll back %d steps: the number of steps must be at least 1, or null to roll back the latest '
                . 'batch.',
                $steps,
            ),
            $exception->getMessage(),
        );
        self::assertSame(['steps' => $steps], $exception->context);
        self::assertSame(['CreateUsers' => 1], $this->batches());
        self::assertSame(['acquire', 'release'], $this->locks->statements);
    }

    #[Test]
    public function it_refuses_to_roll_back_a_migration_whose_source_is_missing(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        unset($this->files['users/a.php']);

        $exception = $this->expectFailure(MigrationRollbackException::class, $this->rollback(...));

        self::assertSame(
            'Unable to roll back migration "CreateUsers" of batch 1 on connection "primary": none of the configured '
            . 'migration directories holds its source.',
            $exception->getMessage(),
        );
        self::assertSame(['migration' => 'CreateUsers', 'connection' => 'primary', 'batch' => 1], $exception->context);
        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
    }

    #[Test]
    public function it_finds_the_source_of_a_migration_whatever_the_case_of_its_name(): void
    {
        $this->addLogged('users/a.php', 'createusers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));

        $this->rollback();

        self::assertSame(['createusers:down:primary'], MigrationLog::$events);
        self::assertSame([], $this->batches());
    }

    #[Test]
    public function it_refuses_to_roll_back_a_migration_whose_index_changed(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_02_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));

        $exception = $this->expectFailure(MigrationPlanException::class, $this->rollback(...));

        self::assertSame('2026_01_01_000000', $exception->context['appliedIndex']);
        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_refuses_to_roll_back_a_migration_that_now_names_another_connection(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000', connection: 'reporting');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));

        $exception = $this->expectFailure(MigrationPlanException::class, $this->rollback(...));

        self::assertSame(
            [
                'migration' => 'CreateUsers',
                'path' => 'users/a.php',
                'connection' => 'reporting',
                'appliedConnection' => 'primary',
            ],
            $exception->context,
        );
        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_wraps_a_recorded_connection_that_is_no_longer_configured(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1, connection: 'archive'));

        $exception = $this->expectFailure(MigrationPlanException::class, $this->rollback(...));

        self::assertSame(
            ['migration' => 'CreateUsers', 'path' => 'users/a.php', 'connection' => 'archive'],
            $exception->context,
        );
        self::assertInstanceOf(ConnectionRegistryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_refuses_to_roll_back_from_an_invalid_migration_set(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        $this->addLogged('billing/a.php', 'createusers', '2026_01_02_000000');

        $this->expectFailure(MigrationPlanException::class, fn() => $this->rollback(['users', 'billing']));

        self::assertSame(['CreateUsers' => 1], $this->batches());
    }

    #[Test]
    public function it_runs_the_down_hooks_around_the_migration_and_forgets_it(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            down: "MigrationLog::record('CreateUsers:down');",
            beforeDown: "MigrationLog::record('CreateUsers:beforeDown');",
            afterDown: "MigrationLog::record('CreateUsers:afterDown');",
        );
        $this->migrate();

        $this->rollback();

        self::assertSame(
            ['CreateUsers:beforeDown', 'CreateUsers:down', 'CreateUsers:afterDown'],
            MigrationLog::$events,
        );
        self::assertSame([], $this->batches());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingSteps(): iterable
    {
        $failure = "throw new RuntimeException('Rollback failed');";

        yield 'beforeDown' => [$failure, '', ''];
        yield 'down' => ['', $failure, ''];
        yield 'afterDown' => ['', '', $failure];
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function it_keeps_the_history_of_a_migration_whose_rollback_fails(
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
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrate();

        $exception = $this->expectFailure(RuntimeException::class, $this->rollback(...));

        self::assertSame('Rollback failed', $exception->getMessage());
        self::assertSame(['CreateComments:down:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
        self::assertSame(['acquire', 'release', 'acquire', 'release'], $this->locks->statements);
        $this->assertLockReleased();
    }

    #[Test]
    public function it_skips_a_migration_and_rolls_back_the_next_one(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged(
            'users/b.php',
            'CreatePosts',
            '2026_01_02_000000',
            beforeDown: '$decision = MigrationDecision::skip();',
        );
        $this->migrate();

        $this->rollback();

        self::assertSame(['CreateUsers:down:primary'], MigrationLog::$events);
        self::assertSame(['CreatePosts' => 1], $this->batches());
    }

    #[Test]
    public function it_does_not_reach_further_back_to_make_up_for_a_skipped_step(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->addLogged(
            'users/c.php',
            'CreateComments',
            '2026_01_03_000000',
            beforeDown: '$decision = MigrationDecision::skip();',
        );
        $this->migrate();

        $this->rollback(steps: 2);

        self::assertSame(['CreatePosts:down:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreateComments' => 1], $this->batches());
    }

    #[Test]
    public function it_stops_the_whole_rollback_at_a_stopped_migration(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged(
            'users/b.php',
            'CreatePosts',
            '2026_01_02_000000',
            beforeDown: "\$decision = MigrationDecision::stop('Keep the posts');",
        );
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrate();

        $this->rollback();

        self::assertSame(['CreateComments:down:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
        $this->assertLockReleased();
    }

    #[Test]
    public function it_keeps_the_batches_of_migrations_left_by_a_step_rollback(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrate();
        $this->migrate();

        $this->rollback(steps: 1);

        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());

        $this->migrate();

        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1, 'CreateComments' => 2], $this->batches());

        MigrationLog::$events = [];
        $this->rollback();

        self::assertSame(['CreateComments:down:primary'], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1, 'CreatePosts' => 1], $this->batches());
    }

    #[Test]
    public function it_holds_the_migration_lock_while_rolling_back(): void
    {
        $this->addMigration(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            down: "MigrationLog::record(implode(',', \$context->database->connection()->locks()->held()));",
        );
        $this->migrate();

        $this->rollback();

        self::assertSame([self::LOCK], MigrationLog::$events);
        self::assertSame(['acquire', 'release', 'acquire', 'release'], $this->locks->statements);
        $this->assertLockReleased();
    }

    #[Test]
    public function it_releases_the_lock_when_rollback_planning_fails(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        unset($this->files['users/a.php']);

        $this->expectFailure(MigrationRollbackException::class, $this->rollback(...));

        self::assertSame(['acquire', 'release', 'acquire', 'release'], $this->locks->statements);
        $this->assertLockReleased();
    }

    #[Test]
    public function it_takes_no_lock_when_locking_is_disabled(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate(locking: false);

        $this->rollback(locking: false);

        self::assertSame(['CreateUsers:down:primary'], MigrationLog::$events);
        self::assertSame([], $this->locks->statements);
    }

    #[Test]
    public function it_refuses_to_roll_back_unlocked_on_a_connection_without_named_locks(): void
    {
        $this->setUpEnvironment();
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate(locking: false);

        $this->expectFailure(MigrationLockException::class, $this->rollback(...));

        self::assertSame([], MigrationLog::$events);
        self::assertSame(['CreateUsers' => 1], $this->batches());

        $this->rollback(locking: false);

        self::assertSame(['CreateUsers:down:primary'], MigrationLog::$events);
    }

    private function addLogged(
        string $path,
        string $name,
        string $index,
        ?string $connection = null,
        ?string $beforeDown = null,
    ): void {
        $this->addMigration(
            $path,
            $name,
            $index,
            connection: $connection,
            down: sprintf("MigrationLog::record(%s . ':down:' . \$context->database->connection()->name());", var_export(
                $name,
                return: true,
            )),
            beforeDown: $beforeDown,
        );
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
    private function rollback(array $directories = ['users'], ?int $steps = null, bool $locking = true): void
    {
        $this->migrator()->rollback(new MigrationConfiguration($directories, locking: $locking), steps: $steps);
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

    private function applied(string $name, string $index, int $batch, string $connection = 'primary'): AppliedMigration
    {
        return new AppliedMigration(
            name: $name,
            index: $index,
            description: null,
            connection: $connection,
            batch: $batch,
            appliedAt: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
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

        self::fail(sprintf('The operation did not throw %s.', $type));
    }
}
