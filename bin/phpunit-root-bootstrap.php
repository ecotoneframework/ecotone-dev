<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

$strayPackageVendors = (require __DIR__ . '/suite-parity/stray-package-vendor.php')(dirname(__DIR__));
if ($strayPackageVendors !== []) {
    fwrite(STDERR, "The root full suite refuses to start, because its results would not be the root suite's:\n\n" . implode("\n\n", $strayPackageVendors) . "\n");
    exit(1);
}
