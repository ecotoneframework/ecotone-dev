<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;

final class TestDatabaseGuard
{
    private const TEST_DATABASE = 'storefront_test';

    public static function refusalOf(Application $storefront, string $databaseDsn): ?string
    {
        $configuration = $storefront->make('config');
        $resolvedDatabase = (new ConfigurationUrlParser())->parseConfiguration($configuration->get('database.connections.' . $configuration->get('database.default')))['database'] ?? null;
        $dsnDatabase = ltrim((string) parse_url($databaseDsn, PHP_URL_PATH), '/');
        if ($resolvedDatabase === self::TEST_DATABASE && $dsnDatabase === self::TEST_DATABASE) {
            return null;
        }
        $pinTheVariable = ' redirects it: unset it in the environment, or pin it with force="true" as both <env> and <server> in phpunit.dist.xml, as every database variable there is, then run the tests again.';
        $wayOut = match (true) {
            $dsnDatabase !== self::TEST_DATABASE => 'DATABASE_DSN' . $pinTheVariable,
            $storefront->configurationIsCached() => sprintf('The cached configuration %s holds it: delete that file, or run APP_CONFIG_CACHE=bootstrap/cache/config.testing.php php artisan config:clear, then run the tests again.', $storefront->getCachedConfigPath()),
            (string) env('DB_URL') !== '' => 'DB_URL' . $pinTheVariable,
            env('DB_DATABASE') !== self::TEST_DATABASE => 'DB_DATABASE' . $pinTheVariable,
            default => 'DB_CONNECTION' . $pinTheVariable,
        };

        return sprintf('The storefront tests refuse to run: they wipe and migrate the database they are pointed at, and it is %s, not %s. %s', $dsnDatabase !== self::TEST_DATABASE ? $dsnDatabase : $resolvedDatabase, self::TEST_DATABASE, $wayOut);
    }
}
