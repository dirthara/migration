<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use Throwable;
use DateTimeZone;
use RuntimeException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Dirthara\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use Dirthara\Migration\Tests\Fixtures\MigrationLog;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Tests\Fixtures\LockingSQLiteDriver;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Migration\Exception\MigrationRepositoryException;

use function sprintf;
use function var_export;
use function array_filter;
use function array_values;
use function str_ends_with;

final class MigratorTest extends TestCase
{
    use MigrationEnvironment;

    private const string NOW = '2026-09-30 10:15:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEnvironment(new LockingSQLiteDriver());
        MigrationLog::$events = [];
    }

    #[Test]
    public function it_runs_and_records_every_pending_migration(): void
    {
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            description: 'Users',
            up: $this->log('create_users'),
        );
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000', up: $this->log('create_profiles'));

        $this->migrate(['users']);

        self::assertSame(['create_users:up:primary', 'create_profiles:up:primary'], MigrationLog::$events);
        self::assertEquals(
            [
                new AppliedMigration(
                    name: 'create_users',
                    index: '2026_01_01_000000',
                    description: 'Users',
                    connection: 'primary',
                    batch: 1,
                    appliedAt: new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
                ),
                new AppliedMigration(
                    name: 'create_profiles',
                    index: '2026_01_02_000000',
                    description: null,
                    connection: 'primary',
                    batch: 1,
                    appliedAt: new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
                ),
            ],
            $this->repository->getApplied(),
        );
    }

    #[Test]
    public function it_completes_without_pending_migrations(): void
    {
        $this->migrate([]);

        self::assertSame([], MigrationLog::$events);
        self::assertSame([], $this->repository->getApplied());
    }

    #[Test]
    public function it_does_not_run_an_applied_migration_again(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->log('create_users'));

        $this->migrate(['users']);
        $this->migrate(['users']);

        self::assertSame(['create_users:up:primary'], MigrationLog::$events);
        self::assertCount(1, $this->repository->getApplied());
    }

    #[Test]
    public function it_records_every_migration_of_a_run_in_one_batch_across_connections(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->addMigration('reports/a.php', 'create_reports', '2026_01_02_000000', connection: 'reporting');
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_03_000000');

        $this->migrate(['users', 'reports']);

        self::assertSame(['create_users' => 1, 'create_profiles' => 1, 'create_reports' => 1], $this->batches());
    }

    #[Test]
    public function it_records_the_next_run_in_the_next_batch(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->migrate(['users']);

        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000');
        $this->migrate(['users']);

        self::assertSame(['create_users' => 1, 'create_profiles' => 2], $this->batches());
    }

    #[Test]
    public function it_runs_the_migrations_of_every_directory_in_index_order(): void
    {
        $this->addMigration('users/c.php', 'create_users', '2026_01_03_000000', up: $this->log('create_users'));
        $this->addMigration('orders/a.php', 'create_orders', '2026_01_01_000000', up: $this->log('create_orders'));
        $this->addMigration('billing/b.php', 'create_invoices', '2026_01_02_000000', up: $this->log('create_invoices'));

        $this->migrate(['users', 'orders', 'billing']);

        self::assertSame(
            ['create_orders:up:primary', 'create_invoices:up:primary', 'create_users:up:primary'],
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_runs_migrations_with_the_same_index_in_path_order(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->log('create_users'));
        $this->addMigration('billing/a.php', 'create_invoices', '2026_01_01_000000', up: $this->log('create_invoices'));

        $this->migrate(['users', 'billing']);

        self::assertSame(['create_invoices:up:primary', 'create_users:up:primary'], MigrationLog::$events);
    }

    #[Test]
    public function it_keeps_the_order_of_each_connection(): void
    {
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_04_000000', up: $this->log('create_profiles'));
        $this->addMigration(
            'reports/b.php',
            'create_totals',
            '2026_01_03_000000',
            connection: 'reporting',
            up: $this->log('create_totals'),
        );
        $this->addMigration('users/a.php', 'create_users', '2026_01_02_000000', up: $this->log('create_users'));
        $this->addMigration(
            'reports/a.php',
            'create_reports',
            '2026_01_01_000000',
            connection: 'reporting',
            up: $this->log('create_reports'),
        );

        $this->migrate(['users', 'reports']);

        self::assertSame(['create_users:up:primary', 'create_profiles:up:primary'], $this->eventsOn('primary'));
        self::assertSame(['create_reports:up:reporting', 'create_totals:up:reporting'], $this->eventsOn('reporting'));
    }

    #[Test]
    public function it_runs_each_migration_against_the_connection_it_resolves_to(): void
    {
        $createTable =
            static fn(string $table): string => sprintf('$context->schema->create(%s, static function (\Dirthara\Schema\Table $table): void { $table->id(); });', var_export(
                $table,
                return: true,
            ));
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            up: $this->log('create_users') . $createTable('users'),
        );
        $this->addMigration(
            'reports/a.php',
            'create_reports',
            '2026_01_01_000000',
            connection: 'reporting',
            up: $this->log('create_reports') . $createTable('reports'),
        );

        $this->migrate(['users', 'reports']);

        self::assertSame(['create_users:up:primary'], $this->eventsOn('primary'));
        self::assertSame(['create_reports:up:reporting'], $this->eventsOn('reporting'));
        self::assertTrue($this->schema->hasTable('users', 'primary'));
        self::assertFalse($this->schema->hasTable('users', 'reporting'));
        self::assertTrue($this->schema->hasTable('reports', 'reporting'));
        self::assertFalse($this->schema->hasTable('reports', 'primary'));
        self::assertSame(['create_users' => 'primary', 'create_reports' => 'reporting'], $this->appliedConnections());
    }

    #[Test]
    public function it_runs_the_hooks_around_the_migration_and_records_it(): void
    {
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            up: $this->log('create_users'),
            beforeUp: "MigrationLog::record('create_users:beforeUp');",
            afterUp: "MigrationLog::record('create_users:afterUp');",
        );

        $this->migrate(['users']);

        self::assertSame(
            ['create_users:beforeUp', 'create_users:up:primary', 'create_users:afterUp'],
            MigrationLog::$events,
        );
        self::assertSame(['create_users' => 1], $this->batches());
    }

    #[Test]
    public function it_skips_a_migration_without_recording_it_and_runs_the_next_one(): void
    {
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            up: $this->log('create_users'),
            beforeUp: "MigrationLog::record('create_users:beforeUp'); \$decision = MigrationDecision::skip('Later');",
            afterUp: "MigrationLog::record('create_users:afterUp');",
        );
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000', up: $this->log('create_profiles'));

        $this->migrate(['users']);

        self::assertSame(['create_users:beforeUp', 'create_profiles:up:primary'], MigrationLog::$events);
        self::assertSame(['create_profiles' => 1], $this->batches());
    }

    #[Test]
    public function it_offers_a_skipped_migration_again_on_the_next_run(): void
    {
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            beforeUp: "MigrationLog::record('create_users:beforeUp'); \$decision = MigrationDecision::skip();",
        );

        $this->migrate(['users']);
        $this->migrate(['users']);

        self::assertSame(['create_users:beforeUp', 'create_users:beforeUp'], MigrationLog::$events);
        self::assertSame([], $this->repository->getApplied());
    }

    #[Test]
    public function it_stops_the_whole_run_at_a_stopped_migration(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->log('create_users'));
        $this->addMigration(
            'users/b.php',
            'create_profiles',
            '2026_01_02_000000',
            up: $this->log('create_profiles'),
            beforeUp: "MigrationLog::record('create_profiles:beforeUp'); \$decision = MigrationDecision::stop('Not yet');",
            afterUp: "MigrationLog::record('create_profiles:afterUp');",
        );
        $this->addMigration('users/c.php', 'create_avatars', '2026_01_03_000000', up: $this->log('create_avatars'));
        $this->addMigration(
            'reports/a.php',
            'create_reports',
            '2026_01_01_000000',
            connection: 'reporting',
            up: $this->log('create_reports'),
        );

        $this->migrate(['users', 'reports']);

        self::assertSame(['create_users:up:primary', 'create_profiles:beforeUp'], MigrationLog::$events);
        self::assertSame(['create_users' => 1], $this->batches());
    }

    #[Test]
    public function it_continues_a_stopped_run_in_a_new_batch(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->addMigration(
            'users/b.php',
            'create_profiles',
            '2026_01_02_000000',
            beforeUp: '$decision = MigrationDecision::stop(\'Not yet\');',
        );
        $this->migrate(['users']);

        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000');
        $this->migrate(['users']);

        self::assertSame(['create_users' => 1, 'create_profiles' => 2], $this->batches());
    }

    #[Test]
    public function it_does_not_record_a_migration_whose_before_hook_fails(): void
    {
        $this->addFailingRun(beforeUp: "throw new RuntimeException('beforeUp failed');");

        $this->assertRunFails('beforeUp failed');

        self::assertSame(['create_users:up:primary', 'create_profiles:beforeUp'], MigrationLog::$events);
    }

    #[Test]
    public function it_does_not_record_a_migration_that_fails(): void
    {
        $this->addFailingRun(up: "throw new RuntimeException('up failed');");

        $this->assertRunFails('up failed');

        self::assertSame(
            ['create_users:up:primary', 'create_profiles:beforeUp', 'create_profiles:up:primary'],
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_does_not_record_a_migration_whose_after_hook_fails(): void
    {
        $this->addFailingRun(afterUp: "throw new RuntimeException('afterUp failed');");

        $this->assertRunFails('afterUp failed');

        self::assertSame(
            ['create_users:up:primary', 'create_profiles:beforeUp', 'create_profiles:up:primary'],
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_records_a_migration_before_running_it(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->recorded('create_users'));

        $this->migrate(['users']);

        self::assertSame(['create_users:recorded'], MigrationLog::$events);
    }

    #[Test]
    public function it_does_not_run_a_migration_it_cannot_record(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->dropHistory());
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000', up: $this->log('create_profiles'));

        try {
            $this->migrate(['users']);
            self::fail(sprintf('The run did not throw %s.', MigrationRepositoryException::class));
        } catch (MigrationRepositoryException $exception) {
            self::assertSame(['table' => self::HISTORY_TABLE, 'migration' => 'create_profiles'], $exception->context);
        }

        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_keeps_the_migration_failure_behind_a_failure_to_restore_the_history(): void
    {
        $this->addMigration(
            'users/a.php',
            'create_users',
            '2026_01_01_000000',
            up: $this->dropHistory() . "throw new RuntimeException('up failed');",
        );

        try {
            $this->migrate(['users']);
            self::fail(sprintf('The run did not throw %s.', MigrationRepositoryException::class));
        } catch (MigrationRepositoryException $exception) {
            self::assertStringStartsWith('Unable to remove migration "create_users"', $exception->getMessage());
            self::assertSame('up failed', $this->rootCause($exception)->getMessage());
        }
    }

    private function recorded(string $migration): string
    {
        return sprintf(
            "MigrationLog::record(%1\$s . (\$context->database->table(%2\$s)->where('name', '=', %1\$s)->exists() ? "
            . "':recorded' : ':unrecorded'));",
            var_export($migration, return: true),
            var_export(self::HISTORY_TABLE, return: true),
        );
    }

    private function dropHistory(): string
    {
        return sprintf('$context->schema->drop(%s);', var_export(self::HISTORY_TABLE, return: true));
    }

    private function rootCause(Throwable $exception): Throwable
    {
        $previous = $exception->getPrevious();

        while ($previous !== null) {
            $exception = $previous;
            $previous = $exception->getPrevious();
        }

        return $exception;
    }

    private function addFailingRun(string $beforeUp = '', string $up = '', string $afterUp = ''): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', up: $this->log('create_users'));
        $this->addMigration(
            'users/b.php',
            'create_profiles',
            '2026_01_02_000000',
            up: $this->log('create_profiles') . $up,
            beforeUp: "MigrationLog::record('create_profiles:beforeUp');" . $beforeUp,
            afterUp: $afterUp,
        );
        $this->addMigration('users/c.php', 'create_avatars', '2026_01_03_000000', up: $this->log('create_avatars'));
    }

    private function assertRunFails(string $message): void
    {
        try {
            $this->migrate(['users']);
            self::fail('The run did not fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame(['create_users' => 1], $this->batches());
    }

    /**
     * @param list<string> $directories
     */
    private function migrate(array $directories): void
    {
        new Migrator(
            $this->loader,
            $this->repository,
            $this->database,
            $this->schema,
            new FrozenClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))),
        )->migrate(new MigrationConfiguration($directories));
    }

    private function log(string $migration): string
    {
        return sprintf("MigrationLog::record(%s . ':up:' . \$context->database->connection()->name());", var_export(
            $migration,
            return: true,
        ));
    }

    /**
     * @return list<string>
     */
    private function eventsOn(string $connection): array
    {
        return array_values(array_filter(MigrationLog::$events, static fn(string $event): bool => str_ends_with(
            $event,
            ':' . $connection,
        )));
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
    private function appliedConnections(): array
    {
        $connections = [];

        foreach ($this->repository->getApplied() as $migration) {
            $connections[$migration->name] = $migration->connection;
        }

        return $connections;
    }
}
