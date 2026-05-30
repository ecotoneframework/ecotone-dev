<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\KernelEvent;
use Tempest\Discovery\AutoloadDiscoveryLocations;
use Tempest\Discovery\Composer;
use Tempest\Discovery\DiscoveryConfig;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Framework\Testing\IntegrationTest;

/**
 * licence Apache-2.0
 */
abstract class EcotoneIntegrationTest extends IntegrationTest
{
    protected string $root = '/data/app/packages/Tempest/tests/app';

    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            new DiscoveryLocation('Ecotone\\Tempest\\', '/data/app/packages/Tempest/src'),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', '/data/app/packages/Tempest/tests/Fixture'),
        ];
    }

    protected function setupKernel(): self
    {
        EcotoneServiceInitializer::clearCache();

        $this->internalStorage = '/tmp/ecotone_tempest_test_storage_' . getmypid();

        $allLocations = [...$this->discoveryLocations, ...$this->discoverTestLocations()];

        $kernel = new FrameworkKernel(
            root: $this->root,
            discoveryLocations: $allLocations,
            internalStorage: $this->internalStorage,
        );

        $kernel->registerKernel()
               ->validateRoot()
               ->loadEnv()
               ->registerEmergencyExceptionHandler()
               ->registerShutdownFunction()
               ->registerInternalStorage()
               ->loadComposer();

        $this->injectDiscoveryAndConfig($kernel, $allLocations);

        $kernel->loadConfig()
               ->bootDiscovery()
               ->registerExceptionHandler()
               ->event(KernelEvent::BOOTED);

        $this->kernel = $kernel;
        $this->container = $kernel->container;

        $this->container->config($this->ecotoneConfig());

        return $this;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
        restore_error_handler();
        EcotoneServiceInitializer::clearCache();
    }

    private function injectDiscoveryAndConfig(FrameworkKernel $kernel, array $extraLocations): void
    {
        $testAppComposer = (new Composer($this->root))->load();
        $testAppComposer->namespaces = [];

        $autoloadLocations = (new AutoloadDiscoveryLocations(
            rootPath: '/data/app',
            composer: $testAppComposer,
        ))();

        $discoveryConfig = $kernel->container->get(DiscoveryConfig::class);
        $discoveryConfig->locations = [...$extraLocations, ...$autoloadLocations];

        $kernel->container->config($discoveryConfig);
        $kernel->discoveryConfig = $discoveryConfig;
    }
}
