<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use PDO;
use Dirthara\Database\Connection\Driver\PdoDriver;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\Database\Connection\ValueObjects\SavepointPrefix;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\Database\Connection\Transaction\StandardTransactionGrammar;

final class LockingSQLiteDriver extends PdoDriver
{
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
        return new PDO('sqlite::memory:', options: $this->options($config));
    }
}
