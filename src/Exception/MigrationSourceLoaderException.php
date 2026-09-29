<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;

use function sprintf;

final class MigrationSourceLoaderException extends RuntimeException implements MigrationException
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

    public static function schemeAlreadyRegistered(string $scheme): self
    {
        return new self(
            message: sprintf(
                'Unable to load migrations: another stream wrapper is already registered for the "%s://" scheme.',
                self::printable($scheme),
            ),
            context: ['scheme' => $scheme],
        );
    }

    public static function registrationFailed(string $scheme): self
    {
        return new self(
            message: sprintf(
                'Unable to load migrations: the stream wrapper for the "%s://" scheme could not be registered.',
                self::printable($scheme),
            ),
            context: ['scheme' => $scheme],
        );
    }
}
