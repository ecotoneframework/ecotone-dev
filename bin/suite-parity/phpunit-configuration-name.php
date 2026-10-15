<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

return static function (string $monorepoDirectory): array {
    $divergences = [];
    foreach (glob($monorepoDirectory . '/packages/*', GLOB_ONLYDIR) as $packagePath) {
        $packageDirectory = 'packages/' . basename($packagePath);
        if (is_file($packagePath . '/phpunit.xml.dist')) {
            continue;
        }

        $divergences[] = "phpunit configuration name: {$packageDirectory} has no phpunit.xml.dist"
            . (is_file($packagePath . '/phpunit.xml') ? " — its configuration is committed as {$packageDirectory}/phpunit.xml" : '') . ".\n"
            . '   .github/workflows/split-testing.yml selects the packages whose own suite CI runs with `find packages -maxdepth 2 -name phpunit.xml.dist`, '
            . "so {$packageDirectory}'s own suite never runs in CI while the root full suite runs its tests, and the root .gitignore reserves phpunit.xml for a local override.\n"
            . '   Fix: ' . (is_file($packagePath . '/phpunit.xml') ? "git mv {$packageDirectory}/phpunit.xml {$packageDirectory}/phpunit.xml.dist" : "add {$packageDirectory}/phpunit.xml.dist, with a testsuite matching the root phpunit.xml.dist entry for {$packageDirectory}") . '.';
    }

    return $divergences;
};
