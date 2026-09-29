<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Dirthara\Schema\Grammar\SchemaGrammar;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Schema\Grammar\SqlServerSchemaGrammar;
use Dirthara\Database\Connection\Driver\SqlServerDriver;
use Dirthara\Database\Query\Grammar\SqlServerQueryGrammar;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\SqlServerTransactionGrammar;

#[Group('conformance')]
#[Group('integration')]
final class SqlServerMigrationRepositoryTest extends TestCase
{
    use MigrationRepositoryConformance;

    protected function driverName(): DriverName
    {
        return DriverName::SqlServer;
    }

    protected function driver(): Driver
    {
        return new SqlServerDriver(new SqlServerTransactionGrammar(new SavepointPrefix()));
    }

    protected function schemaGrammar(): SchemaGrammar
    {
        return new SqlServerSchemaGrammar();
    }

    protected function queryGrammar(): QueryGrammar
    {
        return new SqlServerQueryGrammar();
    }

    protected function config(): ConnectionConfig
    {
        return new ConnectionConfig(
            driver: DriverName::SqlServer,
            name: 'conformance',
            host: $this->env('DIRTHARA_SQLSRV_HOST', 'sqlserver'),
            port: (int) $this->env('DIRTHARA_SQLSRV_PORT', '1433'),
            database: $this->env('DIRTHARA_SQLSRV_DATABASE', 'master'),
            username: $this->env('DIRTHARA_SQLSRV_USERNAME', 'sa'),
            password: $this->env('DIRTHARA_SQLSRV_PASSWORD', 'Dirthara!2026'),
            dsn: ['TrustServerCertificate' => 'yes'],
        );
    }
}
