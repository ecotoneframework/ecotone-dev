<?php

declare(strict_types=1);

/**
 * licence Apache-2.0
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/gc-probe.php';
if (getenv('LITE_RESOLVER_PROBE')) {
    require getenv('LITE_RESOLVER_PROBE');
}

$benchmark = new \Monorepo\Benchmark\LiteFlowTestingBenchmark();
$method = $argv[1] ?? 'bench_bootstrap_send_assert';
$iterations = (int) ($argv[2] ?? 1000);
if ($method === 'bench_bus_execution') {
    $benchmark->setUpExecution();
}
$start = hrtime(true);
for ($iteration = 0; $iteration < $iterations; ++$iteration) {
    $benchmark->$method();
}
echo json_encode(['method' => $method, 'iterations' => $iterations, 'seconds' => getenv('LITE_MEMORY_ONLY') ? null : (hrtime(true) - $start) / 1e9, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR) . "\n";
