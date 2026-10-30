<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use PHPUnit\Framework\TestCase;
use Tests\TestDatabaseGuard;

/**
 * @internal
 */
final class TestDatabaseGuardTest extends TestCase
{
    private string $cachedConfiguration;

    protected function setUp(): void
    {
        $this->cachedConfiguration = dirname(__DIR__, 2) . '/bootstrap/cache/config.testing.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cachedConfiguration)) {
            unlink($this->cachedConfiguration);
        }
    }

    public function test_the_pinned_test_database_is_not_refused(): void
    {
        self::assertNull(TestDatabaseGuard::refusalOf(storefront: $this->storefront(), databaseDsn: (string) getenv('DATABASE_DSN')));
    }

    public function test_a_stale_cached_configuration_is_refused_naming_the_cache_file_and_config_clear(): void
    {
        $configuration = $this->storefront()->make('config');
        $configuration->set('database.connections.' . $configuration->get('database.default') . '.database', 'storefront_stale_cache');
        file_put_contents($this->cachedConfiguration, '<?php return ' . var_export($configuration->all(), true) . ';' . PHP_EOL);

        self::assertSame(
            sprintf('The storefront tests refuse to run: they wipe and migrate the database they are pointed at, and it is storefront_stale_cache, not storefront_test. The cached configuration %s holds it: delete that file, or run APP_CONFIG_CACHE=bootstrap/cache/config.testing.php php artisan config:clear, then run the tests again.', $this->cachedConfiguration),
            TestDatabaseGuard::refusalOf(storefront: $this->storefront(), databaseDsn: (string) getenv('DATABASE_DSN')),
        );
    }

    public function test_a_database_dsn_pointing_elsewhere_is_refused_naming_the_variable(): void
    {
        self::assertSame(
            'The storefront tests refuse to run: they wipe and migrate the database they are pointed at, and it is storefront, not storefront_test. DATABASE_DSN redirects it: unset it in the environment, or pin it with force="true" as both <env> and <server> in phpunit.dist.xml, as every database variable there is, then run the tests again.',
            TestDatabaseGuard::refusalOf(storefront: $this->storefront(), databaseDsn: 'pgsql://ecotone:secret@postgres:5432/storefront'),
        );
    }

    private function storefront(): Application
    {
        $storefront = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $storefront->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

        return $storefront;
    }
}
