<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use Ecotone\AnnotationFinder\FileSystem\FileSystemAnnotationFinder;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\InMemoryReferenceTypeFromNameResolver;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModuleClassList;
use Ecotone\Messaging\Config\ProxyGenerator;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Config\StubConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Psr\Container\ContainerInterface;

final class EcotoneMinimal
{
    public const CONFIGURED_MESSAGING_SYSTEM = ConfiguredMessagingSystem::class;

    /**
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @param ContainerInterface|object[] $containerOrAvailableServices
     */
    public static function boostrapAllModules(
        array                       $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration       $configuration = null,
        array                       $configurationVariables = [],
    ): ConfiguredMessagingSystem {
        if (! $configuration) {
            $configuration = ServiceConfiguration::createWithDefaults();
        }

        return self::prepareConfiguration(ModuleClassList::allModules(), $containerOrAvailableServices, $configuration, $classesToResolve, $configurationVariables);
    }

    /**
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @param ContainerInterface|object[] $containerOrAvailableServices
     */
    public static function boostrapWithMessageHandlers(
        array                       $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration       $configuration = null,
        array                       $configurationVariables = [],
        array                       $enableModules = [],
        ?string $pathToRootCatalog = null
    ): ConfiguredMessagingSystem {
        if (! $configuration) {
            $configuration = ServiceConfiguration::createWithDefaults();
        }

        return self::prepareConfiguration(array_merge(ModuleClassList::CORE_MODULES, $enableModules), $containerOrAvailableServices, $configuration, $classesToResolve, $configurationVariables, $pathToRootCatalog);
    }

    /**
     * @param array $modulesToEnable
     * @param GatewayAwareContainer|object[] $containerOrAvailableServices
     * @param ServiceConfiguration $configuration
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @return ConfiguredMessagingSystem
     */
    private static function prepareConfiguration(array $modulesToEnable, ContainerInterface|array $containerOrAvailableServices, ServiceConfiguration $configuration, array $classesToResolve, array $configurationVariables, ?string $pathToRootCatalog): ConfiguredMessagingSystem
    {
        $pathToRootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../../';

        $container = $containerOrAvailableServices instanceof ContainerInterface ? $containerOrAvailableServices : InMemoryPSRContainer::createFromAssociativeArray($containerOrAvailableServices);

        $modulesToEnable = array_unique($modulesToEnable);
        $configuration = $configuration->withSkippedModulePackageNames(array_diff(ModuleClassList::allModules(), $modulesToEnable));

        $messagingConfiguration = MessagingSystemConfiguration::prepare(
            $pathToRootCatalog,
            InMemoryReferenceTypeFromNameResolver::createFromReferenceSearchService(new PsrContainerReferenceSearchService($container)),
            InMemoryConfigurationVariableService::create($configurationVariables),
            $configuration,
            false,
            $classesToResolve
        );

        $messagingSystem = $messagingConfiguration->buildMessagingSystemFromConfiguration(
            new PsrContainerReferenceSearchService($container, ['logger' => new EchoLogger(), ConfiguredMessagingSystem::class => new StubConfiguredMessagingSystem()])
        );

        $container->set(self::CONFIGURED_MESSAGING_SYSTEM, $messagingSystem);

        return $messagingSystem;
    }
}
