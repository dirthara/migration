<?php

declare(strict_types=1);

namespace Dirthara\Migration\Naming;

use Dirthara\Migration\Exception\MigrationCreatorException;

use function trim;
use function sprintf;
use function strtolower;
use function preg_replace;

final readonly class DefaultNamingStrategy implements NamingStrategy
{
    public function fileName(string $name, string $index): string
    {
        $words =
            preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', replacement: '_', subject: $name) ?? '';
        $snakeCase = trim(
            strtolower(preg_replace('/[^A-Za-z0-9]+/', replacement: '_', subject: $words) ?? ''),
            characters: '_',
        );

        if ($snakeCase === '') {
            throw MigrationCreatorException::invalidName($name);
        }

        return sprintf('%s_%s.php', $index, $snakeCase);
    }
}
