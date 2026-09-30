<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;
use League\Flysystem\FilesystemException;
use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\Exception\InvalidMigrationFileException;

use function usort;
use function strcmp;
use function array_map;
use function str_ends_with;

final readonly class MigrationLoader
{
    public function __construct(
        private FilesystemReader $filesystem,
        private MigrationSourceLoader $sourceLoader,
    ) {}

    /**
     * @throws InvalidMigrationFileException
     *
     * @return list<LoadedMigration>
     */
    public function loadDirectory(string $directory): array
    {
        try {
            /** @var list<string> $paths */
            $paths = $this->filesystem
                ->listContents($directory)
                ->filter(
                    static fn(StorageAttributes $item): bool => $item->isFile() && str_ends_with($item->path(), '.php'),
                )
                ->map(static fn(StorageAttributes $item): string => $item->path())
                ->toArray();
        } catch (FilesystemException $exception) {
            throw InvalidMigrationFileException::fromException($exception)->addContext(['directory' => $directory]);
        }

        $migrations = array_map($this->sourceLoader->loadFile(...), $paths);

        usort($migrations, static function (LoadedMigration $first, LoadedMigration $second): int {
            $order = strcmp($first->migration->index, $second->migration->index);

            return $order !== 0 ? $order : strcmp($first->path, $second->path);
        });

        return $migrations;
    }
}
