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