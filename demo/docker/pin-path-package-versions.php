<?php

declare(strict_types=1);

$versions = [];
foreach (glob(__DIR__ . '/../../packages/*/composer.json') as $file) {
    $versions[json_decode(file_get_contents($file), true)['name']] = '2.0.x-dev';
}

$application = $argv[1] ?? getcwd();
$composer = json_decode(file_get_contents($application . '/composer.json'), true);
$composer['repositories'][0]['options']['versions'] = $versions;

file_put_contents($application . '/composer.pinned.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
