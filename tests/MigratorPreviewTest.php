<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use Closure;
use Throwable;
use DateTimeZone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Dirthara\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\MigrationDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use Dirthara\Migration\Tests\Fixtures\MigrationLog;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\MigrationPreview;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Tests\Fixtures\LockingSQLiteDriver;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Database\Exception\ConnectionRegistryException;
use Dirthara\Migration\Exception\MigrationRollbackException;
use Dirthara\Migration\Exception\InvalidRollbackStepsException;
use Dirthara\Migration\Tests\Fixtures\ScriptedNamedLockGrammar;

use function sprintf;
use function array_map;
use function var_export;

final class MigratorPreviewTest extends TestCase
{
    use MigrationEnvironment;

    private ScriptedNamedLockGrammar $locks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locks = new ScriptedNamedLockGrammar();
        $this->setUpEnvironment(new LockingSQLiteDriver($this->locks));
        MigrationLog::$events = [];
    }

    #[Test]
    public function it_previews_every_pending_migration(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000', description: 'Creates the users');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_02_000000', connection: 'reporting');

        self::assertEquals(
            [
                new MigrationPreview(
                    name: 'CreateUsers',
                    index: '2026_01_01_000000',
                    description: 'Creates the users',
                    connection: 'primary',
                    direction: MigrationDirection::Up,
                    batch: 1,
                    path: 'users/a.php',
                ),
                new MigrationPreview(
                    name: 'CreateReports',
                    index: '2026_01_02_000000',
                    description: null,
                    connection: 'reporting',
                    direction: MigrationDirection::Up,
                    batch: 1,
                    path: 'reports/a.php',
                ),
            ],
            $this->preview(['users', 'reports']),
        );
    }

    #[Test]
    public function it_previews_migrations_in_the_order_a_run_applies_them(): void
    {
        $this->addLogged('users/c.php', 'CreatePosts', '2026_01_03_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_01_000000', connection: 'reporting');
        $this->addLogged('users/b.php', 'CreateComments', '2026_01_02_000000');
        $this->addLogged('billing/z.php', 'CreateInvoices', '2026_01_02_000000');
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $directories = ['users', 'reports', 'billing'];

        $preview = $this->preview($directories);
        $this->migrate($directories);

        self::assertSame(
            ['CreateUsers', 'CreateInvoices', 'CreateComments', 'CreatePosts', 'CreateReports'],
            $this->names($preview),
        );
        self::assertSame(
            array_map(static fn(MigrationPreview $preview): string => sprintf(
                '%s:up:%s',
                $preview->name,
                $preview->connection,
            ), $preview),
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_previews_the_connection_each_declaration_resolves_to(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000', connection: 'primary');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_01_000000', connection: 'reporting');

        self::assertSame(
            ['CreateUsers' => 'primary', 'CreatePosts' => 'primary', 'CreateReports' => 'reporting'],
            $this->connections($this->preview(['users', 'reports'])),
        );
    }

    #[Test]
    public function it_leaves_applied_migrations_out_of_the_preview_and_previews_the_next_batch(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');

        $preview = $this->preview();

        self::assertSame(['CreatePosts'], $this->names($preview));
        self::assertSame(2, $preview[0]->batch);
    }

    #[Test]
    public function it_previews_nothing_when_every_migration_was_applied(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();

        self::assertSame([], $this->preview());
    }

    #[Test]
    public function it_does_not_create_the_history_table_to_preview(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');

        self::assertSame(['CreateUsers'], $this->names($this->preview()));
        self::assertSame([], $this->previewRollback());
        self::assertSame([], $this->previewRollback(steps: 1));
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_does_not_change_the_history_to_preview(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $history = $this->repository->getApplied();

        $this->preview();
        $this->previewRollback();
        $this->previewRollback(steps: 2);

        self::assertEquals($history, $this->repository->getApplied());
    }

    #[Test]
    public function it_runs_no_migration_code_to_preview(): void
    {
        $this->addTraced('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addTraced('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        $this->addTraced('users/c.php', 'CreateComments', '2026_01_03_000000');
        MigrationLog::$events = [];

        self::assertSame(['CreateComments'], $this->names($this->preview()));
        self::assertSame(['CreatePosts', 'CreateUsers'], $this->names($this->previewRollback()));
        self::assertSame([], MigrationLog::$events);
    }

    #[Test]
    public function it_previews_a_migration_whose_hook_would_skip_it(): void
    {
        $this->addLogged(
            'users/a.php',
            'CreateUsers',
            '2026_01_01_000000',
            beforeUp: '$decision = MigrationDecision::skip();',
            beforeDown: "\$decision = MigrationDecision::stop('Keep the users');",
        );

        self::assertSame(['CreateUsers'], $this->names($this->preview()));

        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));

        self::assertSame(['CreateUsers'], $this->names($this->previewRollback()));
    }

    #[Test]
    public function it_takes_no_lock_to_preview(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate(locking: false);
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');

        $this->preview();
        $this->previewRollback();

        self::assertSame([], $this->locks->statements);
    }

    #[Test]
    public function it_previews_on_a_connection_without_named_locks_while_locking_is_enabled(): void
    {
        $this->setUpEnvironment();
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate(locking: false);
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');

        self::assertSame(['CreatePosts'], $this->names($this->preview()));
        self::assertSame(['CreateUsers'], $this->names($this->previewRollback()));
    }

    #[Test]
    public function it_previews_a_rollback_of_the_latest_batch_in_reverse_order_of_application(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000', description: 'Creates the posts');
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrate();

        self::assertEquals(
            [
                new MigrationPreview(
                    name: 'CreateComments',
                    index: '2026_01_03_000000',
                    description: null,
                    connection: 'primary',
                    direction: MigrationDirection::Down,
                    batch: 2,
                    path: 'users/c.php',
                ),
                new MigrationPreview(
                    name: 'CreatePosts',
                    index: '2026_01_02_000000',
                    description: 'Creates the posts',
                    connection: 'primary',
                    direction: MigrationDirection::Down,
                    batch: 2,
                    path: 'users/b.php',
                ),
            ],
            $this->previewRollback(),
        );
    }

    #[Test]
    public function it_previews_a_rollback_in_the_order_the_rollback_reverses_migrations(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_02_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_01_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_03_000000', connection: 'reporting');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_02_000000', batch: 1));
        $this->repository->record($this->applied('CreateReports', '2026_01_03_000000', 1, connection: 'reporting'));
        $this->repository->record($this->applied('CreatePosts', '2026_01_01_000000', batch: 1));

        $preview = $this->previewRollback(['users', 'reports']);
        $this->rollback(['users', 'reports']);

        self::assertSame(['CreatePosts', 'CreateReports', 'CreateUsers'], $this->names($preview));
        self::assertSame(
            array_map(static fn(MigrationPreview $preview): string => sprintf(
                '%s:down:%s',
                $preview->name,
                $preview->connection,
            ), $preview),
            MigrationLog::$events,
        );
    }

    #[Test]
    public function it_previews_a_rollback_of_exactly_the_latest_steps_across_batches(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('users/b.php', 'CreatePosts', '2026_01_02_000000');
        $this->migrate();
        $this->addLogged('users/c.php', 'CreateComments', '2026_01_03_000000');
        $this->migrate();

        $preview = $this->previewRollback(steps: 2);

        self::assertSame(['CreateComments', 'CreatePosts'], $this->names($preview));
        self::assertSame([2, 1], array_map(static fn(MigrationPreview $preview): int => $preview->batch, $preview));
        self::assertSame(['CreateComments'], $this->names($this->previewRollback(steps: 1)));
        self::assertSame(
            ['CreateComments', 'CreatePosts', 'CreateUsers'],
            $this->names($this->previewRollback(steps: 10)),
        );
    }

    #[Test]
    public function it_previews_a_step_rollback_across_every_connection(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addLogged('reports/a.php', 'CreateReports', '2026_01_02_000000', connection: 'reporting');
        $this->migrate(['users']);
        $this->migrate(['users', 'reports']);

        self::assertSame(
            ['CreateReports' => 'reporting'],
            $this->connections($this->previewRollback(['users', 'reports'], steps: 1)),
        );
        self::assertSame(
            ['CreateReports' => 'reporting', 'CreateUsers' => 'primary'],
            $this->connections($this->previewRollback(['users', 'reports'], steps: 2)),
        );
    }

    #[Test]
    public function it_previews_a_rollback_on_the_connection_recorded_in_the_history(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 1, connection: 'reporting'));

        self::assertSame(['CreateUsers' => 'reporting'], $this->connections($this->previewRollback()));
    }

    #[Test]
    public function it_previews_no_rollback_of_an_empty_history(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->repository->initialise();

        self::assertSame([], $this->previewRollback());
        self::assertSame([], $this->previewRollback(steps: 1));
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
    public function it_rejects_a_number_of_steps_below_one_as_a_rollback_does(int $steps): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();

        $preview = $this->expectFailure(InvalidRollbackStepsException::class, fn() => $this->previewRollback(
            steps: $steps,
        ));
        $rollback = $this->expectFailure(InvalidRollbackStepsException::class, fn() => $this->rollback(steps: $steps));

        self::assertSame($rollback->getMessage(), $preview->getMessage());
        self::assertSame(['steps' => $steps], $preview->context);
    }

    #[Test]
    public function it_fails_to_preview_an_invalid_migration_set(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        $this->addLogged('billing/a.php', 'createusers', '2026_01_02_000000');

        $forward = $this->expectFailure(MigrationPlanException::class, fn() => $this->preview(['users', 'billing']));
        $rollback = $this->expectFailure(MigrationPlanException::class, fn() => $this->previewRollback([
            'users',
            'billing',
        ]));

        self::assertSame(['CreateUsers', 'createusers'], $forward->context['names']);
        self::assertSame(['CreateUsers', 'createusers'], $rollback->context['names']);
    }

    #[Test]
    public function it_fails_to_preview_a_migration_whose_index_changed(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_02_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', batch: 1));

        $forward = $this->expectFailure(MigrationPlanException::class, $this->preview(...));
        $rollback = $this->expectFailure(MigrationPlanException::class, $this->previewRollback(...));

        self::assertSame('2026_01_01_000000', $forward->context['appliedIndex']);
        self::assertSame('2026_01_01_000000', $rollback->context['appliedIndex']);
    }

    #[Test]
    public function it_fails_to_preview_a_connection_it_cannot_resolve(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000', connection: 'archive');

        $exception = $this->expectFailure(MigrationPlanException::class, $this->preview(...));

        self::assertSame(
            ['migration' => 'CreateUsers', 'path' => 'users/a.php', 'connection' => 'archive'],
            $exception->context,
        );
        self::assertInstanceOf(ConnectionRegistryException::class, $exception->getPrevious());
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_fails_to_preview_the_rollback_of_a_migration_whose_source_is_missing(): void
    {
        $this->addLogged('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->migrate();
        unset($this->files['users/a.php']);

        $exception = $this->expectFailure(MigrationRollbackException::class, $this->previewRollback(...));

        self::assertSame(['migration' => 'CreateUsers', 'connection' => 'primary', 'batch' => 1], $exception->context);
    }

    private function addLogged(
        string $path,
        string $name,
        string $index,
        ?string $connection = null,
        ?string $description = null,
        ?string $beforeUp = null,
        ?string $beforeDown = null,
    ): void {
        $this->addMigration(
            $path,
            $name,
            $index,
            connection: $connection,
            description: $description,
            up: $this->log($name, 'up'),
            beforeUp: $beforeUp,
            down: $this->log($name, 'down'),
            beforeDown: $beforeDown,
        );
    }

    private function addTraced(string $path, string $name, string $index): void
    {
        $this->addMigration(
            $path,
            $name,
            $index,
            up: $this->log($name, 'up'),
            beforeUp: $this->log($name, 'beforeUp'),
            afterUp: $this->log($name, 'afterUp'),
            down: $this->log($name, 'down'),
            beforeDown: $this->log($name, 'beforeDown'),
            afterDown: $this->log($name, 'afterDown'),
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

    /**
     * @param list<string> $directories
     *
     * @return list<MigrationPreview>
     */
    private function preview(array $directories = ['users']): array
    {
        return $this->migrator()->preview(new MigrationConfiguration($directories));
    }

    /**
     * @param list<string> $directories
     *
     * @return list<MigrationPreview>
     */
    private function previewRollback(array $directories = ['users'], ?int $steps = null): array
    {
        return $this->migrator()->previewRollback(new MigrationConfiguration($directories), steps: $steps);
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
    private function rollback(array $directories = ['users'], ?int $steps = null): void
    {
        $this->migrator()->rollback(new MigrationConfiguration($directories), steps: $steps);
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

    /**
     * @param list<MigrationPreview> $preview
     *
     * @return list<string>
     */
    private function names(array $preview): array
    {
        return array_map(static fn(MigrationPreview $migration): string => $migration->name, $preview);
    }

    /**
     * @param list<MigrationPreview> $preview
     *
     * @return array<string, string>
     */
    private function connections(array $preview): array
    {
        $connections = [];

        foreach ($preview as $migration) {
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
