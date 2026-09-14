<?php

declare(strict_types=1);

function sortImports(string $source): string
{
    $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
    $depth = 0;
    $namespaceDepth = 0;
    $namespacePending = false;
    $groups = [];
    $group = [];
    $previousEnd = 0;
    $previousType = null;

    foreach ($tokens as $index => $token) {
        if ($token->id === T_NAMESPACE) {
            $namespacePending = true;
        }

        if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            if ($namespacePending) {
                $namespaceDepth = $depth;
                $namespacePending = false;
            }
        } elseif ($token->text === '}') {
            $depth--;
            $namespaceDepth = min($namespaceDepth, $depth);
        } elseif ($token->text === ';') {
            $namespacePending = false;
        }

        if ($token->id !== T_USE || $depth !== $namespaceDepth) {
            continue;
        }

        $end = $index + 1;

        while (isset($tokens[$end]) && $tokens[$end]->id === T_WHITESPACE) {
            $end++;
        }

        if (!isset($tokens[$end]) || $tokens[$end]->text === '(') {
            continue;
        }

        $type = match ($tokens[$end]->id) {
            T_FUNCTION => 'function',
            T_CONST => 'const',
            default => 'class',
        };

        while (isset($tokens[$end]) && $tokens[$end]->text !== ';') {
            $end++;
        }

        if (!isset($tokens[$end])) {
            continue;
        }

        $start = $token->pos;
        $finish = $tokens[$end]->pos + 1;
        $statement = substr($source, $start, $finish - $start);
        $lineStart = strrpos(substr($source, 0, $start), "\n");
        $prefix = substr(
            $source,
            $lineStart === false ? 0 : $lineStart + 1,
            $start - ($lineStart === false ? 0 : $lineStart + 1),
        );
        $lineEnd = strpos($source, "\n", $finish);
        $suffix = substr($source, $finish, ($lineEnd === false ? strlen($source) : $lineEnd) - $finish);
        $previous = $index - 1;

        while ($previous >= 0 && $tokens[$previous]->id === T_WHITESPACE) {
            $previous--;
        }

        if (
            trim($prefix) !== ''
            || trim($suffix) !== ''
            || preg_match('/[\r\n{},]|\/\*|\/\/|#/', $statement) === 1
            || $previous >= 0 && in_array($tokens[$previous]->id, [T_COMMENT, T_DOC_COMMENT], true)
        ) {
            continue;
        }

        if (
            $group !== []
            && ($type !== $previousType || trim(substr($source, $previousEnd, $start - $previousEnd)) !== '')
        ) {
            $groups[] = $group;
            $group = [];
        }

        $group[] = ['start' => $start, 'length' => $finish - $start, 'statement' => $statement];
        $previousEnd = $finish;
        $previousType = $type;
    }

    if ($group !== []) {
        $groups[] = $group;
    }

    foreach (array_reverse($groups) as $imports) {
        $sorted = array_column($imports, 'statement');
        usort(
            $sorted,
            static fn(string $a, string $b): int => (
                preg_match_all('/./us', $a) <=> preg_match_all('/./us', $b) ?: strcasecmp($a, $b) ?: strcmp($a, $b)
            ),
        );

        for ($index = count($imports) - 1; $index >= 0; $index--) {
            $source = substr_replace($source, $sorted[$index], $imports[$index]['start'], $imports[$index]['length']);
        }
    }

    return $source;
}

$check = in_array('--check', $argv, true);
$paths = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $argument): bool => $argument !== '--check',
));
$paths = $paths === [] ? [__DIR__ . '/../src', __DIR__ . '/../tests'] : $paths;
$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }

    if (!is_dir($path)) {
        fwrite(STDERR, "Path does not exist: {$path}\n");
        exit(1);
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $path,
        FilesystemIterator::SKIP_DOTS,
    )) as $file) {
        if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$status = 0;

foreach (array_unique($files) as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "Unable to read: {$file}\n");
        $status = 1;
        continue;
    }

    try {
        $sorted = sortImports($source);
    } catch (ParseError $error) {
        fwrite(STDERR, "{$file}: {$error->getMessage()}\n");
        $status = 1;
        continue;
    }

    if ($source === $sorted) {
        continue;
    }

    if ($check) {
        fwrite(STDERR, "Imports must be sorted by full statement length: {$file}\n");
        $status = 1;
    } elseif (file_put_contents($file, $sorted) === false) {
        fwrite(STDERR, "Unable to write: {$file}\n");
        $status = 1;
    } else {
        echo "Sorted imports: {$file}\n";
    }
}

exit($status);
