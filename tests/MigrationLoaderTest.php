<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use Generator;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\FileAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemReader;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\MigrationLoader;
use League\Flysystem\StorageAttributes;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\UnableToListContents;
use Dirthara\Migration\MigrationSourceLoader;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\Exception\InvalidMigrationFile;

use function sprintf;
use function array_map;
use function array_keys;
use function array_key_exists;

final class MigrationLoaderTest extends TestCase
{
    private const string MIGRATION = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Dirthara\Migration\Contract\Migration;
        use Dirthara\Migration\ValueObject\MigrationContext;

        return new class implements Migration {
            public string $name = '%s';

            public ?string $description = null;

            public string $index = '%s';

            public ?string $connection = null;

            public function up(MigrationContext $context): void {}

            public function down(MigrationContext $context): void {}
        };
        PHP;

    #[Test]
    public function it_loads_every_migration_in_a_directory(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter(__DIR__ . '/Fixtures'));

        $loaded = $this->loader($filesystem)->loadDirectory('Migrations');

        self::assertSame(['Migrations/2026_01_01_000000_create_users.php'], $this->paths($loaded));
        self::assertSame('create_users', $loaded[0]->migration->name);
    }

    #[Test]
    public function it_loads_migrations_in_the_order_of_their_index(): void
    {
        $filesystem = $this->filesystemWith([
            'migrations/a_create_comments.php' => $this->migration('create_comments', '20260301000000'),
            'migrations/b_create_users.php' => $this->migration('create_users', '20260101000000'),
            'migrations/c_create_posts.php' => $this->migration('create_posts', '20260201000000'),
        ]);

        $loaded = $this->loader($filesystem)->loadDirectory('migrations');

        self::assertSame(['create_users', 'create_posts', 'create_comments'], $this->names($loaded));
        self::assertSame(
            ['migrations/b_create_users.php', 'migrations/c_create_posts.php', 'migrations/a_create_comments.php'],
            $this->paths($loaded),
        );
    }

    #[Test]
    public function it_orders_migrations_with_the_same_index_by_path(): void
    {
        $filesystem = $this->filesystemWith([
            'migrations/c_create_posts.php' => $this->migration('create_posts', '20260101000000'),
            'migrations/a_create_users.php' => $this->migration('create_users', '20260101000000'),
            'migrations/b_create_comments.php' => $this->migration('create_comments', '20260101000000'),
        ]);

        $loaded = $this->loader($filesystem)->loadDirectory('migrations');

        self::assertSame(['create_users', 'create_comments', 'create_posts'], $this->names($loaded));
    }

    #[Test]
    public function it_skips_directories_and_files_that_are_not_php(): void
    {
        $filesystem = $this->filesystemWith([
            'migrations/2026_01_01_000000_create_users.php' => $this->migration('create_users', '2026_01_01_000000'),
            'migrations/README.md' => '# Migrations',
        ], [new DirectoryAttributes('migrations/archive.php')]);

        $loaded = $this->loader($filesystem)->loadDirectory('migrations');

        self::assertSame(['migrations/2026_01_01_000000_create_users.php'], $this->paths($loaded));
    }

    #[Test]
    public function it_loads_nothing_from_an_empty_directory(): void
    {
        self::assertSame([], $this->loader($this->filesystemWith([]))->loadDirectory('migrations'));
    }

    #[Test]
    public function it_wraps_a_failure_to_list_the_directory(): void
    {
        $failure = UnableToListContents::atLocation('migrations', false, new RuntimeException('Connection timed out'));
        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem->method('listContents')->willReturn(new DirectoryListing($this->failingListing($failure)));

        $exception = $this->loadInvalid($this->loader($filesystem), 'migrations');

        self::assertSame($failure->getMessage(), $exception->getMessage());
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame(['directory' => 'migrations'], $exception->context);
    }

    #[Test]
    public function it_rejects_a_directory_holding_an_invalid_migration(): void
    {
        $filesystem = $this->filesystemWith([
            'migrations/2026_01_01_000000_create_users.php' => $this->migration('create_users', '2026_01_01_000000'),
            'migrations/2026_02_01_000000_broken.php' => "<?php\n\nreturn 42;",
        ]);

        $exception = $this->loadInvalid($this->loader($filesystem), 'migrations');

        self::assertSame(
            'The file at migrations/2026_02_01_000000_broken.php does not contain a valid migration',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_wraps_a_failure_to_read_a_migration(): void
    {
        $failure = UnableToReadFile::fromLocation('migrations/2026_01_01_000000_create_users.php', 'access denied');
        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem
            ->method('listContents')
            ->willReturn(new DirectoryListing([
                new FileAttributes('migrations/2026_01_01_000000_create_users.php'),
            ]));
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('read')->willThrowException($failure);

        $exception = $this->loadInvalid($this->loader($filesystem), 'migrations');

        self::assertSame($failure, $exception->getPrevious());
    }

    private function loader(FilesystemReader $filesystem): MigrationLoader
    {
        return new MigrationLoader($filesystem, new MigrationSourceLoader($filesystem));
    }

    /**
     * @param array<string, string> $files
     * @param list<StorageAttributes> $directories
     */
    private function filesystemWith(array $files, array $directories = []): FilesystemReader
    {
        $listing = [
            ...array_map(static fn(string $path): FileAttributes => new FileAttributes($path), array_keys($files)),
            ...$directories,
        ];

        $filesystem = $this->createStub(FilesystemReader::class);
        $filesystem->method('listContents')->willReturn(new DirectoryListing($listing));
        $filesystem
            ->method('fileExists')
            ->willReturnCallback(static fn(string $path): bool => array_key_exists($path, $files));
        $filesystem->method('read')->willReturnCallback(static fn(string $path): string => $files[$path]);

        return $filesystem;
    }

    /**
     * @return Generator<int, StorageAttributes>
     */
    private function failingListing(UnableToListContents $failure): Generator
    {
        yield new FileAttributes('migrations/2026_01_01_000000_create_users.php');

        throw $failure;
    }

    private function migration(string $name, string $index): string
    {
        return sprintf(self::MIGRATION, $name, $index);
    }

    /**
     * @param list<LoadedMigration> $loaded
     *
     * @return list<string>
     */
    private function names(array $loaded): array
    {
        return array_map(static fn(LoadedMigration $item): string => $item->migration->name, $loaded);
    }

    /**
     * @param list<LoadedMigration> $loaded
     *
     * @return list<string>
     */
    private function paths(array $loaded): array
    {
        return array_map(static fn(LoadedMigration $item): string => $item->path, $loaded);
    }

    private function loadInvalid(MigrationLoader $loader, string $directory): InvalidMigrationFile
    {
        try {
            $loader->loadDirectory($directory);
        } catch (InvalidMigrationFile $exception) {
            return $exception;
        }

        self::fail(sprintf('Loading "%s" did not throw %s.', $directory, InvalidMigrationFile::class));
    }
}
