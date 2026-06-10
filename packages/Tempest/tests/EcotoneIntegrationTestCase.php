<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
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
abstract class EcotoneIntegrationTestCase extends IntegrationTest
{
    protected string $root = '';

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
            new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath()),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', TempestTestPaths::fixturePath()),
        ];
    }

    protected function setupKernel(): self
    {
        EcotoneServiceInitializer::clearCache();

        if ($this->root === '') {
            $this->root = TempestTestPaths::appRoot();
        }

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

        $kernel->container->config($this->ecotoneConfig());

        $kernel->loadConfig()
               ->bootDiscovery()
               ->registerExceptionHandler()
               ->event(KernelEvent::BOOTED);

        $this->kernel = $kernel;
        $this->container = $kernel->container;

        return $this;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
        restore_error_handler();
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
        $this->removeProxyCache();
    }

    private function removeProxyCache(): void
    {
        $proxyDirs = [
            MessagingSystemInitializer::getProxyDirectory(),
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest_console_proxies',
        ];

        foreach (array_filter($proxyDirs) as $proxyDir) {
            if (is_dir($proxyDir)) {
                foreach (glob($proxyDir . '/*.php') ?: [] as $file) {
                    @unlink($file);
                }
                @unlink($proxyDir . '/.ecotone_hash');
                @rmdir($proxyDir);
            }
        }
    }

    private function injectDiscoveryAndConfig(FrameworkKernel $kernel, array $extraLocations): void
    {
        $testAppComposer = (new Composer($this->root))->load();
        $testAppComposer->namespaces = [];

        $autoloadLocations = (new AutoloadDiscoveryLocations(
            rootPath: TempestTestPaths::discoveryRoot(),
            composer: $testAppComposer,
        ))();

        $discoveryConfig = $kernel->container->get(DiscoveryConfig::class);
        $discoveryConfig->locations = [...$extraLocations, ...$autoloadLocations];

        $kernel->container->config($discoveryConfig);
        $kernel->discoveryConfig = $discoveryConfig;
    }
}
