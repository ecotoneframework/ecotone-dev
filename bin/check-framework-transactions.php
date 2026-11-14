<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

const OWN_TRANSACTIONS = [
    'packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php' => [1, 'the boundary itself: command bus, gateways marked #[DbalTransaction], asynchronous endpoint and console command transactions'],
    'packages/Ecotone/src/Projecting/ProjectingManager.php' => [1, 'a projection commits each batch on its own, so a long catch-up never holds one transaction'],
    'packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php' => [1, 'projection state is versioned and committed per projection batch'],
    'packages/Dbal/src/BatchForwarding/DbalOutboxPublisher.php' => [1, 'the outbox forwarder claims one batch in a short transaction of its own consumer loop'],
    'packages/Dbal/src/Tracing/DbalTraceStore.php' => [3, 'traces survive the message transaction they describe, projection batch spans commit with that batch, and expired traces are removed in short batches'],
    'packages/Dbal/src/Pulse/Tracking/DbalTrackingStore.php' => [1, 'a Pulse projection action commits with its tracking row, outside any message transaction'],
    'packages/Dbal/src/Database/TimeBoxedSchemaRead.php' => [1, 'scopes SET LOCAL statement_timeout to a schema read and always rolls back'],
    'packages/Laravel/src/Config/PDO/Connection.php' => [1, 'driver adapter implementing the connection transaction API'],
    'packages/Laravel/src/Config/PDO/SqlServerConnection.php' => [1, 'driver adapter implementing the connection transaction API'],
    'packages/Tempest/src/Config/PDO/TempestConnectionPdo.php' => [1, 'driver adapter implementing the connection transaction API'],
    'packages/Tempest/src/Config/PDO/Connection.php' => [1, 'driver adapter implementing the connection transaction API'],
    'packages/Tempest/src/Config/PDO/TempestDynamicDriverConnection.php' => [1, 'driver adapter implementing the connection transaction API'],
    'packages/Laravel/src/EloquentRepository.php' => [1, 'an Eloquent aggregate save keeps its version claim and its write as one unit, a savepoint inside the boundary transaction'],
];

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$found = [];
foreach ([...glob($root . '/packages/*/src', GLOB_ONLYDIR), ...glob($root . '/packages/*/Api', GLOB_ONLYDIR)] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count = preg_match_all('/(->|::)\s*(transactional|transaction|beginTransaction|wrapInTransaction)\s*\(/', file_get_contents($file->getPathname()));
        if ($count > 0) {
            $found[substr($file->getPathname(), strlen($root) + 1)] = $count;
        }
    }
}

$violations = [];
foreach ($found as $path => $count) {
    $allowed = OWN_TRANSACTIONS[$path][0] ?? 0;
    if ($count > $allowed) {
        $violations[] = sprintf(
            "%s opens a database transaction itself (%d call%s, %d allowed).\nTransactions belong to the boundaries: the command bus (or a gateway marked #[DbalTransaction]), the asynchronous endpoint (polling consumer or inbound adapter) and the console command, which all wrap framework code in the caller's transaction (DbalConfiguration::withTransactionOn*). Remove the transactional()/transaction()/beginTransaction() call and rely on that transaction. A console command registered by a module carries ConsoleCommand automatically, so it gets one too.\nOnly work that must stay out of a long boundary transaction opens its own, like projections; a console command doing batched work runs with #[WithoutDatabaseTransaction] instead. Then add the file to OWN_TRANSACTIONS in bin/check-framework-transactions.php with its reason (framework-storage-and-transactions rule 16d).",
            $path,
            $count,
            $count === 1 ? '' : 's',
            $allowed,
        );
    }
}
foreach (OWN_TRANSACTIONS as $path => [$allowed]) {
    if (($found[$path] ?? 0) < $allowed) {
        $violations[] = sprintf('%s is listed in OWN_TRANSACTIONS with %d transaction call%s but has %d. Lower or remove its entry in bin/check-framework-transactions.php so the list stays exact.', $path, $allowed, $allowed === 1 ? '' : 's', $found[$path] ?? 0);
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode("\n\n", $violations) . "\n");
    exit(1);
}

echo "Framework transactions: only the boundaries and the listed exceptions open one.\n";
