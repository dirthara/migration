<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\Exception\MigrationPlanException;

use function trim;
use function count;
use function array_map;

/**
 * @internal
 */
final readonly class MigrationSetValidator
{
    /**
     * @param list<LoadedMigration> $loaded
     *
     * @throws MigrationPlanException
     *
     * @return array<string, LoadedMigration>
     */
    public function validate(array $loaded): array
    {
        $byName = [];

        foreach ($loaded as $migration) {
            $name = $migration->migration->name;
            $connection = $migration->migration->connection;

            if (!MigrationName::isValid($name)) {
                throw MigrationPlanException::invalidName($name, $migration->path);
            }

            if (trim($migration->migration->index) === '') {
                throw MigrationPlanException::emptyIndex($name, $migration->path);
            }

            if ($connection !== null && trim($connection) === '') {
                throw MigrationPlanException::emptyConnection($name, $migration->path);
            }

            $byName[MigrationName::canonical($name)][] = $migration;
        }

        $migrations = [];

        foreach ($byName as $name => $named) {
            if (count($named) > 1) {
                throw MigrationPlanException::duplicateName(
                    array_map(static fn(LoadedMigration $migration): string => $migration->migration->name, $named),
                    array_map(static fn(LoadedMigration $migration): string => $migration->path, $named),
                );
            }

            $migrations[$name] = $named[0];
        }

        return $migrations;
    }
}
