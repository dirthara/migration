<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use Dirthara\Migration\ValueObject\LoadedMigration;
use Dirthara\Migration\Config\MigrationConfiguration;
use Dirthara\Migration\Exception\MigrationPlanException;
use Dirthara\Migration\Exception\InvalidMigrationFileException;

use function array_map;
use function array_merge;

/**
 * Loads the migrations of every configured directory into one set and checks the set as a whole. Every operation
 * loads the set once and hands it to the planners, so the migrations it plans are the migrations it runs.
 *
 * @internal
 */
final readonly class MigrationSetLoader
{
    private MigrationSetValidator $validator;

    public function __construct(
        private MigrationLoader $loader,
    ) {
        $this->validator = new MigrationSetValidator();
    }

    /**
     * @throws InvalidMigrationFileException
     * @throws MigrationPlanException
     *
     * @return array<string, LoadedMigration>
     */
    public function load(MigrationConfiguration $configuration): array
    {
        return $this->validator->validate(array_merge(...array_map(
            $this->loader->loadDirectory(...),
            $configuration->directories,
        )));
    }
}
