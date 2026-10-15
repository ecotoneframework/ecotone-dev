<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

$monorepoDirectory = dirname(__DIR__);
$checks = glob(__DIR__ . '/suite-parity/*.php');
sort($checks);

$divergences = [];
foreach ($checks as $check) {
    $findDivergences = require $check;
    array_push($divergences, ...$findDivergences($monorepoDirectory));
}

if ($divergences === []) {
    echo 'Suite parity: the monorepo root and every package run their tests under the same configuration (' . count($checks) . " checks).\n";
    exit(0);
}

echo 'Suite parity: ' . count($divergences) . " divergence(s) between the monorepo root and the per-package test runs.\n";
echo "A package whose own suite passes can fail in the root full suite, or the reverse, until each one is fixed.\n\n";
foreach ($divergences as $number => $divergence) {
    echo ($number + 1) . ') ' . $divergence . "\n\n";
}
exit(1);
