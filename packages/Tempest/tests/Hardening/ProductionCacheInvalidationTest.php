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
use Throwable;

/**
 * Reproduces two production-cache defects:
 *
 * 1. The cached compiled container is loaded without validating its config
 *    hash against the current configuration — after code or config changes the
 *    app keeps running the stale messaging system until ecotone:cache:clear.
 *
 * 2. The environment is read exclusively from APP_ENV
 *    (getenv('APP_ENV') ?: 'production'), while Tempest's own convention is
 *    ENVIRONMENT — a fresh Tempest app sets ENVIRONMENT=local and still gets
 *    production caching (which is also how PHPUnit runs silently reuse the
 *    live app's cache).
 *
 * licence Apache-2.0
 * @internal
 */
final class ProductionCacheInvalidationTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';
        $this->wipeCacheDirectory();

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('ENVIRONMENT');

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->wipeCacheDirectory();
    }

    public function test_stale_production_cache_is_rebuilt_when_configuration_changes(): void
    {
        putenv('APP_ENV=production');

        $firstSystem = (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\CachePing\\']),
        );
        $this->assertSame('pong', $firstSystem->getCommandBus()->sendWithRouting('hardening.cache.ping'));

        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $secondSystem = (new MessagingSystemInitializer())->initialize(
            $this->containerWithNamespaces(['Test\\Ecotone\\Tempest\\Hardening\\Fixture\\NoHandlers\\']),
        );

        try {
            $secondSystem->getCommandBus()->sendWithRouting('hardening.cache.ping');
        } catch (DestinationResolutionException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The configuration no longer scans the CachePing namespace — a rebuilt messaging system must not know this routing. The stale production cache was reused.');
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
