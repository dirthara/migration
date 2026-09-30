<?php

declare(strict_types=1);

namespace Dirthara\Migration\Naming;

use Dirthara\Migration\Exception\MigrationCreatorException;

interface NamingStrategy
{
    /**
     * @throws MigrationCreatorException
     */
    public function fileName(string $name, string $index): string;
}
