<?php

declare(strict_types=1);

use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\ConfigurationUrlParser;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['ECOTONE_LICENCE_KEY'] ?? getenv('ECOTONE_LICENCE_KEY')) == false) {
    $_SERVER['ECOTONE_LICENCE_KEY'] = $_ENV['ECOTONE_LICENCE_KEY'] = LicenceTesting::VALID_LICENCE;
    putenv('ECOTONE_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
}

$testProcessApplicationName = 'storefront_tests_' . getmypid();
$_SERVER['DB_APPLICATION_NAME'] = $_ENV['DB_APPLICATION_NAME'] = $testProcessApplicationName;
putenv('DB_APPLICATION_NAME=' . $testProcessApplicationName);

$storefront = require dirname(__DIR__) . '/bootstrap/app.php';
$storefront->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
$testDatabase = 'storefront_test';
$storefrontConfig = $storefront->make('config');
$resolvedDatabase = (new ConfigurationUrlParser())->parseConfiguration($storefrontConfig->get('database.connections.' . $storefrontConfig->get('database.default')))['database'] ?? null;
$dsnDatabase = ltrim((string) parse_url((string) getenv('DATABASE_DSN'), PHP_URL_PATH), '/');
if ($resolvedDatabase !== $testDatabase || $dsnDatabase !== $testDatabase) {
    $pinTheVariable = ' redirects it: unset it in the environment, or pin it with force="true" as both <env> and <server> in phpunit.dist.xml, as every database variable there is, then run the tests again.';
    $wayOut = match (true) {
        $dsnDatabase !== $testDatabase => 'DATABASE_DSN' . $pinTheVariable,
        $storefront->configurationIsCached() => sprintf('The cached configuration %s holds it: delete that file, or run APP_CONFIG_CACHE=bootstrap/cache/config.testing.php php artisan config:clear, then run the tests again.', $storefront->getCachedConfigPath()),
        (string) env('DB_URL') !== '' => 'DB_URL' . $pinTheVariable,
        env('DB_DATABASE') !== $testDatabase => 'DB_DATABASE' . $pinTheVariable,
        default => 'DB_CONNECTION' . $pinTheVariable,
    };
    fwrite(STDERR, sprintf('The storefront tests refuse to run: they wipe and migrate the database they are pointed at, and it is %s, not %s. %s' . PHP_EOL, $dsnDatabase !== $testDatabase ? $dsnDatabase : $resolvedDatabase, $testDatabase, $wayOut));
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
