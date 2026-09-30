<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

return static function (string $monorepoDirectory): array {
    $strayVendors = array_map(
        static fn (string $vendorPath): string => 'packages/' . basename(dirname($vendorPath)) . '/vendor',
        glob($monorepoDirectory . '/packages/*/vendor', GLOB_ONLYDIR) ?: [],
    );
    if ($strayVendors === []) {
        return [];
    }

    return ['stray package vendor: found ' . implode(', ', $strayVendors) . ", left behind by a per-package composer install.\n"
        . "   A test that passes its package directory to EcotoneLite finds the nearest vendor/autoload.php, which is now the package's own, and requires it inside the root full suite. "
        . "Composer prepends that classloader, so the package's dependency versions and autoload-dev map replace the root ones for the rest of the run: "
        . "a version clash dies as an uncatchable 'Premature end of PHP process' in an unrelated test, and a namespace missing from the root composer.json resolves anyway, hiding the divergence.\n"
        . '   Fix: before a root run, remove them with `find packages -maxdepth 2 -name vendor -type d -prune -exec rm -rf {} +` '
        . '(or move them aside and move them back before the next per-package run).'];
};
