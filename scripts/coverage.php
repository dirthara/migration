<?php

declare(strict_types=1);

/**
 * Fails when line coverage of the source is below the required percentage.
 *
 * Usage: php scripts/coverage.php [clover.xml] [minimum percentage]
 */

$report = $argv[1] ?? 'build/coverage/clover.xml';
$minimum = (float) ($argv[2] ?? 100);

if (!is_file($report)) {
    fwrite(STDERR, sprintf("Coverage report %s does not exist. Run the tests with coverage first.\n", $report));

    exit(1);
}

$clover = simplexml_load_file($report);

if ($clover === false) {
    fwrite(STDERR, sprintf("Coverage report %s could not be parsed.\n", $report));

    exit(1);
}

$root = dirname(__DIR__) . '/';
$statements = 0;
$covered = 0;
$uncovered = [];

/** @var list<SimpleXMLElement> $files */
$files = $clover->xpath('//file') ?: [];

foreach ($files as $file) {
    $name = str_replace($root, '', (string) $file['name']);

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $statements++;

        if ((int) $line['count'] > 0) {
            $covered++;

            continue;
        }

        $uncovered[$name][] = (int) $line['num'];
    }
}

if ($statements === 0) {
    fwrite(STDERR, "The coverage report contains no lines. Is a coverage driver enabled?\n");

    exit(1);
}

$percentage = $covered / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d), minimum %.2f%%\n", $percentage, $covered, $statements, $minimum);

if ($uncovered !== []) {
    fwrite(STDERR, "\nUncovered lines:\n");

    foreach ($uncovered as $name => $lines) {
        fwrite(STDERR, sprintf("  %s:%s\n", $name, implode(',', $lines)));
    }

    fwrite(STDERR, "\n");
}

if ($percentage + 0.0001 < $minimum) {
    fwrite(STDERR, sprintf("Coverage is below the required %.2f%%.\n", $minimum));

    exit(1);
}

exit(0);
