<?php

declare(strict_types=1);

namespace Dirthara\Migration;

enum MigrationDecision
{
    case Continue;
    case Skip;
    case Stop;
}
