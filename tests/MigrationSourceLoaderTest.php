<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use ParseError;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemReader;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\Contract\Migration;
use Dirthara\Migration\MigrationSourceLoader;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Dirthara\Migration\Tests\Fixtures\ForeignStreamWrapper;
use Dirthara\Migration\Exception\InvalidMigrationFileException;
use Dirthara\Migration\Exception\MigrationSourceLoaderException;

use function fopen;
use function sprintf;
use function file_get_contents;
use function set_error_handler;
use function stream_get_wrappers;
use function restore_error_handler;
use function stream_wrapper_register;

final class MigrationSourceLoaderTest extends TestCase
{
    private const string MIGRATION = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Dirthara\Migration\Contract\Migration;
        use Dirthara\Migration\ValueObject\MigrationContext;

        return new class implements Migration {
            public string $name = 'create_posts';

            public ?string $description = __FILE__;

            public string $index = '2026_02_01_000000';

            public ?string $connection = 'remote';

            public function up(MigrationContext $context): void {}

            public function down(MigrationContext $context): void {}
        };
        PHP;

    #[Test]
    public function it_loads_a_migration_from_a_filesystem(): void
    {
        $loader = new MigrationSourceLoader(new Filesystem(new LocalFilesystemAdapter(__DIR__
        . '/Fixtures/Migrations')));

        $loaded = $loader->loadFile('2026_01_01_000000_create_users.php');

        self::assertSame('2026_01_01_000000_create_users.php', $loaded->path);
        self::assertSame('create_users', $loaded->migration->name);
        self::assertSame('2026_01_01_000000', $loaded->migration->index);
        self::assertNull($loaded->migration->connection);
    }

    #[Test]
    public function it_loads_a_migration_that_is_not_on_the_local_disk(): void
    {
        $loader = new MigrationSourceLoader($this->filesystemWith('migrations/create_posts.php', self::MIGRATION));

        $loaded = $loader->loadFile('migrations/create_posts.php');

        self::assertSame('migrations/create_posts.php', $loaded->path);
        self::assertSame('create_posts', $loaded->migration->name);
        self::assertSame('remote', $loaded->migration->connection);
    }

    #[Test]
    public function it_names_the_migration_file_by_its_own_path(): void
    {
        $loader = new MigrationSourceLoader($this->filesystemWith('/migrations/create_posts.php', self::MIGRATION));

        $loaded = $loader->loadFile('/migrations/create_posts.php');

        self::assertSame('/migrations/create_posts.php', $loaded->path);
        self::assertSame('dirthara-migration://migrations/create_posts.php', $loaded->migration->description);
    }

    #[Test]
    public function it_returns_a_new_migration_on_every_load(): void
    {
        $filesystem = $this->filesystemWith('create_posts.php', self::MIGRATION);

        $first = new MigrationSourceLoader($filesystem)->loadFile('create_posts.php');
        $second = new MigrationSourceLoader($filesystem)->loadFile('create_posts.php');

        self::assertInstanceOf(Migration::class, $second->migration);
        self::assertNotSame($first->migration, $second->migration);
    }

    #[Test]
    public function it_rejects_a_missing_file(): void
    {
        $loader = new MigrationSourceLoader($this->filesystemWith('create_posts.php', self::MIGRATION));

        $exception = $this->loadInvalid($loader, 'missing.php');

        self::assertSame('Migration file "missing.php" not found', $exception->getMessage());
        self::assertSame(['filePath' => 'missing.php'], $exception->context);
    }

    #[Test]
    public function it_wraps_a_failure_to_read_the_file(): void
    {
        $failure = UnableToReadFile::fromLocation('create_posts.php', 'access denied');
        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('read')->willThrowException($failure);

        $exception = $this->loadInvalid(new MigrationSourceLoader($filesystem), 'create_posts.php');

        self::assertSame($failure->getMessage(), $exception->getMessage());
        self::assertSame($failure, $exception->getPrevious());
    }

    #[Test]
    public function it_rejects_a_file_that_does_not_compile(): void
    {
        $loader = new MigrationSourceLoader($this->filesystemWith('broken.php', "<?php\n\nreturn new class {"));

        $exception = $this->loadInvalid($loader, 'broken.php');
        $previous = $exception->getPrevious();

        self::assertSame(
            'The migration file "broken.php" could not be loaded: Unclosed \'{\'',
            $exception->getMessage(),
        );
        self::assertSame(['filePath' => 'broken.php'], $exception->context);
        self::assertInstanceOf(ParseError::class, $previous);
        self::assertSame('dirthara-migration://broken.php', $previous->getFile());
        self::assertSame(3, $previous->getLine());
    }

    #[Test]
    public function it_rejects_a_file_that_throws_while_it_loads(): void
    {
        $source = "<?php\n\nthrow new RuntimeException('Not today');";
        $loader = new MigrationSourceLoader($this->filesystemWith('throws.php', $source));

        $exception = $this->loadInvalid($loader, 'throws.php');

        self::assertSame('The migration file "throws.php" could not be loaded: Not today', $exception->getMessage());
        self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
    }

    #[Test]
    public function it_rejects_a_file_that_does_not_return_a_migration(): void
    {
        $loader = new MigrationSourceLoader($this->filesystemWith('object.php', "<?php\n\nreturn new stdClass();"));

        $exception = $this->loadInvalid($loader, 'object.php');

        self::assertSame('The file at object.php does not contain a valid migration', $exception->getMessage());
        self::assertSame(['filePath' => 'object.php'], $exception->context);
    }

    #[Test]
    public function it_serves_a_source_only_while_it_loads(): void
    {
        new MigrationSourceLoader($this->filesystemWith('create_posts.php', self::MIGRATION))->loadFile(
            'create_posts.php',
        );

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $stream = fopen('dirthara-migration://create_posts.php', mode: 'r');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($stream);
        self::assertNotSame([], $warnings);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_registers_its_own_stream_wrapper_when_the_scheme_is_free(): void
    {
        self::assertNotContains('dirthara-migration', stream_get_wrappers());

        $loader = new MigrationSourceLoader($this->filesystemWith('create_posts.php', self::MIGRATION));

        $first = $loader->loadFile('create_posts.php');

        self::assertContains('dirthara-migration', stream_get_wrappers());

        $second = $loader->loadFile('create_posts.php');

        self::assertSame('create_posts', $first->migration->name);
        self::assertSame('dirthara-migration://create_posts.php', $second->migration->description);
        self::assertNotSame($first->migration, $second->migration);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_refuses_a_stream_wrapper_another_component_registered_for_its_scheme(): void
    {
        self::assertTrue(stream_wrapper_register('dirthara-migration', ForeignStreamWrapper::class));

        $loader = new MigrationSourceLoader($this->filesystemWith('create_posts.php', self::MIGRATION));

        $exception = $this->refusal($loader, 'create_posts.php');

        self::assertSame(['scheme' => 'dirthara-migration'], $exception->context);
        self::assertSame(['scheme' => 'dirthara-migration'], $this->refusal($loader, 'create_posts.php')->context);
        self::assertSame([], ForeignStreamWrapper::$opened);
        self::assertContains('dirthara-migration', stream_get_wrappers());
        self::assertSame(ForeignStreamWrapper::CONTENTS, file_get_contents('dirthara-migration://probe.php'));
        self::assertSame(['dirthara-migration://probe.php'], ForeignStreamWrapper::$opened);
    }

    private function filesystemWith(string $path, string $source): FilesystemReader
    {
        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem->method('fileExists')->willReturnCallback(static fn(string $location): bool => $location === $path);
        $filesystem->method('read')->willReturn($source);

        return $filesystem;
    }

    private function loadInvalid(MigrationSourceLoader $loader, string $filePath): InvalidMigrationFileException
    {
        try {
            $loader->loadFile($filePath);
        } catch (InvalidMigrationFileException $exception) {
            return $exception;
        }

        self::fail(sprintf('Loading "%s" did not throw %s.', $filePath, InvalidMigrationFileException::class));
    }

    private function refusal(MigrationSourceLoader $loader, string $filePath): MigrationSourceLoaderException
    {
        try {
            $loader->loadFile($filePath);
        } catch (MigrationSourceLoaderException $exception) {
            return $exception;
        }

        self::fail(sprintf('Loading "%s" did not throw %s.', $filePath, MigrationSourceLoaderException::class));
    }
}
