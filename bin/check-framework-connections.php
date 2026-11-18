<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

const BUILDS_CONNECTIONS = [
    'packages/Dbal/Api/DbalConnectionFactory.php' => [1, 'the callback the connection pool opens its connections with'],
];

const KEEPS_CONNECTIONS = [
    'packages/Dbal/src/Connection/PooledConnection.php' => [1, 'the hold of one execution on its connection, which returns it to the pool when the execution ends'],
    'packages/Dbal/src/AlreadyConnectedDbalConnectionFactory.php' => [1, 'wraps the one connection the application built and registered itself'],
    'packages/PdoEventSourcing/src/Projecting/PartitionState/DbalTransaction.php' => [1, 'lives for one projection batch, inside the method that resolved the connection'],
];

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$built = [];
$kept = [];
foreach ([...glob($root . '/packages/*/src', GLOB_ONLYDIR), ...glob($root . '/packages/*/Api', GLOB_ONLYDIR)] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = substr($file->getPathname(), strlen($root) + 1);
        $source = file_get_contents($file->getPathname());

        $count = preg_match_all('/DriverManager\s*::\s*getConnection\s*\(/', $source);
        if ($count > 0) {
            $built[$path] = $count;
        }

        $count = propertiesKeepingAConnection($source);
        if ($count > 0) {
            $kept[$path] = $count;
        }
    }
}

$violations = [];
foreach ($built as $path => $count) {
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
foreach ($kept as $path => $count) {
    $allowed = KEEPS_CONNECTIONS[$path][0] ?? 0;
    if ($count > $allowed) {
        $violations[] = sprintf(
            "%s keeps a database connection in a property (%d propert%s typed Doctrine\\DBAL\\Connection or DbalContext, %d allowed).\nA connection kept in a property of a service is the connection of whichever execution was running when the property was set, and every other execution running concurrently in the process (a coroutine or a fiber) then works on it too, inside its transaction. Keep the connection factory instead and resolve the connection inside each method that uses it: \$this->connectionFactory->createContext()->getDbalConnection() returns the connection of the current execution.\nIf the object lives no longer than one execution (it is created inside the method that resolved the connection) or it is the connection the application registered itself, add the file to KEEPS_CONNECTIONS in bin/check-framework-connections.php with its reason (framework-storage-and-transactions rule 16e).",
            $path,
            $count,
            $count === 1 ? 'y' : 'ies',
            $allowed,
        );
    }
}
foreach (['BUILDS_CONNECTIONS' => [BUILDS_CONNECTIONS, $built, 'DriverManager::getConnection call'], 'KEEPS_CONNECTIONS' => [KEEPS_CONNECTIONS, $kept, 'connection property']] as $listName => [$list, $found, $what]) {
    foreach ($list as $path => [$allowed]) {
        if (($found[$path] ?? 0) < $allowed) {
            $violations[] = sprintf('%s is listed in %s with %d %s(s) but has %d. Lower or remove its entry in bin/check-framework-connections.php so the list stays exact.', $path, $listName, $allowed, $what, $found[$path] ?? 0);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode("\n\n", $violations) . "\n");
    exit(1);
}

echo "Framework connections: only the connection pool opens one, and only the listed classes keep one.\n";

function propertiesKeepingAConnection(string $source): int
{
    $connectionTypes = ['\\\\?Doctrine\\\\DBAL\\\\Connection', '\\\\?Ecotone\\\\Dbal\\\\Connection\\\\DbalContext'];
    if (preg_match('/^use\s+Doctrine\\\\DBAL\\\\Connection\s*;/m', $source)) {
        $connectionTypes[] = 'Connection';
    }
    if (preg_match('/^use\s+Ecotone\\\\Dbal\\\\Connection\\\\DbalContext\s*;/m', $source) || preg_match('/^namespace\s+Ecotone\\\\Dbal\\\\Connection\s*;/m', $source)) {
        $connectionTypes[] = 'DbalContext';
    }

    return preg_match_all('/\b(?:private|protected|public)(?:\s+readonly)?\s+\??(?:' . implode('|', $connectionTypes) . ')\s+\$\w+/', $source);
}
