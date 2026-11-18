<?php

/**
 * php client.php <port> <action> <requests> <concurrency> [json-template, {i} is replaced by the request number]
 * Prints how the requests spread over workers, coroutines and database sessions, and every distinct failure.
 */
$argv = $_SERVER['argv'];
[, $port, $action, $requests, $concurrency] = $argv;
$template = $argv[5] ?? '{}';
$run = bin2hex(random_bytes(3));

$multi = curl_multi_init();
$sent = 0;
$running = [];
$summary = ['ok' => 0, 'failed' => 0, 'workers' => [], 'sessions' => [], 'errors' => [], 'http' => []];
$started = microtime(true);

$send = static function () use (&$sent, &$running, $multi, $port, $action, $template, $run): void {
    $handle = curl_init("http://127.0.0.1:{$port}/{$action}");
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => str_replace('{i}', $run . '-' . $sent, $template)]);
    curl_multi_add_handle($multi, $handle);
    $running[(int) $handle] = $handle;
    $sent++;
};

while ($sent < $requests || $running !== []) {
    while ($sent < $requests && count($running) < $concurrency) {
        $send();
    }
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 0.05);
    while ($done = curl_multi_info_read($multi)) {
        $handle = $done['handle'];
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $body = json_decode((string) curl_multi_getcontent($handle), true);
        $summary['http'][$status] = ($summary['http'][$status] ?? 0) + 1;
        if (($body['ok'] ?? false) === true) {
            $summary['ok']++;
        } else {
            $summary['failed']++;
            $error = substr($body['error'] ?? ('HTTP ' . $status . ' ' . substr((string) curl_multi_getcontent($handle), 0, 300)), 0, 400);
            $summary['errors'][$error] = ($summary['errors'][$error] ?? 0) + 1;
        }
        if (isset($body['worker_pid'])) {
            $summary['workers'][$body['worker_pid']] = ($summary['workers'][$body['worker_pid']] ?? 0) + 1;
        }
        if (isset($body['session_id'])) {
            $summary['sessions'][$body['session_id']] = ($summary['sessions'][$body['session_id']] ?? 0) + 1;
        }
        curl_multi_remove_handle($multi, $handle);
        unset($running[(int) $handle]);
    }
}

$summary['seconds'] = round(microtime(true) - $started, 2);
$summary['run'] = $run;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
