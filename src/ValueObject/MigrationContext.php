<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use Dirthara\Schema\ConnectedSchema;
use Dirthara\Database\ConnectedDatabase;

final readonly class MigrationContext
{
    public function __construct(
        public ConnectedDatabase $database,
        public ConnectedSchema $schema,
    ) {}
}
