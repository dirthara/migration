<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Dirthara\Schema\Grammar\SchemaGrammar;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Schema\Grammar\SQLiteSchemaGrammar;
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\Driver\SQLiteDriver;
use Dirthara\Database\Query\Grammar\SQLiteQueryGrammar;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

#[Group('conformance')]
final class SQLiteMigrationRepositoryTest extends TestCase
{
    use MigrationRepositoryConformance;

    protected function driverName(): DriverName
    {
        return DriverName::SQLite;
    }

    protected function driver(): Driver
    {
        return new SQLiteDriver(new StandardTransactionGrammar(new SavepointPrefix()));
    }

    protected function schemaGrammar(): SchemaGrammar
    {
        return new SQLiteSchemaGrammar();
    }

    protected function queryGrammar(): QueryGrammar
    {
        return new SQLiteQueryGrammar();
    }

    protected function config(): ConnectionConfig
    {
        return new ConnectionConfig(driver: DriverName::SQLite, name: 'conformance', database: ':memory:');
    }
}
