<?php

declare(strict_types=1);

namespace Dirthara\Migration;

enum MigrationDirection
{
    case Up;
    case Down;
}
