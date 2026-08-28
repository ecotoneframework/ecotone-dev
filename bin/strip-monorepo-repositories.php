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

    // Require entries aliasing a local path package (e.g. "dev-main as 1.999.999") only make
    // sense while the path repository above is present; without it they'd force installs from
    // an unstable dev branch instead of the published release the package otherwise resolves to.
    foreach (['require', 'require-dev'] as $section) {
        if (!isset($composer[$section])) {
            continue;
        }

        $composer[$section] = array_filter(
            $composer[$section],
            fn ($constraint) => !str_starts_with($constraint, 'dev-main as ')
        );

        if (empty($composer[$section])) {
            unset($composer[$section]);
        }
    }

    file_put_contents(
        $path,
        json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo "stripped: $path\n";
}
