<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

return static function (string $monorepoDirectory): array {
    $phpunitConfiguration = static fn (string $directory): string => is_file($directory . '/phpunit.xml') ? $directory . '/phpunit.xml' : $directory . '/phpunit.xml.dist';
    $normalizePath = static fn (string $path): string => rtrim(preg_replace('#^\./#', '', str_replace('\\', '/', trim($path))), '/');
    $testLocations = static function (string $configurationPath, string $pathPrefix) use ($normalizePath): array {
        $locations = [];
        foreach (simplexml_load_file($configurationPath)->xpath('/phpunit/testsuites/testsuite/*') as $location) {
            $suffix = isset($location['suffix']) ? " suffix=\"{$location['suffix']}\"" : '';
            $locations[] = "<{$location->getName()}{$suffix}>{$pathPrefix}{$normalizePath((string) $location)}</{$location->getName()}>";
        }
        sort($locations);

        return array_values(array_unique($locations));
    };

    $rootConfiguration = $phpunitConfiguration($monorepoDirectory);
    $rootConfigurationName = basename($rootConfiguration);
    $rootLocations = $testLocations($rootConfiguration, '');

    $divergences = [];
    foreach (glob($monorepoDirectory . '/packages/*', GLOB_ONLYDIR) as $packagePath) {
        $packageDirectory = 'packages/' . basename($packagePath);
        $packageConfiguration = $phpunitConfiguration($packagePath);
        if (! is_file($packageConfiguration)) {
            continue;
        }

        $packageLocations = $testLocations($packageConfiguration, $packageDirectory . '/');
        $rootLocationsForPackage = array_values(array_filter(
            $rootLocations,
            static fn (string $location): bool => str_contains($location, '>' . $packageDirectory . '/') || str_contains($location, '>' . $packageDirectory . '<'),
        ));
        if ($packageLocations === $rootLocationsForPackage) {
            continue;
        }

        $packageConfigurationName = $packageDirectory . '/' . basename($packageConfiguration);
        $divergences[] = "testsuite directories: {$rootConfigurationName} and {$packageConfigurationName} run different tests for {$packageDirectory}.\n"
            . "   {$packageConfigurationName} runs: " . implode(' ', $packageLocations) . "\n"
            . "   {$rootConfigurationName} runs: " . ($rootLocationsForPackage === [] ? "nothing under {$packageDirectory}/" : implode(' ', $rootLocationsForPackage)) . "\n"
            . "   A test file that one list reaches and the other does not runs under one runner only: a root-only test never runs in the package's own suite or its split repository, "
            . "and a package-only test is not run when a core change is checked by the root full suite.\n"
            . "   Fix: make the testsuite for {$packageDirectory} in {$rootConfigurationName} list exactly " . implode(' ', $packageLocations)
            . ' — the package configuration is what the split repository runs.';
    }

    return $divergences;
};
