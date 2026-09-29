<?php

declare(strict_types=1);

namespace Dirthara\Migration\Tests\Fixtures;

use function strlen;
use function substr;

final class ForeignStreamWrapper
{
    public const string CONTENTS = 'served by the foreign wrapper';

    /**
     * @var list<string>
     */
    public static array $opened = [];

    public mixed $context = null;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::CONTENTS, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::CONTENTS);
    }

    /**
     * @return array{size: int}
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::CONTENTS)];
    }
}
