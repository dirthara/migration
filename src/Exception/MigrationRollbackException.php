<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class MigrationRollbackException extends RuntimeException implements MigrationException
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

    public static function missingSource(string $name, string $connection, int $batch): self
    {
        return new self(
            message: sprintf(
                'Unable to roll back migration "%s" of batch %d on connection "%s": none of the configured migration '
                . 'directories holds its source.',
                self::printable($name),
                $batch,
                self::printable($connection),
            ),
            context: ['migration' => $name, 'connection' => $connection, 'batch' => $batch],
        );
    }
}
