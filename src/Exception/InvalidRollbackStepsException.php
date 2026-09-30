<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use InvalidArgumentException;

use function sprintf;

final class InvalidRollbackStepsException extends InvalidArgumentException implements MigrationException
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

    public static function notPositive(int $steps): self
    {
        return new self(
            message: sprintf(
                'Unable to roll back %d steps: the number of steps must be at least 1, or null to roll back the latest '
                . 'batch.',
                $steps,
            ),
            context: ['steps' => $steps],
        );
    }
}
