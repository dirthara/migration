<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use DateTimeZone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\MigrationPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Migration\ValueObject\AppliedMigration;
use Dirthara\Migration\ValueObject\PendingMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Tests\Fixtures\MigrationEnvironment;
use Dirthara\Database\Exception\ConnectionRegistryException;

use function sprintf;
use function array_map;

final class MigrationPlannerTest extends TestCase
{
    use MigrationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEnvironment();
    }

    #[Test]
    public function it_plans_nothing_without_directories(): void
    {
        self::assertSame([], $this->plan([]));
        self::assertTrue($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_combines_the_migrations_of_every_directory_in_index_order_per_connection(): void
    {
        $this->addMigration('users/c.php', 'create_users', '2026_01_03_000000');
        $this->addMigration('orders/a.php', 'create_orders', '2026_01_01_000000');
        $this->addMigration('billing/b.php', 'create_invoices', '2026_01_02_000000');

        $plan = $this->plan(['users', 'orders', 'billing']);

        self::assertSame(['primary' => ['create_orders', 'create_invoices', 'create_users']], $this->names($plan));
    }

    #[Test]
    public function it_orders_migrations_with_the_same_index_by_path(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->addMigration('billing/z.php', 'create_invoices', '2026_01_01_000000');
        $this->addMigration('billing/a.php', 'create_payments', '2026_01_01_000000');

        $plan = $this->plan(['users', 'billing']);

        self::assertSame(['primary' => ['create_payments', 'create_invoices', 'create_users']], $this->names($plan));
    }

    #[Test]
    public function it_groups_migrations_by_the_connection_they_resolve_to(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_02_000000');
        $this->addMigration('reports/a.php', 'create_reports', '2026_01_01_000000', connection: 'reporting');
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_03_000000', connection: 'primary');

        $plan = $this->plan(['users', 'reports']);

        self::assertSame(
            ['primary' => ['create_users', 'create_profiles'], 'reporting' => ['create_reports']],
            $this->names($plan),
        );
        self::assertSame('primary', $plan['primary'][0]->connection);
        self::assertSame('primary', $plan['primary'][0]->context->database->connection()->name());
        self::assertSame('reporting', $plan['reporting'][0]->connection);
        self::assertSame('reporting', $plan['reporting'][0]->context->database->connection()->name());
        self::assertSame($plan['primary'][0]->context, $plan['primary'][1]->context);
    }

    #[Test]
    public function it_rejects_a_name_used_in_two_directories_before_reading_the_history(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->addMigration('billing/a.php', 'create_users', '2026_01_02_000000');

        $exception = $this->failure(['users', 'billing']);

        self::assertSame(
            'More than one migration uses the name "create_users", which is compared case-insensitively: '
            . '"create_users" at "users/a.php", "create_users" at "billing/a.php".',
            $exception->getMessage(),
        );
        self::assertSame(
            [
                'migration' => 'create_users',
                'names' => ['create_users', 'create_users'],
                'paths' => ['users/a.php', 'billing/a.php'],
            ],
            $exception->context,
        );
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blanks(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'StudlyCase' => ['CreateUsers'];
        yield 'snake_case' => ['create_users'];
        yield 'long' => ['AddStatusToInvoices'];
        yield 'digits' => ['Region2Migration'];
        yield 'single letter' => ['A'];
    }

    #[Test]
    #[DataProvider('validNames')]
    public function it_accepts_a_valid_migration_name(string $name): void
    {
        $this->addMigration('users/a.php', $name, '2026_01_01_000000');

        self::assertSame(['primary' => [$name]], $this->names($this->plan(['users'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'space' => ['create users'];
        yield 'hyphen' => ['create-users'];
        yield 'leading underscore' => ['_create_users'];
        yield 'leading digits' => ['123_create_users'];
        yield 'dot' => ['create.users'];
        yield 'accented letters' => ['résumé'];
        yield 'punctuation' => ['CreateUsers!'];
        yield 'surrounding whitespace' => [' CreateUsers '];
        yield 'trailing newline' => ["CreateUsers\n"];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_an_invalid_migration_name(string $name): void
    {
        $this->addMigration('users/a.php', $name, '2026_01_01_000000');

        $exception = $this->failure(['users']);

        self::assertStringStartsWith('Migration name "', $exception->getMessage());
        self::assertStringContainsString('at "users/a.php" is invalid', $exception->getMessage());
        self::assertSame(['migration' => $name, 'path' => 'users/a.php'], $exception->context);
        self::assertFalse($this->schema->hasTable(self::HISTORY_TABLE));
    }

    #[Test]
    public function it_rejects_names_that_differ_only_in_case_across_directories(): void
    {
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addMigration('billing/b.php', 'createusers', '2026_01_02_000000');

        $exception = $this->failure(['users', 'billing']);

        self::assertSame(
            'More than one migration uses the name "CreateUsers", which is compared case-insensitively: '
            . '"CreateUsers" at "users/a.php", "createusers" at "billing/b.php".',
            $exception->getMessage(),
        );
        self::assertSame(
            [
                'migration' => 'CreateUsers',
                'names' => ['CreateUsers', 'createusers'],
                'paths' => ['users/a.php', 'billing/b.php'],
            ],
            $exception->context,
        );
    }

    #[Test]
    public function it_keeps_similar_but_different_names_apart(): void
    {
        $this->addMigration('users/a.php', 'CreateUsers', '2026_01_01_000000');
        $this->addMigration('billing/b.php', 'CreateUsersTable', '2026_01_02_000000');
        $this->addMigration('billing/c.php', 'Create_Users', '2026_01_03_000000');

        self::assertSame(
            ['primary' => ['CreateUsers', 'CreateUsersTable', 'Create_Users']],
            $this->names($this->plan(['users', 'billing'])),
        );
    }

    #[Test]
    #[DataProvider('blanks')]
    public function it_rejects_a_migration_without_an_index(string $index): void
    {
        $this->addMigration('users/a.php', 'create_users', $index);

        $exception = $this->failure(['users']);

        self::assertSame('Migration "create_users" at "users/a.php" has an empty index.', $exception->getMessage());
        self::assertSame(['migration' => 'create_users', 'path' => 'users/a.php'], $exception->context);
    }

    #[Test]
    #[DataProvider('blanks')]
    public function it_rejects_an_explicit_connection_without_a_name(string $connection): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', connection: $connection);

        $exception = $this->failure(['users']);

        self::assertSame(
            'Migration "create_users" at "users/a.php" names an empty connection; use null for the default connection.',
            $exception->getMessage(),
        );
        self::assertSame(['migration' => 'create_users', 'path' => 'users/a.php'], $exception->context);
    }

    #[Test]
    public function it_wraps_a_connection_it_cannot_resolve(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', connection: 'archive');

        $exception = $this->failure(['users']);

        self::assertSame(
            'Unable to resolve the connection "archive" for migration "create_users" at "users/a.php".',
            $exception->getMessage(),
        );
        self::assertSame(
            ['migration' => 'create_users', 'path' => 'users/a.php', 'connection' => 'archive'],
            $exception->context,
        );
        self::assertInstanceOf(ConnectionRegistryException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_leaves_out_migrations_that_were_already_applied(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->addMigration('users/b.php', 'create_profiles', '2026_01_02_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', '2026_01_01_000000', 'primary'));

        self::assertSame(['primary' => ['create_profiles']], $this->names($this->plan(['users'])));
    }

    #[Test]
    public function it_plans_nothing_when_every_migration_was_applied(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', '2026_01_01_000000', 'primary'));

        self::assertSame([], $this->plan(['users']));
    }

    #[Test]
    public function it_ignores_a_changed_description_of_an_applied_migration(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000', description: 'Creates the users');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', '2026_01_01_000000', 'primary'));

        self::assertSame([], $this->plan(['users']));
    }

    #[Test]
    public function it_ignores_applied_migrations_that_no_longer_exist(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_legacy_accounts', '2025_01_01_000000', 'primary'));

        self::assertSame(['primary' => ['create_users']], $this->names($this->plan(['users'])));
    }

    #[Test]
    public function it_recognises_an_applied_migration_whatever_the_case_of_its_name(): void
    {
        $this->addMigration('users/a.php', 'createusers', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 'primary'));

        self::assertSame([], $this->plan(['users']));
    }

    #[Test]
    public function it_checks_an_applied_migration_found_under_another_case_for_drift(): void
    {
        $this->addMigration('users/a.php', 'createusers', '2026_02_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 'primary'));

        self::assertSame('2026_01_01_000000', $this->failure(['users'])->context['appliedIndex']);
    }

    #[Test]
    public function it_rejects_a_history_holding_names_that_differ_only_in_case(): void
    {
        $this->addMigration('users/a.php', 'CreateProfiles', '2026_01_03_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('CreateUsers', '2026_01_01_000000', 'primary'));
        $this->repository->record($this->applied('createusers', '2026_01_02_000000', 'primary'));

        $exception = $this->failure(['users']);

        self::assertSame(
            'The migration history holds both "CreateUsers" and "createusers", which name the same migration because '
            . 'migration names are compared case-insensitively.',
            $exception->getMessage(),
        );
        self::assertSame(['migration' => 'CreateUsers', 'conflictingMigration' => 'createusers'], $exception->context);
    }

    #[Test]
    public function it_rejects_an_applied_migration_whose_index_changed(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_02_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', '2026_01_01_000000', 'primary'));

        $exception = $this->failure(['users']);

        self::assertSame(
            'Migration "create_users" at "users/a.php" has index "2026_02_01_000000", but it was applied with index '
            . '"2026_01_01_000000".',
            $exception->getMessage(),
        );
        self::assertSame(
            [
                'migration' => 'create_users',
                'path' => 'users/a.php',
                'index' => '2026_02_01_000000',
                'appliedIndex' => '2026_01_01_000000',
            ],
            $exception->context,
        );
    }

    #[Test]
    public function it_rejects_an_applied_migration_whose_resolved_connection_changed(): void
    {
        $this->addMigration('users/a.php', 'create_users', '2026_01_01_000000');
        $this->repository->initialise();
        $this->repository->record($this->applied('create_users', '2026_01_01_000000', 'reporting'));

        $exception = $this->failure(['users']);

        self::assertSame(
            'Migration "create_users" at "users/a.php" runs on connection "primary", but it was applied on connection '
            . '"reporting".',
            $exception->getMessage(),
        );
        self::assertSame(
            [
                'migration' => 'create_users',
                'path' => 'users/a.php',
                'connection' => 'primary',
                'appliedConnection' => 'reporting',
            ],
            $exception->context,
        );
    }

    /**
     * @param list<string> $directories
     *
     * @return array<string, list<PendingMigration>>
     */
    private function plan(array $directories): array
    {
        return $this->planner()->plan(new MigrationConfiguration($directories));
    }

    private function planner(): MigrationPlanner
    {
        return new MigrationPlanner($this->loader, $this->repository, $this->database, $this->schema);
    }

    /**
     * @param array<string, list<PendingMigration>> $plan
     *
     * @return array<string, list<string>>
     */
    private function names(array $plan): array
    {
        return array_map(static fn(array $migrations): array => array_map(
            static fn(PendingMigration $migration): string => $migration->migration->migration->name,
            $migrations,
        ), $plan);
    }

    /**
     * @param list<string> $directories
     */
    private function failure(array $directories): MigrationPlanException
    {
        try {
            $this->plan($directories);
        } catch (MigrationPlanException $exception) {
            return $exception;
        }

        self::fail(sprintf('Planning did not throw %s.', MigrationPlanException::class));
    }

    private function applied(string $name, string $index, string $connection): AppliedMigration
    {
        return new AppliedMigration(
            name: $name,
            index: $index,
            description: null,
            connection: $connection,
            batch: 1,
            appliedAt: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        );
    }
}
