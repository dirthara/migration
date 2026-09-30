<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use Dirthara\Migration\Naming\NamingStrategy;

use function sprintf;

final class RecordingNamingStrategy implements NamingStrategy
{
    /**
     * @var list<array{name: string, index: string}>
     */
    public private(set) array $calls = [];

    public function fileName(string $name, string $index): string
    {
        $this->calls[] = ['name' => $name, 'index' => $index];

        return sprintf('custom/%s-%s.php', $name, $index);
    }
}
