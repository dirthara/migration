<?php

declare(strict_types=1);

namespace Dirthara\Migration\ValueObject;

use Dirthara\Migration\MigrationAction;

final readonly class MigrationDecision
{
    private function __construct(
        public MigrationAction $decision,
        public ?string $reason = null,
    ) {}

    public static function continue(): self
    {
        return new self(MigrationAction::Continue);
    }

    public static function skip(?string $reason = null): self
    {
        return new self(MigrationAction::Skip, $reason);
    }

    public static function stop(string $reason): self
    {
        return new self(MigrationAction::Stop, $reason);
    }
}
