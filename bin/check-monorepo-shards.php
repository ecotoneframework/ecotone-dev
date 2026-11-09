<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

$root = dirname(__DIR__);
$shards = json_decode((string) file_get_contents($root . '/.github/monorepo-shards.json'), associative: true, flags: JSON_THROW_ON_ERROR);

preg_match_all('/<testsuite name="([^"]+)"/', (string) file_get_contents($root . '/phpunit.xml.dist'), $matches);
$configured = $matches[1];
sort($configured);

$sharded = [];
foreach ($shards as $shard) {
    foreach (explode(',', $shard['suites']) as $suite) {
        $sharded[] = trim($suite);
    }
}
sort($sharded);

$missing = array_values(array_diff($configured, $sharded));
$unknown = array_values(array_diff($sharded, $configured));
$duplicated = array_values(array_unique(array_diff_assoc($sharded, array_unique($sharded))));

if ($missing !== [] || $unknown !== [] || $duplicated !== []) {
    fwrite(STDERR, "The shards in .github/monorepo-shards.json do not cover the test suites of phpunit.xml.dist exactly once.\n");
    if ($missing !== []) {
        fwrite(STDERR, 'Suites in phpunit.xml.dist but in no shard: ' . implode(', ', $missing) . "\n");
    }
    if ($unknown !== []) {
        fwrite(STDERR, 'Suites in a shard but not in phpunit.xml.dist: ' . implode(', ', $unknown) . "\n");
    }
    if ($duplicated !== []) {
        fwrite(STDERR, 'Suites in more than one shard: ' . implode(', ', $duplicated) . "\n");
    }
    exit(1);
}

echo json_encode($shards, JSON_THROW_ON_ERROR), PHP_EOL;
