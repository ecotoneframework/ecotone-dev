<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 *
 * php http.php [requests] [requests sent at once] [MySQL|PostgreSQL] [part of a server name]
 * Serves the Symfony application of Monorepo/SwooleApp without OpenSwoole (PHP built-in server, which boots the
 * application for every request as php-fpm does), on OpenSwoole with one request per worker, and on OpenSwoole
 * with coroutines, each with 1, 4, 8 and 16 workers, and measures three requests on MySQL and PostgreSQL.
 */

const PORT = 9501;
const SECONDS_FOR_CLOSED_DATABASE_CONNECTIONS_TO_FREE_THEIR_PORTS = 60;

$requests = (int) ($argv[1] ?? 2000);
$sentAtOnce = (int) ($argv[2] ?? 64);
$application = dirname(__DIR__) . '/Symfony';
$databases = [
    'MySQL' => getenv('DATABASE_MYSQL'),
    'PostgreSQL' => 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16',
];
$servers = [];
foreach ([1, 4, 8, 16] as $workers) {
    $servers["no OpenSwoole, {$workers} workers"] = "PHP_CLI_SERVER_WORKERS={$workers} php -S 0.0.0.0:" . PORT . " -t {$application}/public {$application}/public/index.php";
}
foreach ([1, 4, 8, 16] as $workers) {
    $servers["OpenSwoole, {$workers} workers"] = "SWOOLE_WORKERS={$workers} SWOOLE_COROUTINES=0 php {$application}/server.php";
}
foreach ([1, 4, 8, 16] as $workers) {
    $servers["OpenSwoole, {$workers} workers, coroutines"] = "SWOOLE_WORKERS={$workers} SWOOLE_COROUTINES=1 php {$application}/server.php";
}

exec('rm -rf ' . escapeshellarg($application . '/var/cache'));
printf("%-10s | %-40s | %-34s | %12s | %8s | %8s | %11s | %15s | %18s | %6s\n", 'database', 'server', 'request', 'requests/min', 'mean ms', 'p95 ms', 'memory (MB)', 'CPU of one core', 'CPU ms per request', 'failed');
foreach ($databases as $databaseName => $databaseDsn) {
    if (isset($argv[3]) && $argv[3] !== '' && $argv[3] !== $databaseName) {
        continue;
    }
    foreach ($servers as $serverName => $command) {
        if (isset($argv[4]) && ! str_contains($serverName, $argv[4])) {
            continue;
        }
        stopServers();
        $setupOutput = [];
        exec("DATABASE_DSN='{$databaseDsn}' APP_ENV=prod php {$application}/console ecotone:migration:database:setup --initialize 2>&1", $setupOutput, $setupResult);
        exec("DATABASE_DSN='{$databaseDsn}' APP_ENV=prod php {$application}/console ecotone:projection:init --all 2>&1", $setupOutput, $projectionResult);
        if ($setupResult !== 0 || $projectionResult !== 0) {
            fwrite(STDERR, implode("\n", $setupOutput) . "\n");
            exit(1);
        }
        exec("DATABASE_DSN='{$databaseDsn}' APP_ENV=prod nohup sh -c " . escapeshellarg($command) . ' > /tmp/benchmark-server.log 2>&1 &');
        waitUntilServing();
        send('setup', 1, 1, '{}');
        send('state', 200, $sentAtOnce, '{}');

        $run = bin2hex(random_bytes(3));
        foreach (['open' => 'accountId', 'walletOpen' => 'walletId', 'deposit' => 'accountId', 'walletTopUp' => 'walletId', 'balance' => 'accountId'] as $warmedUp => $identifier) {
            send($warmedUp, str_starts_with($serverName, 'no OpenSwoole') ? 1 : 200, $sentAtOnce, '{"' . $identifier . '":"warm-' . $run . '-{i}","amount":5}');
        }
        foreach (['open' => 'accountId', 'walletOpen' => 'walletId'] as $preparation => $identifier) {
            foreach (array_slice(send($preparation, $requests, $sentAtOnce, '{"' . $identifier . '":"' . $run . '-{i}"}')['errors'], 0, 3, true) as $error => $count) {
                fwrite(STDERR, "    {$preparation}: {$count} x {$error}\n");
            }
        }

        $measured = [
            'command to event-sourced aggregate' => send('deposit', $requests, $sentAtOnce, '{"accountId":"' . $run . '-{i}","amount":5}'),
            'command to state-stored aggregate' => send('walletTopUp', $requests, $sentAtOnce, '{"walletId":"' . $run . '-{i}","amount":5}'),
            'query of a projected read model' => send('balance', $requests, $sentAtOnce, '{"accountId":"' . $run . '-{i}"}'),
        ];
        foreach ($measured as $requestName => $result) {
            printf(
                "%-10s | %-40s | %-34s | %12d | %8.1f | %8.1f | %11.1f | %14.0f%% | %18.3f | %6d\n",
                $databaseName,
                $serverName,
                $requestName,
                $result['succeeded'] / $result['seconds'] * 60,
                $result['meanMilliseconds'],
                $result['p95Milliseconds'],
                $result['peakMemoryMegabytes'],
                $result['processorSeconds'] / $result['seconds'] * 100,
                $result['processorSeconds'] / max(1, $result['succeeded']) * 1000,
                $result['failed'],
            );
            foreach (array_slice($result['errors'], 0, 3, true) as $error => $count) {
                fwrite(STDERR, "    {$count} x {$error}\n");
            }
        }
        if (str_starts_with($serverName, 'no OpenSwoole')) {
            stopServers();
            sleep(SECONDS_FOR_CLOSED_DATABASE_CONNECTIONS_TO_FREE_THEIR_PORTS);
        }
    }
}
stopServers();

function send(string $action, int $requests, int $sentAtOnce, string $bodyTemplate): array
{
    $multi = curl_multi_init();
    $sent = 0;
    $running = [];
    $durations = [];
    $failed = 0;
    $errors = [];
    $startedAt = microtime(true);
    $memorySampledAt = 0.0;
    $peakMemory = 0.0;
    $processorSecondsBefore = processorSecondsOfServers();
    while ($sent < $requests || $running !== []) {
        while ($sent < $requests && count($running) < $sentAtOnce) {
            $handle = curl_init('http://127.0.0.1:' . PORT . '/' . $action);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => str_replace('{i}', (string) $sent, $bodyTemplate)]);
            curl_multi_add_handle($multi, $handle);
            $running[spl_object_id($handle)] = $handle;
            $sent++;
        }
        curl_multi_exec($multi, $active);
        curl_multi_select($multi, 0.01);
        if (microtime(true) - $memorySampledAt > 0.25) {
            $peakMemory = max($peakMemory, memoryOfServersInMegabytes());
            $memorySampledAt = microtime(true);
        }
        while ($done = curl_multi_info_read($multi)) {
            $handle = $done['handle'];
            $body = json_decode((string) curl_multi_getcontent($handle), true);
            if (($body['ok'] ?? false) === true) {
                $durations[] = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
            } else {
                $failed++;
                $error = substr((string) ($body['error'] ?? ('HTTP ' . curl_getinfo($handle, CURLINFO_HTTP_CODE) . ' ' . curl_error($handle))), 0, 200);
                $errors[$error] = ($errors[$error] ?? 0) + 1;
            }
            curl_multi_remove_handle($multi, $handle);
            unset($running[spl_object_id($handle)]);
        }
    }
    sort($durations);

    return [
        'succeeded' => count($durations),
        'failed' => $failed,
        'errors' => $errors,
        'seconds' => microtime(true) - $startedAt,
        'peakMemoryMegabytes' => max($peakMemory, memoryOfServersInMegabytes()),
        'processorSeconds' => processorSecondsOfServers() - $processorSecondsBefore,
        'meanMilliseconds' => $durations === [] ? 0 : array_sum($durations) / count($durations),
        'p95Milliseconds' => $durations === [] ? 0 : $durations[(int) floor(count($durations) * 0.95)],
    ];
}

function serverProcesses(): array
{
    $processes = [];
    foreach (glob('/proc/[0-9]*') as $process) {
        $commandLine = str_replace("\0", ' ', (string) @file_get_contents($process . '/cmdline'));
        if ((int) basename($process) !== getmypid() && (str_contains($commandLine, 'server.php') || str_contains($commandLine, 'php -S'))) {
            $processes[] = (int) basename($process);
        }
    }

    return $processes;
}

function stopServers(): void
{
    foreach (serverProcesses() as $process) {
        posix_kill($process, SIGKILL);
    }
    usleep(500_000);
}

function waitUntilServing(): void
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $handle = curl_init('http://127.0.0.1:' . PORT . '/state');
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_TIMEOUT => 2]);
        curl_exec($handle);
        if (curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200) {
            return;
        }
        usleep(200_000);
    }
    fwrite(STDERR, "Server did not start, see /tmp/benchmark-server.log\n");
    exit(1);
}

function processorSecondsOfServers(): float
{
    $ticks = 0;
    foreach (serverProcesses() as $process) {
        $statistics = (string) @file_get_contents("/proc/{$process}/stat");
        $fields = explode(' ', substr($statistics, (int) strrpos($statistics, ')') + 2));
        $ticks += (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0);
    }

    return $ticks / 100;
}

function memoryOfServersInMegabytes(): float
{
    $kilobytes = 0;
    foreach (serverProcesses() as $process) {
        if (preg_match('/^Pss:\s+(\d+) kB/m', (string) @file_get_contents("/proc/{$process}/smaps_rollup"), $proportional)) {
            $kilobytes += (int) $proportional[1];
        }
    }

    return $kilobytes / 1024;
}
