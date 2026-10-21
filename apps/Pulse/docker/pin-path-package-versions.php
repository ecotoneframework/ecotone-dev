<?php

declare(strict_types=1);

$versions = [];
foreach (glob(__DIR__ . '/../../../packages/*/composer.json') as $file) {
    $versions[json_decode(file_get_contents($file), true)['name']] = '2.0.x-dev';
}

$composerFile = __DIR__ . '/../composer.json';
$composer = json_decode(file_get_contents($composerFile), true);
$composer['repositories'][0]['options']['versions'] = $versions;
if (($argv[1] ?? null) === '--copy') {
    $composer['repositories'][0]['options']['symlink'] = false;
}

file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
