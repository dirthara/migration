<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;
use RuntimeException;
use Dirthara\Schema\Exception\SchemaException;
use Dirthara\Database\Exception\DatabaseException;

use function implode;
use function sprintf;
use function array_map;

final class MigrationPlanException extends RuntimeException implements MigrationException
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

    public static function invalidName(string $name, string $path): self
    {
        return new self(
            message: sprintf(
                'Migration name "%s" at "%s" is invalid: a migration name starts with an ASCII letter and contains only '
                . 'ASCII letters, digits, and underscores.',
                self::printable($name),
                self::printable($path),
            ),
            context: ['migration' => $name, 'path' => $path],
        );
    }

    public static function emptyIndex(string $name, string $path): self
    {
        return new self(
            message: sprintf(
                'Migration "%s" at "%s" has an empty index.',
                self::printable($name),
                self::printable($path),
            ),
            context: ['migration' => $name, 'path' => $path],
        );
    }

    public static function emptyConnection(string $name, string $path): self
    {
        return new self(
            message: sprintf(
                'Migration "%s" at "%s" names an empty connection; use null for the default connection.',
                self::printable($name),
                self::printable($path),
            ),
            context: ['migration' => $name, 'path' => $path],
        );
    }

    /**
     * @param list<string> $names
     * @param list<string> $paths
     */
    public static function duplicateName(array $names, array $paths): self
    {
        return new self(
            message: sprintf(
                'More than one migration uses the name "%s", which is compared case-insensitively: %s.',
                self::printable($names[0]),
                implode(', ', array_map(
                    static fn(string $name, string $path): string => sprintf(
                        '"%s" at "%s"',
                        self::printable($name),
                        self::printable($path),
                    ),
                    $names,
                    $paths,
                )),
            ),
            context: ['migration' => $names[0], 'names' => $names, 'paths' => $paths],
        );
    }

    public static function conflictingHistory(string $name, string $conflictingName): self
    {
        return new self(
            message: sprintf(
                'The migration history holds both "%s" and "%s", which name the same migration because migration names '
                . 'are compared case-insensitively.',
                self::printable($name),
                self::printable($conflictingName),
            ),
            context: ['migration' => $name, 'conflictingMigration' => $conflictingName],
        );
    }

    public static function indexChanged(string $name, string $path, string $index, string $appliedIndex): self
    {
        return new self(
            message: sprintf(
                'Migration "%s" at "%s" has index "%s", but it was applied with index "%s".',
                self::printable($name),
                self::printable($path),
                self::printable($index),
                self::printable($appliedIndex),
            ),
            context: ['migration' => $name, 'path' => $path, 'index' => $index, 'appliedIndex' => $appliedIndex],
        );
    }

    public static function connectionChanged(
        string $name,
        string $path,
        string $connection,
        string $appliedConnection,
    ): self {
        return new self(
            message: sprintf(
                'Migration "%s" at "%s" runs on connection "%s", but it was applied on connection "%s".',
                self::printable($name),
                self::printable($path),
                self::printable($connection),
                self::printable($appliedConnection),
            ),
            context: [
                'migration' => $name,
                'path' => $path,
                'connection' => $connection,
                'appliedConnection' => $appliedConnection,
            ],
        );
    }

    public static function connectionUnavailable(
        string $name,
        string $path,
        ?string $connection,
        DatabaseException|SchemaException $previous,
    ): self {
        return new self(
            message: sprintf(
                'Unable to resolve the %s for migration "%s" at "%s".',
                $connection === null ? 'default connection' : sprintf('connection "%s"', self::printable($connection)),
                self::printable($name),
                self::printable($path),
            ),
            previous: $previous,
            context: ['migration' => $name, 'path' => $path, 'connection' => $connection],
        );
    }
}
