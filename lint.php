<?php

/**
 * Cross-platform PHP syntax checker.
 *
 * Recursively checks all .php files in src/ and tests/ for syntax errors.
 * Works on Linux, macOS, and Windows — no shell utilities required.
 *
 * Usage:  php lint.php
 * Exit:   0 = all clean, 1 = syntax errors found
 */

declare(strict_types=1);

$dirs   = [__DIR__ . '/src', __DIR__ . '/tests'];
$errors = [];
$count  = 0;

$iterator = function (string $dir) use (&$iterator): \Generator {
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }

        if ($item->isDir()) {
            yield from $iterator($item->getPathname());
        } elseif ($item->isFile() && $item->getExtension() === 'php') {
            yield $item->getPathname();
        }
    }
};

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    foreach ($iterator($dir) as $file) {
        $count++;
        $output = [];
        $code   = 0;

        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        if ($code !== 0) {
            $errors[] = implode("\n", $output);
        }
    }
}

$errorCount = count($errors);

if ($errorCount > 0) {
    echo "Syntax errors found in {$errorCount} file(s):\n\n";

    foreach ($errors as $error) {
        echo $error . "\n";
    }

    exit(1);
}

echo "No syntax errors detected in {$count} file(s).\n";
exit(0);
