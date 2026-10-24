<?php

declare(strict_types=1);

$version = $argv[1] ?? '';
if ($version === '') {
    fwrite(STDERR, "A Pulse image on released packages needs the Ecotone release to install. Build it with --build-arg ECOTONE_VERSION=<released tag, e.g. 2.0.0-beta.6>, or leave PULSE_DEPENDENCIES unset to build on the packages of this repository.\n");
    exit(1);
}

$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
$composer['repositories'] = array_values(array_filter(
    $composer['repositories'] ?? [],
    static fn (array $repository): bool => ($repository['type'] ?? null) !== 'path'
));
if ($composer['repositories'] === []) {
    unset($composer['repositories']);
}
foreach (array_keys($composer['require']) as $package) {
    if (str_starts_with($package, 'ecotone/')) {
        $composer['require'][$package] = $version;
    }
}
$composer['require']['ecotone/ecotone'] = $version;

file_put_contents(__DIR__ . '/../composer.released.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
