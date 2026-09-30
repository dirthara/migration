<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;

use function count;
use function implode;
use function sprintf;
use function array_map;

final class MigrationRefreshException extends RuntimeException implements MigrationException
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

    /**
     * @param non-empty-list<string> $migrations
     */
    public static function rollbackIncomplete(array $migrations): self
    {
        return new self(
            message: sprintf(
                'Unable to refresh the migrations: a rollback hook skipped or stopped the rollback, so %d %s still '
                . 'applied: %s. No migration was run again.',
                count($migrations),
                count($migrations) === 1 ? 'migration is' : 'migrations are',
                implode(', ', array_map(static fn(string $migration): string => sprintf(
                    '"%s"',
                    self::printable($migration),
                ), $migrations)),
            ),
            context: ['migrations' => $migrations],
        );
    }
}
