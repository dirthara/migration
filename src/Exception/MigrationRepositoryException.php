<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Schema\Exceptions\SchemaException;
use Dirthara\Database\Exceptions\DatabaseException;

use function sprintf;

final class MigrationRepositoryException extends RuntimeException implements MigrationException
{
    use HasExceptionContext;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    public static function initialiseFailed(string $table, SchemaException $previous): self
    {
        return new self(
            message: sprintf('Unable to create the migration table "%s".', self::printable($table)),
            previous: $previous,
            context: ['table' => $table],
        );
    }

    public static function recordFailed(string $table, string $migration, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to record migration "%s" in the migration table "%s".',
                self::printable($migration),
                self::printable($table),
            ),
            previous: $previous,
            context: ['table' => $table, 'migration' => $migration],
        );
    }

    public static function forgetFailed(string $table, string $migration, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to remove migration "%s" from the migration table "%s".',
                self::printable($migration),
                self::printable($table),
            ),
            previous: $previous,
            context: ['table' => $table, 'migration' => $migration],
        );
    }

    public static function lookupFailed(string $table, string $migration, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to check whether migration "%s" has run in the migration table "%s".',
                self::printable($migration),
                self::printable($table),
            ),
            previous: $previous,
            context: ['table' => $table, 'migration' => $migration],
        );
    }

    public static function nextBatchFailed(string $table, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to determine the next batch from the migration table "%s".',
                self::printable($table),
            ),
            previous: $previous,
            context: ['table' => $table],
        );
    }
}
