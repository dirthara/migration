<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Schema\Exception\SchemaException;

use function sprintf;

final class MigrationFreshException extends RuntimeException implements MigrationException
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

    public static function resetFailed(string $connection, SchemaException $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to run the migrations fresh: the tables of connection "%s" could not be dropped.',
                self::printable($connection),
            ),
            previous: $previous,
            context: ['connection' => $connection],
        );
    }
}
