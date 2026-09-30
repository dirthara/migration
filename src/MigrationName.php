<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use function preg_match;
use function strtolower;

/**
 * The rule every migration name follows.
 *
 * A migration name is an ASCII identifier: a letter followed by letters, digits, and underscores. It identifies a
 * migration case-insensitively, so `CreateUsers` and `createusers` are the same migration on every database, whatever
 * its collation. The name keeps its original spelling for storage and display; only comparisons use the canonical,
 * lower-case form.
 *
 * @internal
 */
final class MigrationName
{
    private const string PATTERN = '/^[A-Za-z][A-Za-z0-9_]*\z/';

    private function __construct() {}

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public static function canonical(string $name): string
    {
        return strtolower($name);
    }
}
