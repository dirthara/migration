<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use PDO;
use Closure;
use Override;
use PDOStatement;

final class RecordingPdo extends PDO
{
    /**
     * @param Closure(string): void  $record
     * @param array<int, mixed>      $options
     */
    public function __construct(
        private readonly Closure $record,
        array $options,
    ) {
        parent::__construct('sqlite::memory:', options: $options);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ($this->record)($query);

        return parent::prepare($query, $options);
    }
}
