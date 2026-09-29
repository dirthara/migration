<?php

declare(strict_types=1);

namespace Dirthara\Migration;

enum MigrationAction
{
    case Continue;
    case Skip;
    case Stop;
}
