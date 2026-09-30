<?php

declare(strict_types=1);

namespace Dirthara\Migration;

enum MigrationTemplate
{
    case Basic;
    case BasicWithHooks;
    case Create;
    case CreateWithHooks;
    case Alter;
    case AlterWithHooks;

    public function fileName(): string
    {
        return match ($this) {
            self::Basic => 'migration.php.stub',
            self::BasicWithHooks => 'migration-with-hooks.php.stub',
            self::Create => 'create-migration.php.stub',
            self::CreateWithHooks => 'create-migration-with-hooks.php.stub',
            self::Alter => 'alter-migration.php.stub',
            self::AlterWithHooks => 'alter-migration-with-hooks.php.stub',
        };
    }
}
