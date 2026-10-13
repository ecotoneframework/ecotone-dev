<?php
/*
 * licence Apache-2.0
 */

use Symfony\Component\Finder\Finder;

return static function (string $monorepoDirectory): Finder {
    return (new Finder())
        ->files()
        ->in($monorepoDirectory . '/packages/*')
        ->exclude('vendor')
        ->name('*.php')
        ->contains('/\*\s*(@licence|licence)\s+Enterprise\b/')
        ->sortByName();
};
