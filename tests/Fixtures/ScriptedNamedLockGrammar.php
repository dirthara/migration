<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use Dirthara\Database\Connection\Lock\NamedLockGrammar;
use Dirthara\Database\Connection\Lock\NamedLockOutcome;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;

final class ScriptedNamedLockGrammar implements NamedLockGrammar
{
    public const string GRANTED = 'SELECT 1 AS outcome WHERE ? IS NOT NULL';

    public const string FAILED = 'SELECT NULL AS outcome WHERE ? IS NOT NULL';

    /**
     * @var list<string>
     */
    public private(set) array $statements = [];

    public function __construct(
        private readonly string $acquire = self::GRANTED,
        private readonly string $release = self::GRANTED,
    ) {}

    public function resource(ConnectionConfig $config, string $name): string
    {
        return $config->name . ':' . $name;
    }

    public function acquire(): string
    {
        $this->statements[] = 'acquire';

        return $this->acquire;
    }

    public function tryAcquire(): string
    {
        $this->statements[] = 'tryAcquire';

        return $this->acquire;
    }

    public function release(): string
    {
        $this->statements[] = 'release';

        return $this->release;
    }

    public function outcome(?int $value): NamedLockOutcome
    {
        return $value === 1 ? NamedLockOutcome::Granted : NamedLockOutcome::Failed;
    }
}
