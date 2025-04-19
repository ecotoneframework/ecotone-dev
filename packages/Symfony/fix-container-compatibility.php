<?php

require_once __DIR__ . '/../../vendor/autoload.php';


// Clear all cache directories
echo "Clearing cache directories...\n";
$cacheDirectories = [
    __DIR__ . '/var/cache',
    __DIR__ . '/tests/phpunit/Licence/var/cache',
    __DIR__ . '/tests/phpunit/SingleTenant/var/cache',
    __DIR__ . '/tests/phpunit/MultiTenant/var/cache',
];

foreach ($cacheDirectories as $cacheDir) {
    if (is_dir($cacheDir)) {
        echo "Clearing $cacheDir\n";
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        // Remove the directory itself
        rmdir($cacheDir);
    }
}

echo "Cache directories cleared.\n";

// Create a .gitignore file to prevent committing generated container files
$gitignoreContent = <<<EOT
    # Ignore generated container files
    /var/cache/
    /tests/phpunit/*/var/cache/
    EOT;

file_put_contents(__DIR__ . '/.gitignore', $gitignoreContent, FILE_APPEND);

echo "Added cache directories to .gitignore\n";
echo "Container compatibility fix completed.\n";
