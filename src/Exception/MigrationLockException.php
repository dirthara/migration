<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Database\Exception\DatabaseException;
use Dirthara\Database\Exception\UnsupportedLockException;

use function sprintf;

final class MigrationLockException extends RuntimeException implements MigrationException
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

    public static function lockingUnsupported(
        string $lock,
        string $connection,
        UnsupportedLockException $previous,
    ): self {
        return new self(
            message: sprintf(
                'Unable to lock migration runs with lock "%s": connection "%s" does not support named locks. Disable '
                . 'locking in the migration configuration to run migrations without a lock.',
                self::printable($lock),
                self::printable($connection),
            ),
            previous: $previous,
            context: ['lock' => $lock, 'connection' => $connection],
        );
    }

    public static function acquireFailed(string $lock, string $connection, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to acquire migration lock "%s" on connection "%s".',
                self::printable($lock),
                self::printable($connection),
            ),
            previous: $previous,
            context: ['lock' => $lock, 'connection' => $connection],
        );
    }

    public static function releaseFailed(string $lock, string $connection, DatabaseException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to release migration lock "%s" on connection "%s".',
                self::printable($lock),
                self::printable($connection),
            ),
            previous: $previous,
            context: ['lock' => $lock, 'connection' => $connection],
        );
    }
}
