<?php

require __DIR__ . '/get-packages';
$packages = getPackages();

$version = $argv[1];
if (!$version) {
    throw new \InvalidArgumentException("Pass version to update branch alias");
}
$packageNames = array_map(function ($package) {
    return $package['package'];
}, $packages);

foreach ($packages as $package) {
    $composerFile = $package['directory'] . DIRECTORY_SEPARATOR . 'composer.json';
    $composer = json_decode(file_get_contents($composerFile), true);
    $composer['extra']['branch-alias']['dev-main'] = $version . '-dev';
    $releaseTime = (new \DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+15 min");
    $composer['extra']['release-time'] = $releaseTime->format('Y-m-d H:i:s');

    foreach ($composer['require'] as $requiredPackage => $requiredVersion) {
        if (in_array($requiredPackage, $packageNames)) {
            $composer['require'][$requiredPackage] = "~" . $version;
        }
    }
    foreach ($composer['require-dev'] as $requiredPackage => $requiredVersion) {
        if (in_array($requiredPackage, $packageNames)) {
            $composer['require-dev'][$requiredPackage] = '~' . $version;
        }
    }

    file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}