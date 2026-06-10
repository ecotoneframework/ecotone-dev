<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
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
use Test\Ecotone\Tempest\TempestTestPaths;

/**
 * licence Apache-2.0
 * @internal
 */
final class AppNamespaceAutoDiscoveryTest extends IntegrationTest
{
    protected string $root = '';

    public function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        $this->internalStorage = '/tmp/ecotone_tempest_auto_ns_' . getmypid();
        $this->setupKernel();
    }

    public function setupKernel(): self
    {
        if ($this->root === '') {
            $this->root = TempestTestPaths::appRoot();
        }

        EcotoneServiceInitializer::clearCache();

        $appSrcLocation = new DiscoveryLocation('App\\Tempest\\', TempestTestPaths::appRoot() . '/src');

        $allLocations = [
            new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath()),
            $appSrcLocation,
        ];

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

        $this->injectDiscoveryConfig($kernel, $allLocations);

        $kernel->container->config(new EcotoneConfig(
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        ));

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
    }

    private function injectDiscoveryConfig(FrameworkKernel $kernel, array $extraLocations): void
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

    public function test_handlers_in_app_namespace_discovered_without_explicit_namespaces_config(): void
    {
        $commandBus = $this->container->get(CommandBus::class);
        $queryBus = $this->container->get(QueryBus::class);

        $commandBus->sendWithRouting('app.ping');

        $this->assertTrue($queryBus->sendWithRouting('app.wasHandled'));
    }
}
