<?php

declare(strict_types=1);

use Ecotone\Test\LicenceTesting;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['ECOTONE_LICENCE_KEY'] ?? getenv('ECOTONE_LICENCE_KEY')) == false) {
    $_SERVER['ECOTONE_LICENCE_KEY'] = $_ENV['ECOTONE_LICENCE_KEY'] = LicenceTesting::VALID_LICENCE;
    putenv('ECOTONE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
}

foreach (['db:wipe --force', 'migrate --force', 'ecotone:migration:database:setup --initialize'] as $artisanCommand) {
    passthru(sprintf('%s %s %s --env=testing', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/artisan'), $artisanCommand), $artisanStatus);
    if ($artisanStatus !== 0) {
        fwrite(STDERR, sprintf('The storefront_test database could not be prepared: php artisan %s exited with code %d. Check that postgres is reachable with the DB_* settings in phpunit.dist.xml and fix the error printed above, then run the tests again.' . PHP_EOL, $artisanCommand, $artisanStatus));
        exit(1);
    }
}
