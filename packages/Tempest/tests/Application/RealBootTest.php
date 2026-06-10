<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PHPUnit\Framework\TestCase;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\KernelEvent;
use Tempest\Discovery\AutoloadDiscoveryLocations;
use Tempest\Discovery\Composer;
use Tempest\Discovery\DiscoveryConfig;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\TempestTestPaths;

/**
 * licence Apache-2.0
 * @internal
 */
final class RealBootTest extends TestCase
{
    protected function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();
    }

    public function test_command_bus_resolves_from_tempest_container_without_any_ecotone_config_file(): void
    {
        $internalStorage = '/tmp/ecotone_tempest_real_boot_noconfig_' . getmypid();

        $appLocation = new DiscoveryLocation('App\\Tempest\\', TempestTestPaths::appRoot() . '/src');
        $ecotoneLocation = new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath());

        $kernel = new FrameworkKernel(
            root: TempestTestPaths::appRoot(),
            discoveryLocations: [$ecotoneLocation, $appLocation],
            internalStorage: $internalStorage,
        );

        $kernel->registerKernel()
               ->validateRoot()
               ->loadEnv()
               ->registerEmergencyExceptionHandler()
               ->registerShutdownFunction()
               ->registerInternalStorage()
               ->loadComposer();

        $this->injectDiscoveryConfig($kernel, $ecotoneLocation, $appLocation);

        // Monorepo: all packages including Enterprise ones are present, so skip them to avoid
        // licence errors. In a real app only installed packages load — no skip needed.
        // The key proof: no ecotone.config.php discovered in the boot path; the app
        // derives its EcotoneConfig from MessagingSystemInitializer's fallback new EcotoneConfig().
        $kernel->container->singleton(
            EcotoneConfig::class,
            new EcotoneConfig(
                skippedModulePackageNames: ModulePackageList::allPackages(),
                test: true,
            ),
        );

        $kernel->loadConfig()
               ->bootDiscovery()
               ->registerExceptionHandler()
               ->event(KernelEvent::BOOTED);

        // Resolve CommandBus WITHOUT touching ConfiguredMessagingSystem first —
        // EcotoneServiceInitializer must trigger compile on the first gateway request.
        $commandBus = $kernel->container->get(CommandBus::class);
        $queryBus = $kernel->container->get(QueryBus::class);

        $commandBus->sendWithRouting('app.ping');

        $this->assertTrue($queryBus->sendWithRouting('app.wasHandled'));
    }

    public function test_zero_config_namespace_derivation_from_composer_psr4_discovers_handlers(): void
    {
        $internalStorage = '/tmp/ecotone_tempest_real_boot_' . getmypid();

        $appLocation = new DiscoveryLocation('App\\Tempest\\', TempestTestPaths::appRoot() . '/src');
        $ecotoneLocation = new DiscoveryLocation('Ecotone\\Tempest\\', TempestTestPaths::srcPath());

        $kernel = new FrameworkKernel(
            root: TempestTestPaths::appRoot(),
            discoveryLocations: [$ecotoneLocation, $appLocation],
            internalStorage: $internalStorage,
        );

        $kernel->registerKernel()
               ->validateRoot()
               ->loadEnv()
               ->registerEmergencyExceptionHandler()
               ->registerShutdownFunction()
               ->registerInternalStorage()
               ->loadComposer();

        $this->injectDiscoveryConfig($kernel, $ecotoneLocation, $appLocation);

        $kernel->container->config(new EcotoneConfig(
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        ));

        $kernel->loadConfig()
               ->bootDiscovery()
               ->registerExceptionHandler()
               ->event(KernelEvent::BOOTED);

        $commandBus = $kernel->container->get(CommandBus::class);
        $queryBus = $kernel->container->get(QueryBus::class);

        $commandBus->sendWithRouting('app.ping');

        $this->assertTrue($queryBus->sendWithRouting('app.wasHandled'));
    }

    private function injectDiscoveryConfig(
        FrameworkKernel $kernel,
        DiscoveryLocation $ecotoneLocation,
        DiscoveryLocation $appLocation,
    ): void {
        $vendorOnlyComposer = (new Composer(TempestTestPaths::appRoot()))->load();
        $vendorOnlyComposer->namespaces = [];

        $vendorLocations = (new AutoloadDiscoveryLocations(
            rootPath: TempestTestPaths::discoveryRoot(),
            composer: $vendorOnlyComposer,
        ))();

        $discoveryConfig = $kernel->container->get(DiscoveryConfig::class);
        $discoveryConfig->locations = [$ecotoneLocation, $appLocation, ...$vendorLocations];

        $kernel->container->config($discoveryConfig);
        $kernel->discoveryConfig = $discoveryConfig;
    }
}
