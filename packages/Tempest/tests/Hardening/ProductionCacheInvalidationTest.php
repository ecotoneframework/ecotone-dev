<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use const DIRECTORY_SEPARATOR;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Handler\DestinationResolutionException;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;

/**
 * Production-cache contract:
 *
 * 1. The cached compiled container is TRUSTED — a warm production boot never
 *    rescans or hashes application files (that cost is exactly what the cache
 *    exists to avoid). A configuration change therefore takes effect only
 *    after the cache is cleared; staleness that breaks class loading is
 *    attributed honestly at dispatch (see StaleCacheFailureAttributionTest in
 *    core).
 *
 * 2. The environment is read from APP_ENV or Tempest's own ENVIRONMENT
 *    convention — a fresh Tempest app sets ENVIRONMENT=local and must not get
 *    production caching (which is also how PHPUnit runs silently reuse the
 *    live app's cache).
 *
 * licence Apache-2.0
 * @internal
 */
final class ProductionCacheInvalidationTest extends TestCase
{
    private string $cacheDirectory;

    private string|false $originalAppEnv = false;

    private string|false $originalEnvironment = false;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $this->wipeCacheDirectory();

        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalEnvironment = getenv('ENVIRONMENT');

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    protected function tearDown(): void
    {
        putenv($this->originalAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->originalAppEnv);
        putenv($this->originalEnvironment === false ? 'ENVIRONMENT' : 'ENVIRONMENT=' . $this->originalEnvironment);

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->wipeCacheDirectory();
    }

    public function test_production_cache_is_trusted_until_cleared(): void
    {
        putenv('APP_ENV=production');

        $firstSystem = (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\CachePing\\']),
        );
        $this->assertSame('pong', $firstSystem->getCommandBus()->sendWithRouting('hardening.cache.ping'));

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $reusedCacheSystem = (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\NoHandlers\\']),
        );

        $this->assertSame(
            'pong',
            $reusedCacheSystem->getCommandBus()->sendWithRouting('hardening.cache.ping'),
            'A warm production boot must trust the cache without rescanning files — the changed configuration takes effect only after the cache is cleared',
        );

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
        $this->wipeCacheDirectory();

        $rebuiltSystem = (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\NoHandlers\\']),
        );

        try {
            $rebuiltSystem->getCommandBus()->sendWithRouting('hardening.cache.ping');
        } catch (DestinationResolutionException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('After clearing the cache the rebuilt messaging system must reflect the new configuration, which no longer knows this routing');
    }

    public function test_tempest_environment_variable_disables_production_caching(): void
    {
        putenv('APP_ENV');
        putenv('ENVIRONMENT=local');

        (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\CachePing\\']),
        );

        $this->assertFileDoesNotExist(
            $this->cacheDirectory . DIRECTORY_SEPARATOR . 'ecotone_container.php',
            'With ENVIRONMENT=local (Tempest convention) the production cache layout must not be used',
        );
    }

    private function containerWithNamespaces(array $namespaces): GenericContainer
    {
        $container = new GenericContainer();
        $container->config(new EcotoneConfig(
            namespaces: $namespaces,
            skippedModulePackageNames: ModulePackageList::allPackages(),
        ));

        return $container;
    }

    private function wipeCacheDirectory(): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->cacheDirectory);
    }
}
