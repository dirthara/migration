<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use League\Flysystem\FilesystemException;

final class InvalidMigrationFile extends RuntimeException implements MigrationException
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

    public static function fromException(FileSystemException $exception): self
    {
        return new self($exception->getMessage(), $exception->getCode(), $exception);
    }

    public static function fileNotFound(string $filePath): self
    {
        return new self(message: sprintf('Migration file "%s" not found', $filePath), context: [
            'filePath' => $filePath,
        ]);
    }

    public static function invalidFileContents(string $filePath): self
    {
        return new self(message: sprintf('The file at %s does not contain a valid migration', $filePath), context: [
            'filePath' => $filePath,
        ]);
    }
}
