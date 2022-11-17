<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test;

use Ecotone\AnnotationFinder\FileSystem\FileSystemAnnotationFinder;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Lite\GatewayAwareContainer;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\PsrContainerReferenceSearchService;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\InMemoryReferenceTypeFromNameResolver;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModuleClassList;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ProxyGenerator;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Config\StubConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Ecotone\Messaging\Support\Assert;
use Psr\Container\ContainerInterface;

final class EcotoneTestSupport
{
    public const CONFIGURED_MESSAGING_SYSTEM = ConfiguredMessagingSystem::class;

    /**
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @param ContainerInterface|object[] $containerOrAvailableServices
     */
    public static function boostrapAllModules(
        array                    $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration    $configuration = null,
        array                    $configurationVariables = [],
        ?string                  $pathToRootCatalog = null
    ): ConfiguredMessagingSystemWithTestSupport
    {
        return self::prepareConfiguration(ModulePackageList::allPackages(), $containerOrAvailableServices, $configuration, $classesToResolve, $configurationVariables, $pathToRootCatalog);
    }

    /**
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @param ContainerInterface|object[] $containerOrAvailableServices
     */
    public static function boostrapWithMessageHandlers(
        array                    $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration    $configuration = null,
        array                    $configurationVariables = [],
        array                    $enableModulePackages = [],
        ?string                  $pathToRootCatalog = null
    ): ConfiguredMessagingSystemWithTestSupport
    {
        return self::prepareConfiguration(array_merge([ModulePackageList::CORE_PACKAGE], $enableModulePackages), $containerOrAvailableServices, $configuration, $classesToResolve, $configurationVariables, $pathToRootCatalog);
    }

    /**
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     * @param ContainerInterface|object[] $containerOrAvailableServices
     */
    public static function boostrapWithEventSourcing(
        array                    $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration    $configuration = null,
        array                    $configurationVariables = [],
        array                    $enableModulePackages = [],
        ?string                  $pathToRootCatalog = null
    ): ConfiguredMessagingSystemWithTestSupport
    {
        return self::prepareConfiguration(array_merge([ModulePackageList::CORE_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE], $enableModulePackages), $containerOrAvailableServices, $configuration, $classesToResolve, $configurationVariables, $pathToRootCatalog);
    }

    /**
     * @param string[] $packagesToEnable
     * @param GatewayAwareContainer|object[] $containerOrAvailableServices
     * @param string[] $classesToResolve
     * @param array<string,string> $configurationVariables
     */
    private static function prepareConfiguration(array $packagesToEnable, ContainerInterface|array $containerOrAvailableServices, ?ServiceConfiguration $configuration, array $classesToResolve, array $configurationVariables, ?string $pathToRootCatalog): ConfiguredMessagingSystemWithTestSupport
    {
        $pathToRootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../../';
        if (is_null($configuration)) {
            $configuration = ServiceConfiguration::createWithDefaults();
        }
        Assert::assertEmptyArray($configuration->getSkippedModulesPackages(), "Do not set skipped module packages for Test Support. Make use of enableModulePackages configuration.");

        $container = $containerOrAvailableServices instanceof ContainerInterface ? $containerOrAvailableServices : InMemoryPSRContainer::createFromAssociativeArray($containerOrAvailableServices);
        $configuration = $configuration->withSkippedModulePackageNames(array_diff(ModulePackageList::allPackages(), $packagesToEnable));

        $messagingConfiguration = MessagingSystemConfiguration::prepare(
            $pathToRootCatalog,
            InMemoryReferenceTypeFromNameResolver::createFromReferenceSearchService(new PsrContainerReferenceSearchService($container)),
            InMemoryConfigurationVariableService::create($configurationVariables),
            $configuration,
            false,
            $classesToResolve,
            true
        );

        $messagingSystem = $messagingConfiguration->buildMessagingSystemFromConfiguration(
            new PsrContainerReferenceSearchService($container, ['logger' => new EchoLogger(), ConfiguredMessagingSystem::class => new StubConfiguredMessagingSystem()])
        );

        $container->set(self::CONFIGURED_MESSAGING_SYSTEM, $messagingSystem);

        return new ConfiguredMessagingSystemWithTestSupport($messagingSystem);
    }
}