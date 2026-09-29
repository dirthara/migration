<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures\Migrations;

use Dirthara\Migration\Contract\Migration;
use Dirthara\Migration\ValueObject\MigrationContext;

return new class implements Migration {
    public string $name = 'create_users';

    public ?string $description = __FILE__;

    public string $index = '2026_01_01_000000';

    public ?string $connection = null;

    public function up(MigrationContext $context): void {}

    public function down(MigrationContext $context): void {}
};
