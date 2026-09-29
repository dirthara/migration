<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use RuntimeException;
use Dirthara\Migration\Exception\MigrationException;
use Dirthara\Migration\Exception\HasExceptionContext;

final class ContextualException extends RuntimeException implements MigrationException
{
    use HasExceptionContext;

    public static function describe(string $value): string
    {
        return self::printable($value);
    }
}
