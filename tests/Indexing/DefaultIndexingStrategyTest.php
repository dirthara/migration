<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Indexing;

use DateTimeZone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\Tests\Fixtures\FrozenClock;
use Dirthara\Migration\Indexing\DefaultIndexingStrategy;

final class DefaultIndexingStrategyTest extends TestCase
{
    #[Test]
    public function it_indexes_a_migration_by_the_current_time(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-30 10:15:00', new DateTimeZone('UTC')));

        self::assertSame('2026_09_30_101500', new DefaultIndexingStrategy()->index($clock, 'create_users'));
    }

    #[Test]
    public function it_indexes_a_migration_in_utc_whatever_the_time_zone_of_the_clock(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-30 12:15:00', new DateTimeZone('Europe/Amsterdam')));

        self::assertSame('2026_09_30_101500', new DefaultIndexingStrategy()->index($clock, 'create_users'));
    }
}
