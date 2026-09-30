<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use SplFileInfo;
use DateTimeZone;
use DateTimeImmutable;
use FilesystemIterator;
use Psr\Clock\ClockInterface;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use League\Flysystem\UnableToWriteFile;
use Dirthara\Migration\MigrationCreator;
use League\Flysystem\FilesystemOperator;
use Dirthara\Migration\MigrationTemplate;
use Dirthara\Migration\Contract\Migration;
use Dirthara\Migration\MigrationSourceLoader;
use Dirthara\Migration\Naming\NamingStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use Dirthara\Migration\Contract\MigrationHooks;
use League\Flysystem\UnableToCheckFileExistence;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Dirthara\Migration\Naming\DefaultNamingStrategy;
use Dirthara\Migration\Tests\Fixtures\CountingClock;
use Dirthara\Migration\ValueObject\CreatedMigration;
use Dirthara\Migration\Exception\MigrationCreatorException;
use Dirthara\Migration\Tests\Fixtures\RecordingNamingStrategy;

use function mkdir;
use function rmdir;
use function unlink;
use function bin2hex;
use function sprintf;
use function array_values;
use function random_bytes;
use function preg_match_all;
use function sys_get_temp_dir;

final class MigrationCreatorTest extends TestCase
{
    private const string FILE = 'migrations/2026_09_30_101500_create_users_table.php';

    private string $root;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/dirthara-migration-creator-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_creates_a_migration_that_loads(): void
    {
        $created = $this->creator()->create(
            directory: 'migrations',
            name: 'CreateUsersTable',
            table: 'users',
            description: 'Creates the users table',
            connection: 'reporting',
            template: MigrationTemplate::Create,
        );

        self::assertEquals(
            new CreatedMigration(
                name: 'CreateUsersTable',
                index: '2026_09_30_101500',
                description: 'Creates the users table',
                connection: 'reporting',
                path: self::FILE,
            ),
            $created,
        );

        $migration = $this->load($created->path);

        self::assertSame('CreateUsersTable', $migration->name);
        self::assertSame('2026_09_30_101500', $migration->index);
        self::assertSame('Creates the users table', $migration->description);
        self::assertSame('reporting', $migration->connection);
    }

    /**
     * @return iterable<string, array{MigrationTemplate, bool, list<string>}>
     */
    public static function templatesWithATable(): iterable
    {
        $create = ["\$context->schema->createIfNotExists('users', ", "\$context->schema->dropIfExists('users');"];
        $alter = ["\$context->schema->table('users', "];

        yield 'basic' => [MigrationTemplate::Basic, false, []];
        yield 'basic with hooks' => [MigrationTemplate::BasicWithHooks, true, []];
        yield 'create' => [MigrationTemplate::Create, false, $create];
        yield 'create with hooks' => [MigrationTemplate::CreateWithHooks, true, $create];
        yield 'alter' => [MigrationTemplate::Alter, false, $alter];
        yield 'alter with hooks' => [MigrationTemplate::AlterWithHooks, true, $alter];
    }

    /**
     * @param list<string> $operations
     */
    #[Test]
    #[DataProvider('templatesWithATable')]
    public function it_creates_a_loadable_migration_from_every_template(
        MigrationTemplate $template,
        bool $hooks,
        array $operations,
    ): void {
        $created = $this->creator()->create('migrations', 'CreateUsersTable', 'users', template: $template);

        $migration = $this->load($created->path);
        $source = $this->filesystem->read($created->path);

        self::assertSame('CreateUsersTable', $migration->name);
        self::assertSame($hooks, $migration instanceof MigrationHooks);
        self::assertStringNotContainsString('{{', $source);
        self::assertSame($operations === [], $this->schemaCalls($source) === []);

        foreach ($operations as $operation) {
            self::assertStringContainsString($operation, $source);
        }
    }

    /**
     * @return iterable<string, array{MigrationTemplate, bool, string}>
     */
    public static function templatesWithoutATable(): iterable
    {
        yield 'create' => [MigrationTemplate::Create, false, 'createIfNotExists'];
        yield 'create with hooks' => [MigrationTemplate::CreateWithHooks, true, 'createIfNotExists'];
        yield 'alter' => [MigrationTemplate::Alter, false, '$context->schema->table('];
        yield 'alter with hooks' => [MigrationTemplate::AlterWithHooks, true, '$context->schema->table('];
    }

    #[Test]
    #[DataProvider('templatesWithoutATable')]
    public function it_creates_a_loadable_migration_without_a_table_for_the_developer_to_complete(
        MigrationTemplate $template,
        bool $hooks,
        string $intent,
    ): void {
        $created = $this->creator()->create('migrations', 'CreateUsersTable', template: $template);

        $migration = $this->load($created->path);
        $source = $this->filesystem->read($created->path);

        self::assertSame('CreateUsersTable', $migration->name);
        self::assertSame($hooks, $migration instanceof MigrationHooks);
        self::assertStringNotContainsString('{{', $source);
        self::assertStringContainsString($intent, $source);
        self::assertSame([], $this->schemaCalls($source));
        self::assertDoesNotMatchRegularExpression('/schema->\w+\(\s*null/i', $source);
    }

    #[Test]
    public function it_creates_a_basic_migration_without_a_table_by_default(): void
    {
        $created = $this->creator()->create('migrations', 'CreateUsersTable');

        $migration = $this->load($created->path);
        $source = $this->filesystem->read($created->path);

        self::assertNotInstanceOf(MigrationHooks::class, $migration);
        self::assertSame([], $this->schemaCalls($source));
        self::assertStringNotContainsString('Dirthara\\Schema\\Table', $source);
    }

    #[Test]
    public function it_leaves_a_table_out_of_a_basic_migration(): void
    {
        $created = $this->creator()->create('migrations', 'CreateUsersTable', 'users');

        self::assertStringNotContainsString('users', $this->filesystem->read($created->path));
    }

    #[Test]
    public function it_leaves_the_description_and_connection_out_unless_given(): void
    {
        $created = $this->creator()->create('migrations', 'CreateUsersTable', 'users');

        $migration = $this->load($created->path);

        self::assertNull($created->description);
        self::assertNull($created->connection);
        self::assertNull($migration->description);
        self::assertNull($migration->connection);
    }

    #[Test]
    public function it_writes_values_literally_even_when_they_look_like_code_or_placeholders(): void
    {
        $name = "O'Brien's {{ table }} migration";
        $description = "Line one\nLine two with {{ name }}, a \\ and a \$variable";

        $created = $this->creator()->create(
            'migrations',
            $name,
            "users'); exit; ('",
            description: $description,
            template: MigrationTemplate::Alter,
        );

        $migration = $this->load($created->path);

        self::assertSame($name, $migration->name);
        self::assertSame($description, $migration->description);
        self::assertStringContainsString("table('users\\'); exit; (\\''", $this->filesystem->read($created->path));
    }

    #[Test]
    public function it_indexes_the_migration_by_the_current_time_in_utc(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-30 12:15:00', new DateTimeZone('Europe/Amsterdam')));

        $created = $this->creator(clock: $clock)->create('migrations', 'CreateUsersTable', 'users');

        self::assertSame('2026_09_30_101500', $created->index);
        self::assertSame('2026_09_30_101500', $this->load($created->path)->index);
        self::assertSame(self::FILE, $created->path);
    }

    #[Test]
    public function it_reads_the_clock_once_per_migration(): void
    {
        $clock = new CountingClock(new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC')));

        $this->creator(clock: $clock)->create('migrations', 'CreateUsersTable', 'users');

        self::assertSame(1, $clock->reads);
    }

    #[Test]
    public function it_names_the_file_with_the_same_index_it_records_in_the_migration(): void
    {
        $naming = new RecordingNamingStrategy();

        $created = $this->creator(namingStrategy: $naming)->create('migrations', 'CreateUsersTable', 'users');

        self::assertSame([['name' => 'CreateUsersTable', 'index' => '2026_09_30_101500']], $naming->calls);
        self::assertSame('migrations/custom/CreateUsersTable-2026_09_30_101500.php', $created->path);
        self::assertSame($created->index, $this->load($created->path)->index);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directories(): iterable
    {
        yield 'root' => ['', '2026_09_30_101500_create_users_table.php'];
        yield 'root with a slash' => ['/', '2026_09_30_101500_create_users_table.php'];
        yield 'surrounding slashes' => [
            '/database/migrations/',
            'database/migrations/2026_09_30_101500_create_users_table.php',
        ];
    }

    #[Test]
    #[DataProvider('directories')]
    public function it_creates_the_migration_in_the_given_directory(string $directory, string $path): void
    {
        $created = $this->creator()->create($directory, 'CreateUsersTable', 'users');

        self::assertSame($path, $created->path);
        self::assertTrue($this->filesystem->fileExists($path));
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_migration(): void
    {
        $this->filesystem->write(self::FILE, 'existing');

        $exception = $this->failure(fn() => $this->creator()->create('migrations', 'CreateUsersTable', 'users'));

        self::assertSame(
            sprintf('Unable to create migration "%s": the file already exists.', self::FILE),
            $exception->getMessage(),
        );
        self::assertSame(['path' => self::FILE], $exception->context);
        self::assertSame('existing', $this->filesystem->read(self::FILE));
    }

    #[Test]
    public function it_rejects_a_name_it_cannot_build_a_file_name_from_before_writing_anything(): void
    {
        $exception = $this->failure(fn() => $this->creator()->create('migrations', '!?', 'users'));

        self::assertSame(['name' => '!?'], $exception->context);
        self::assertFalse($this->filesystem->directoryExists('migrations'));
    }

    #[Test]
    public function it_reports_a_template_it_cannot_read(): void
    {
        $creator = $this->creator(templateDirectory: $this->root . '/');

        $exception = $this->failure(static fn() => $creator->create('migrations', 'CreateUsersTable', 'users'));

        $path = $this->root . '/migration.php.stub';

        self::assertSame(
            sprintf('Unable to read the Basic migration template at "%s".', $path),
            $exception->getMessage(),
        );
        self::assertSame(['template' => 'Basic', 'path' => $path], $exception->context);
        self::assertFalse($this->filesystem->directoryExists('migrations'));
    }

    #[Test]
    public function it_wraps_a_failure_to_write_the_migration(): void
    {
        $failure = UnableToWriteFile::atLocation(self::FILE, 'disk full');
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(false);
        $filesystem->method('write')->willThrowException($failure);

        $exception = $this->failure(
            fn() => $this->creator(filesystem: $filesystem)->create('migrations', 'CreateUsersTable', 'users'),
        );

        self::assertSame(sprintf('Unable to write migration "%s".', self::FILE), $exception->getMessage());
        self::assertSame(['path' => self::FILE], $exception->context);
        self::assertSame($failure, $exception->getPrevious());
    }

    #[Test]
    public function it_wraps_a_failure_to_check_whether_the_migration_exists(): void
    {
        $failure = UnableToCheckFileExistence::forLocation(self::FILE);
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willThrowException($failure);

        $exception = $this->failure(
            fn() => $this->creator(filesystem: $filesystem)->create('migrations', 'CreateUsersTable', 'users'),
        );

        self::assertSame(['path' => self::FILE], $exception->context);
        self::assertSame($failure, $exception->getPrevious());
    }

    private function creator(
        ?ClockInterface $clock = null,
        ?FilesystemOperator $filesystem = null,
        ?NamingStrategy $namingStrategy = null,
        ?string $templateDirectory = null,
    ): MigrationCreator {
        $clock ??= new FrozenClock(new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC')));
        $filesystem ??= $this->filesystem;
        $namingStrategy ??= new DefaultNamingStrategy();

        return $templateDirectory === null
            ? new MigrationCreator($clock, $filesystem, $namingStrategy)
            : new MigrationCreator($clock, $filesystem, $namingStrategy, $templateDirectory);
    }

    /**
     * @return list<string>
     */
    private function schemaCalls(string $source): array
    {
        $matches = [];
        preg_match_all('/^(?!\s*\/\/).*\$context->schema->.*$/m', $source, $matches);

        return array_values($matches[0]);
    }

    private function load(string $path): Migration
    {
        return new MigrationSourceLoader($this->filesystem)->loadFile($path)->migration;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failure(callable $operation): MigrationCreatorException
    {
        try {
            $operation();
        } catch (MigrationCreatorException $exception) {
            return $exception;
        }

        self::fail(sprintf('The operation did not throw %s.', MigrationCreatorException::class));
    }
}
