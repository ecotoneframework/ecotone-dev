<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * Ecotone 2.0 upgrade set: renames classes moved into `Api` namespaces (see §13 of upgrade-2.0.md).
 *
 * This is a plain PHP script driven by upgrade/namespace-map-2.0.csv; it is not a runtime
 * dependency of ecotone/ecotone. Run it against your own application with a project-local
 * Rector install:
 *
 *     composer require --dev rector/rector
 *     vendor/bin/rector process src --config=vendor/ecotone/ecotone/upgrade/rector-2.0.php
 *
 * (adjust the --config path if you installed ecotone/ecotone somewhere other than vendor/ecotone/ecotone,
 * and the `src` argument to whichever directories hold your application code).
 */
return static function (RectorConfig $rectorConfig): void {
    $csvPath = __DIR__ . '/namespace-map-2.0.csv';
    $classMap = [];
    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, ',', '"', ''),
        file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    );
    array_shift($rows);

    foreach ($rows as [$oldFqcn, $newFqcn]) {
        $classMap[$oldFqcn] = $newFqcn;
    }

    $rectorConfig->importNames();
    $rectorConfig->ruleWithConfiguration(RenameClassRector::class, $classMap);
};
