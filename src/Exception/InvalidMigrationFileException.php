<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use League\Flysystem\FilesystemException;
use Dirthara\Migration\Contract\Migration;

use function sprintf;

final class InvalidMigrationFileException extends RuntimeException implements MigrationException
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

    public static function listFailed(string $directory, FilesystemException $previous): self
    {
        return new self(
            message: sprintf('Unable to list the migration files in directory "%s".', self::printable($directory)),
            previous: $previous,
            context: ['directory' => $directory],
        );
    }

    public static function fileNotFound(string $filePath): self
    {
        return new self(
            message: sprintf(
                'Unable to load migration file "%s": the file does not exist.',
                self::printable($filePath),
            ),
            context: ['filePath' => $filePath],
        );
    }

    public static function readFailed(string $filePath, FilesystemException $previous): self
    {
        return new self(
            message: sprintf('Unable to read migration file "%s".', self::printable($filePath)),
            previous: $previous,
            context: ['filePath' => $filePath],
        );
    }

    public static function loadFailed(string $filePath, Throwable $previous): self
    {
        return new self(
            message: sprintf(
                'Unable to load migration file "%s": loading it threw %s.',
                self::printable($filePath),
                self::printable($previous::class),
            ),
            previous: $previous,
            context: ['filePath' => $filePath],
        );
    }

    public static function invalidFileContents(string $filePath): self
    {
        return new self(
            message: sprintf(
                'Unable to load migration file "%s": it does not return an object implementing %s.',
                self::printable($filePath),
                Migration::class,
            ),
            context: ['filePath' => $filePath],
        );
    }
}
