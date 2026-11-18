<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

const BUILDS_CONNECTIONS = [
    'packages/Dbal/Api/DbalConnectionFactory.php' => [1, 'the callback the connection pool opens its connections with'],
];

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$found = [];
foreach ([...glob($root . '/packages/*/src', GLOB_ONLYDIR), ...glob($root . '/packages/*/Api', GLOB_ONLYDIR)] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count = preg_match_all('/DriverManager\s*::\s*getConnection\s*\(/', file_get_contents($file->getPathname()));
        if ($count > 0) {
            $found[substr($file->getPathname(), strlen($root) + 1)] = $count;
        }
    }
}

$violations = [];
foreach ($found as $path => $count) {
    $allowed = BUILDS_CONNECTIONS[$path][0] ?? 0;
    if ($count > $allowed) {
        $violations[] = sprintf(
            "%s builds a database connection itself (%d DriverManager::getConnection call%s, %d allowed).\nA connection Ecotone opens belongs to the connection pool (Ecotone\\Dbal\\Connection\\ConnectionPool), which gives every execution running concurrently in the process (a coroutine or a fiber) its own connection and takes it back when the execution ends. A connection built beside the pool is shared by those executions, so they share one transaction. Ask the connection factory instead, each time the connection is needed: CachedConnectionFactory::createContext()->getDbalConnection() in framework code, DbalConnectionFactory::createContext()->getDbalConnection() from the application's factory.\nOnly the pool's own connect callback builds one. If this file is that callback, add it to BUILDS_CONNECTIONS in bin/check-framework-connections.php with its reason (framework-storage-and-transactions rule 16e).",
            $path,
            $count,
            $count === 1 ? '' : 's',
            $allowed,
        );
    }
}
foreach (BUILDS_CONNECTIONS as $path => [$allowed]) {
    if (($found[$path] ?? 0) < $allowed) {
        $violations[] = sprintf('%s is listed in BUILDS_CONNECTIONS with %d DriverManager::getConnection call%s but has %d. Lower or remove its entry in bin/check-framework-connections.php so the list stays exact.', $path, $allowed, $allowed === 1 ? '' : 's', $found[$path] ?? 0);
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode("\n\n", $violations) . "\n");
    exit(1);
}

echo "Framework connections: only the connection pool opens one.\n";
