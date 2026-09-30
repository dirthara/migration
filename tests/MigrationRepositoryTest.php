<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests;

use PHPUnit\Framework\TestCase;
use Dirthara\Schema\ConnectedSchema;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Database\ConnectedDatabase;
use Dirthara\Migration\MigrationRepository;
use Dirthara\Database\Connection\Connection;
use Dirthara\Database\Connection\Result\Result;
use Dirthara\Schema\Grammar\SQLiteSchemaGrammar;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Migration\Exception\MigrationRepositoryException;

use function sprintf;

final class MigrationRepositoryTest extends TestCase
{
    #[Test]
    public function it_reads_a_time_stored_with_fractional_seconds(): void
    {
        $applied = $this->repositoryReturning($this->row([
            'applied_at' => '2026-01-01 12:30:45.0000000',
        ]))->getApplied();

        self::assertSame('2026-01-01 12:30:45 UTC', $applied[0]->appliedAt->format('Y-m-d H:i:s T'));
        self::assertNull($applied[0]->description);
        self::assertSame(3, $applied[0]->batch);
    }

    #[Test]
    public function it_rejects_a_record_with_a_value_that_is_not_scalar(): void
    {
        $exception = $this->failure($this->repositoryReturning($this->row(['name' => ['create_users']])));

        self::assertSame(
            'The migration table "migrations" holds a record with an invalid "name" value.',
            $exception->getMessage(),
        );
        self::assertSame(['table' => 'migrations', 'column' => 'name'], $exception->context);
    }

    #[Test]
    public function it_rejects_a_record_with_a_time_it_cannot_read(): void
    {
        $exception = $this->failure($this->repositoryReturning($this->row(['applied_at' => 'yesterday'])));

        self::assertSame(['table' => 'migrations', 'column' => 'applied_at'], $exception->context);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return [
            'id' => 1,
            'name' => 'create_users',
            'index' => '2026_01_01_000000',
            'description' => null,
            'connection' => 'default',
            'batch' => '3',
            'applied_at' => '2026-01-01 12:30:45',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function repositoryReturning(array $row): MigrationRepository
    {
        $result = $this->createStub(Result::class);
        $result->method('all')->willReturn([$row]);

        $connection = $this->createStub(Connection::class);
        $connection->method('execute')->willReturn($result);

        return new MigrationRepository(
            new ConnectedDatabase($connection, new SQLiteQueryGrammar()),
            new ConnectedSchema($connection, new SQLiteSchemaGrammar()),
            'migrations',
        );
    }

    private function failure(MigrationRepository $repository): MigrationRepositoryException
    {
        try {
            $repository->getApplied();
        } catch (MigrationRepositoryException $exception) {
            return $exception;
        }

        self::fail(sprintf('Reading the applied migrations did not throw %s.', MigrationRepositoryException::class));
    }
}
