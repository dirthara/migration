<?php

declare(strict_types=1);

namespace Dirthara\Migration\Contract;

use Dirthara\Migration\ValueObject\MigrationContext;
use Dirthara\Migration\ValueObject\MigrationDecision;

interface MigrationHooks
{
    public function beforeUp(MigrationContext $context, MigrationDecision &$decision): void;

    public function afterUp(MigrationContext $context): void;

    public function beforeDown(MigrationContext $context, MigrationDecision &$decision): void;

    public function afterDown(MigrationContext $context): void;
}
