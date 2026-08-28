<?php

require __DIR__ . '/get-packages';
$packages = getPackages();

$version = $argv[1];
if (!$version) {
    throw new \InvalidArgumentException("Pass version to update branch alias");
}
if (!preg_match('/^(\d+)\.(\d+)\./', $version, $versionParts)) {
    throw new \InvalidArgumentException("Version must be in format MAJOR.MINOR.PATCH with optional stability suffix, got: " . $version);
}
$branchAlias = $versionParts[1] . '.' . $versionParts[2] . '.x-dev';
$packageNames = array_map(function ($package) {
    return $package['package'];
}, $packages);

foreach ($packages as $package) {
    $composerFile = $package['directory'] . DIRECTORY_SEPARATOR . 'composer.json';
    $composer = json_decode(file_get_contents($composerFile), true);
    $composer['extra']['branch-alias']['dev-main'] = $branchAlias;
    $releaseTime = (new \DateTimeImmutable('now', new DateTimeZone('UTC')));
    $composer['extra']['release-time'] = $releaseTime->format('Y-m-d H:i:s');

    foreach ($composer['require'] ?? [] as $requiredPackage => $requiredVersion) {
        if (in_array($requiredPackage, $packageNames)) {
            $composer['require'][$requiredPackage] = "~" . $version;
        }
    }
    foreach ($composer['require-dev'] ?? [] as $requiredPackage => $requiredVersion) {
        if (in_array($requiredPackage, $packageNames)) {
            $composer['require-dev'][$requiredPackage] = '~' . $version;
        }
    }

    file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

// Quickstart examples directly require some local packages (see bin/get-packages) so their tests
// resolve them from the path repository instead of Packagist; keep those requirements pinned to
// "~$version" in sync with whatever gets released, same as the sibling package requires above.
$quickstartDirectory = realpath(__DIR__ . '/../quickstart-examples');
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($quickstartDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        fn ($current) => $current->getFilename() !== 'vendor'
    )
);

foreach ($iterator as $file) {
    if ($file->getFilename() !== 'composer.json') {
        continue;
    }

    $path = $file->getPathname();
    $composer = json_decode(file_get_contents($path), true);
    $touched = false;

    foreach (['require', 'require-dev'] as $section) {
        foreach ($composer[$section] ?? [] as $requiredPackage => $requiredVersion) {
            if (in_array($requiredPackage, $packageNames)) {
                $composer[$section][$requiredPackage] = '~' . $version;
                $touched = true;
            }
        }
    }

    if ($touched) {
        file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n");
    }
}