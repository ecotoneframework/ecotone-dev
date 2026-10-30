<?php

declare(strict_types=1);

use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Tests\TestDatabaseGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['ECOTONE_LICENCE_KEY'] ?? getenv('ECOTONE_LICENCE_KEY')) == false) {
    $_SERVER['ECOTONE_LICENCE_KEY'] = $_ENV['ECOTONE_LICENCE_KEY'] = LicenceTesting::VALID_LICENCE;
    putenv('ECOTONE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
}

$testProcessApplicationName = sprintf('storefront_tests_%s_%d_%s', gethostname(), getmypid(), bin2hex(random_bytes(4)));
$_SERVER['DB_APPLICATION_NAME'] = $_ENV['DB_APPLICATION_NAME'] = $testProcessApplicationName;
putenv('DB_APPLICATION_NAME=' . $testProcessApplicationName);

$storefront = require dirname(__DIR__) . '/bootstrap/app.php';
$storefront->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
$refusal = TestDatabaseGuard::refusalOf(storefront: $storefront, databaseDsn: (string) getenv('DATABASE_DSN'));
if ($refusal !== null) {
    fwrite(STDERR, $refusal . PHP_EOL);
    exit(1);
}
$storefront->flush();

foreach (['db:wipe --force', 'migrate --force', 'ecotone:migration:database:setup --initialize'] as $artisanCommand) {
    passthru(sprintf('%s %s %s --env=testing', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/artisan'), $artisanCommand), $artisanStatus);
    if ($artisanStatus !== 0) {
        fwrite(STDERR, sprintf('The storefront_test database could not be prepared: php artisan %s exited with code %d. Check that postgres is reachable with the DB_* settings in phpunit.dist.xml and fix the error printed above, then run the tests again.' . PHP_EOL, $artisanCommand, $artisanStatus));
        exit(1);
    }
}
