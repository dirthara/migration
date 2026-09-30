<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use PDO;
use PDOException;
use Dirthara\Database\Connection\Driver\PdoDriver;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

use function str_starts_with;

final class LockingSQLiteDriver extends PdoDriver
{
    /**
     * @var list<string>
     */
    public private(set) array $queries = [];

    public ?string $failingQuery = null;

    public function __construct(
        public readonly ScriptedNamedLockGrammar $locks = new ScriptedNamedLockGrammar(),
    ) {
        parent::__construct(new StandardTransactionGrammar(new SavepointPrefix()));
    }

    public function name(): DriverName
    {
        return DriverName::SQLite;
    }

    public function namedLockGrammar(): ScriptedNamedLockGrammar
    {
        return $this->locks;
    }

    protected function createConnection(ConnectionConfig $config): PDO
    {
        return new RecordingPdo(function (string $query) use ($config): void {
            $recorded = $config->name . ': ' . $query;

            if ($this->failingQuery !== null && str_starts_with($recorded, $this->failingQuery)) {
                throw new PDOException('The query was scripted to fail.');
            }

            $this->queries[] = $recorded;
        }, $this->options($config));
    }
}
