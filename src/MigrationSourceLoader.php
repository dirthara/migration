<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Closure;
use Throwable;
use League\Flysystem\FilesystemReader;
use League\Flysystem\FilesystemException;
use Dirthara\Migration\Contract\Migration;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\Exception\InvalidMigrationFileException;
use Dirthara\Migration\Exception\MigrationSourceLoaderException;

use function ltrim;
use function strlen;
use function substr;
use function sprintf;
use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;

/**
 * Loads a migration file from any Flysystem filesystem, local or remote.
 *
 * A migration file returns its migration as an anonymous class, so it has to be required rather than parsed.
 * Require only accepts a path, so the source is served to it through a stream wrapper instead of being copied to a
 * temporary file. Nothing touches the local disk, and `__FILE__`, `__DIR__`, and error messages name the migration's
 * own path, such as `dirthara-migration://2026_01_01_create_users.php`.
 */
final class MigrationSourceLoader
{
    private const string SCHEME = 'dirthara-migration';

    /**
     * @var array<string, string>
     */
    private static array $sources = [];

    private static bool $registered = false;

    public function __construct(
        private readonly FilesystemReader $filesystem,
    ) {}

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationSourceLoaderException
     */
    public function loadFile(string $filePath): LoadedMigration
    {
        try {
            if (!$this->filesystem->fileExists($filePath)) {
                throw InvalidMigrationFileException::fileNotFound($filePath);
            }

            $source = $this->filesystem->read($filePath);
        } catch (FilesystemException $exception) {
            throw InvalidMigrationFileException::fromException($exception);
        }

        $this->registerWrapper();

        $uri = self::SCHEME . '://' . ltrim($filePath, characters: '/');

        self::$sources[$uri] = $source;

        try {
            // @mago-expect analysis:mixed-assignment
            $migration = require $uri;
        } catch (Throwable $exception) {
            throw new InvalidMigrationFileException(
                message: sprintf(
                    'The migration file "%s" could not be loaded: %s',
                    $filePath,
                    $exception->getMessage(),
                ),
                previous: $exception,
                context: ['filePath' => $filePath],
            );
        } finally {
            unset(self::$sources[$uri]);
        }

        if (!$migration instanceof Migration) {
            throw InvalidMigrationFileException::invalidFileContents($filePath);
        }

        return new LoadedMigration(migration: $migration, path: $filePath);
    }

    /**
     * @throws MigrationSourceLoaderException
     */
    private function registerWrapper(): void
    {
        if (self::$registered) {
            return;
        }

        $wrapper = new class {
            /**
             * @var null|Closure(string): ?string
             */
            public static ?Closure $sources = null;

            public mixed $context = null;

            private string $source = '';

            private int $position = 0;

            // @mago-expect lint:method-name
            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                $source = self::$sources === null ? null : (self::$sources)($path);

                if ($source === null) {
                    return false;
                }

                $this->source = $source;

                return true;
            }

            // @mago-expect lint:method-name
            public function stream_read(int $count): string
            {
                $chunk = substr($this->source, $this->position, $count);
                $this->position += strlen($chunk);

                return $chunk;
            }

            // @mago-expect lint:method-name
            public function stream_eof(): bool
            {
                return $this->position >= strlen($this->source);
            }

            /**
             * @return array{size: int}
             */
            // @mago-expect lint:method-name
            public function stream_stat(): array
            {
                return ['size' => strlen($this->source)];
            }

            // @mago-expect lint:method-name
            public function stream_set_option(int $option, int $value, ?int $parameter): bool
            {
                return false;
            }

            /**
             * @return array{mode: int, size: int}|false
             */
            // @mago-expect lint:method-name
            public function url_stat(string $path, int $flags): array|false
            {
                $source = self::$sources === null ? null : (self::$sources)($path);

                return $source === null ? false : ['mode' => 0o100_444, 'size' => strlen($source)];
            }
        };

        $wrapper::$sources = static fn(string $uri): ?string => self::$sources[$uri] ?? null;

        if (in_array(self::SCHEME, stream_get_wrappers(), strict: true)) {
            throw MigrationSourceLoaderException::schemeAlreadyRegistered(self::SCHEME);
        }

        if (!stream_wrapper_register(self::SCHEME, $wrapper::class)) {
            throw MigrationSourceLoaderException::registrationFailed(self::SCHEME); // @codeCoverageIgnore
        }

        self::$registered = true;
    }
}
