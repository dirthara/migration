<?php

declare(strict_types=1);

$script = dirname(__DIR__) . '/sort-imports.php';
$file = tempnam(sys_get_temp_dir(), 'migration-imports-');

if ($file === false) {
    throw new RuntimeException('Unable to create temporary fixture.');
}

$run = static function (bool $check = false) use ($script, $file): int {
    $command = [PHP_BINARY, $script];

    if ($check) {
        $command[] = '--check';
    }

    $command[] = $file;
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to run import sorter.');
    }

    foreach ($pipes as $pipe) {
        stream_get_contents($pipe);
        fclose($pipe);
    }

    return proc_close($process);
};

$verify = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixtures = [
    'aliases' => [
        'use A\\B as VeryLongAlias;' . "\n" . 'use Longer\\Name;',
        'use Longer\\Name;' . "\n" . 'use A\\B as VeryLongAlias;',
    ],
    'ties' => ["use Bbb;\nuse Aaa;", "use Aaa;\nuse Bbb;"],
    'types' => [
        "use Longer;\nuse A;\n\nuse function longer;\nuse function a;\n\nuse const LONGER;\nuse const A;",
        "use A;\nuse Longer;\n\nuse function a;\nuse function longer;\n\nuse const A;\nuse const LONGER;",
    ],
    'comments' => [
        "use Longer; // attached\nuse A;\n// attached\nuse Longest;\nuse B;",
        "use Longer; // attached\nuse A;\n// attached\nuse Longest;\nuse B;",
    ],
    'trait and closure uses' => [
        'class Example { use LongerTrait; use A; } $a = 1; $f = function () use ($a) {};',
        'class Example { use LongerTrait; use A; } $a = 1; $f = function () use ($a) {};',
    ],
    'braced namespaces' => [
        "namespace One {\n    use Longer;\n    use A;\n}\nnamespace Two {\n    use Longest;\n    use B;\n}",
        "namespace One {\n    use A;\n    use Longer;\n}\nnamespace Two {\n    use B;\n    use Longest;\n}",
    ],
    'unbraced namespaces' => [
        "namespace One;\nuse Longer;\nuse A;\nnamespace Two;\nuse Longest;\nuse B;",
        "namespace One;\nuse A;\nuse Longer;\nnamespace Two;\nuse B;\nuse Longest;",
    ],
    'CRLF' => ["use Longer;\r\nuse A;", "use A;\r\nuse Longer;"],
    'grouped imports' => ['use Example\\{Longer, A};', 'use Example\\{Longer, A};'],
];

try {
    foreach ($fixtures as $name => [$before, $after]) {
        $source = "<?php\n" . $before . "\n";
        $expected = "<?php\n" . $after . "\n";
        file_put_contents($file, $source);
        $verify($run(true) === ($source === $expected ? 0 : 1), "{$name}: check status");
        $verify(file_get_contents($file) === $source, "{$name}: check changed source");
        $verify($run() === 0, "{$name}: sort status");
        $verify(file_get_contents($file) === $expected, "{$name}: incorrect output");
        $verify($run() === 0 && file_get_contents($file) === $expected, "{$name}: not idempotent");
        $verify($run(true) === 0, "{$name}: sorted check failed");
    }

    file_put_contents($file, '<?php use ;');
    $verify($run() === 1, 'Invalid PHP must fail');
    $verify(file_get_contents($file) === '<?php use ;', 'Invalid PHP was changed');
    echo count($fixtures) . " import sorting fixtures and invalid PHP handling passed.\n";
} finally {
    unlink($file);
}
