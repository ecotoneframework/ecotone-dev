#!/usr/bin/env php
<?php

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass directory to strip path repositories from');
if (!is_dir($directory)) {
    throw new InvalidArgumentException("Directory does not exist: $directory");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        fn ($current) => $current->getFilename() !== 'vendor'
    )
);

foreach ($iterator as $file) {
    if ($file->getFilename() !== 'composer.json') {
        continue;
    }

    $path = $file->getPathname();
    $composer = json_decode(file_get_contents($path), true);
    if (!is_array($composer) || !isset($composer['repositories'])) {
        continue;
    }

    $composer['repositories'] = array_values(array_filter(
        $composer['repositories'],
        fn ($repository) => ($repository['type'] ?? null) !== 'path'
    ));

    if (empty($composer['repositories'])) {
        unset($composer['repositories']);
    }

    file_put_contents(
        $path,
        json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo "stripped: $path\n";
}
