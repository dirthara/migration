<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use Dirthara\Migration\MigrationDecision as Decision;

final class MigrationDecision
{
    public function __construct(
        public Decision $decision,
        public ?string $reason = null,
    ) {}
}
