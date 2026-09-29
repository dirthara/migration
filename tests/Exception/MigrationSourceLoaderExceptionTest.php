<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Exception;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Migration\Exception\MigrationException;
use Dirthara\Migration\Exception\MigrationSourceLoaderException;

final class MigrationSourceLoaderExceptionTest extends TestCase
{
    #[Test]
    public function it_reports_a_scheme_registered_by_another_component(): void
    {
        $exception = MigrationSourceLoaderException::schemeAlreadyRegistered('dirthara-migration');

        self::assertInstanceOf(MigrationException::class, $exception);
        self::assertSame(
            'Unable to load migrations: another stream wrapper is already registered for the "dirthara-migration://" scheme.',
            $exception->getMessage(),
        );
        self::assertSame(['scheme' => 'dirthara-migration'], $exception->context);
    }

    #[Test]
    public function it_reports_a_stream_wrapper_that_could_not_be_registered(): void
    {
        $exception = MigrationSourceLoaderException::registrationFailed('dirthara-migration');

        self::assertInstanceOf(MigrationException::class, $exception);
        self::assertSame(
            'Unable to load migrations: the stream wrapper for the "dirthara-migration://" scheme could not be registered.',
            $exception->getMessage(),
        );
        self::assertSame(['scheme' => 'dirthara-migration'], $exception->context);
    }
}
