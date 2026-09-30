<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Migration\MigrationTemplate;
use League\Flysystem\FilesystemException;

use function sprintf;

final class MigrationCreatorException extends RuntimeException implements MigrationException
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

    public static function invalidName(string $name): self
    {
        return new self(
            message: sprintf(
                'The migration name "%s" contains no letters or digits to build a file name from.',
                self::printable($name),
            ),
            context: ['name' => $name],
        );
    }

    public static function templateUnavailable(MigrationTemplate $template, string $path): self
    {
        return new self(
            message: sprintf(
                'Unable to read the %s migration template at "%s".',
                $template->name,
                self::printable($path),
            ),
            context: ['template' => $template->name, 'path' => $path],
        );
    }

    public static function migrationAlreadyExists(string $path): self
    {
        return new self(
            message: sprintf('Unable to create migration "%s": the file already exists.', self::printable($path)),
            context: ['path' => $path],
        );
    }

    public static function writeFailed(string $path, FilesystemException $previous): self
    {
        return new self(
            message: sprintf('Unable to write migration "%s".', self::printable($path)),
            previous: $previous,
            context: ['path' => $path],
        );
    }
}
