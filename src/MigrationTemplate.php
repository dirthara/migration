<?php

declare(strict_types=1);

namespace Dirthara\Migration;

enum MigrationTemplate
{
    case Create;
    case CreateWithHooks;
    case Basic;
    case BasicWithHooks;

    public function fileName(): string
    {
        return match ($this) {
            self::Create => 'create-migration.php.stub',
            self::CreateWithHooks => 'create-migration-with-hooks.php.stub',
            self::Basic => 'migration.php.stub',
            self::BasicWithHooks => 'migration-with-hooks.php.stub',
        };
    }
}
