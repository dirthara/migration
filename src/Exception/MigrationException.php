<?php

declare(strict_types=1);

namespace Dirthara\Migration\Exception;

use Throwable;

interface MigrationException extends Throwable
{
    /**
     * @var array<string, mixed>
     */
    public array $context { get; }

    /**
     * @param array<string, mixed> $context
     */
    public function addContext(array $context): static;
}
