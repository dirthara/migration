<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

final class MigrationLog
{
    /**
     * @var list<string>
     */
    public static array $events = [];

    public static function record(string $event): void
    {
        self::$events[] = $event;
    }
}
