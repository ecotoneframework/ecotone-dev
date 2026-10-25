<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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

    public function test_a_stale_cached_configuration_is_refused_naming_the_cache_file_and_config_clear(): void
    {
        (new Process([PHP_BINARY, 'artisan', 'config:cache'], dirname(__DIR__, 2), ['DB_DATABASE' => 'storefront_stale_cache']))->mustRun();

        $guard = new Process([PHP_BINARY, 'tests/bootstrap.php'], dirname(__DIR__, 2));
        $guard->run();

        self::assertSame(1, $guard->getExitCode());
        self::assertSame(sprintf('The storefront tests refuse to run: they wipe and migrate the database they are pointed at, and it is storefront_stale_cache, not storefront_test. The cached configuration %s holds it: delete that file, or run APP_CONFIG_CACHE=bootstrap/cache/config.testing.php php artisan config:clear, then run the tests again.' . PHP_EOL, $this->cachedConfiguration), $guard->getErrorOutput());
    }
}
