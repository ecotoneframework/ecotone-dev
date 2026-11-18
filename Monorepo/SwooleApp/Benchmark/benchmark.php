<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

$workloads = [
    'input-output on MySQL' => ['input-output', 'mysql', (int) ($argv[1] ?? 3000)],
    'input-output on PostgreSQL' => ['input-output', 'pgsql', (int) ($argv[1] ?? 3000)],
    'in-memory' => ['in-memory', 'none', (int) ($argv[2] ?? 20000)],
];
$setups = [
    '1 process' => ['consume', 1, 0],
    '4 processes' => ['consume', 4, 0],
    '8 processes' => ['consume', 8, 0],
    '16 processes' => ['consume', 16, 0],
    '1 process, 1 coroutine' => ['consume-in-coroutines', 1, 1],
    '1 process, 4 coroutines' => ['consume-in-coroutines', 1, 4],
    '1 process, 8 coroutines' => ['consume-in-coroutines', 1, 8],
    '1 process, 16 coroutines' => ['consume-in-coroutines', 1, 16],
];
$consumer = escapeshellarg(__DIR__ . '/consumer.php');

printf("%-28s | %-26s | %9s | %12s | %14s | %12s | %15s | %18s\n", 'workload', 'consumers', 'messages', 'messages/min', 'ms per message', 'memory (MB)', 'CPU of one core', 'CPU ms per message');
foreach ($workloads as $workloadName => [$workload, $database, $messages]) {
    if (isset($argv[3]) && ! str_contains($workloadName, $argv[3])) {
        continue;
    }
    foreach ($setups as $setupName => [$action, $processes, $coroutines]) {
        passthru("php {$consumer} publish {$workload} {$database} {$messages}", $published);
        if ($published !== 0) {
            fwrite(STDERR, "Publishing for {$workloadName} failed\n");
            exit(1);
        }

        $running = [];
        $outputs = [];
        for ($process = 1; $process <= $processes; $process++) {
            $running[] = proc_open("php {$consumer} {$action} {$workload} {$database} {$coroutines}", [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            $outputs[$process] = $pipes[1];
        }
        $reports = [];
        foreach ($running as $index => $process) {
            $reports[] = json_decode(trim((string) stream_get_contents($outputs[$index + 1])), true, flags: JSON_THROW_ON_ERROR);
            proc_close($process);
        }

        $handled = array_sum(array_column($reports, 'handled'));
        $elapsed = max(array_column($reports, 'finishedAt')) - min(array_column($reports, 'startedAt'));
        $consumersAtOnce = $processes * max(1, $coroutines);
        printf(
            "%-28s | %-26s | %9d | %12d | %14.2f | %12.1f | %14.0f%% | %18.3f\n",
            $workloadName,
            $setupName,
            $handled,
            $handled / $elapsed * 60,
            $elapsed * $consumersAtOnce / max(1, $handled) * 1000,
            array_sum(array_column($reports, 'peakMemoryMegabytes')),
            array_sum(array_column($reports, 'processorSeconds')) / $elapsed * 100,
            array_sum(array_column($reports, 'processorSeconds')) / max(1, $handled) * 1000,
        );
    }
}
