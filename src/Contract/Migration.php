<?php

declare(strict_types=1);

namespace Dirthara\Migration\Contract;

use Dirthara\Migration\ValueObject\MigrationContext;

interface Migration
{
    public string $name { get; }

    public ?string $description { get; }

    public string $index { get; }

    public ?string $connection { get; }

    public function up(MigrationContext $context): void;

    public function down(MigrationContext $context): void;
}
