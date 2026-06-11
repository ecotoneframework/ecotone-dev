<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\ValidityCheckPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;

/**
 * licence Apache-2.0
 * @deprecated Ecotone 2.0 To be removed in Ecotone 2.0, use EcotoneLite instead
 */
class EcotoneLiteApplication
{
    public static function bootstrap(
        array $objectsToRegister = [],
        array $configurationVariables = [],
        ?ServiceConfiguration $serviceConfiguration = null,
        bool $cacheConfiguration = false,
        ?string $pathToRootCatalog = null,
        array $classesToRegister = [],
        ?string $licenseKey = null
    ): ConfiguredMessagingSystem {
        $pathToRootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../../';

        if (! $serviceConfiguration) {
            $serviceConfiguration = ServiceConfiguration::createWithDefaults();
        }

        if ($licenseKey !== null) {
            $serviceConfiguration = $serviceConfiguration->withLicenceKey($licenseKey);
        }

        if ($serviceConfiguration->isLoadingCatalogEnabled() && ! $serviceConfiguration->getLoadedCatalog()) {
            $serviceConfiguration = $serviceConfiguration
                ->withLoadCatalog('src');
        }

        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $serviceConfiguration->getCacheDirectoryPath() . DIRECTORY_SEPARATOR . 'ecotone',
            $cacheConfiguration
        );

        $externalContainer = new AutowiringContainer(InMemoryPSRContainer::createFromAssociativeArray(array_merge($classesToRegister, $objectsToRegister)));
        $configurationVariableService = InMemoryConfigurationVariableService::create($configurationVariables);

        $container = EcotoneSymfonyContainerFactory::bootstrap(
            $serviceCacheConfiguration,
            $configurationVariableService,
            $externalContainer,
            function () use ($pathToRootCatalog, $configurationVariableService, $serviceConfiguration, $externalContainer) {
                /** @var MessagingSystemConfiguration $messagingConfiguration */
                $messagingConfiguration = MessagingSystemConfiguration::prepare(
                    $pathToRootCatalog,
                    $configurationVariableService,
                    $serviceConfiguration,
                );
                $messagingConfiguration->withExternalContainer($externalContainer);

                return $messagingConfiguration;
            },
        );

        $externalContainer->setEcotoneContainer($container);

        return $container->get(ConfiguredMessagingSystem::class);
    }

    /**
     * @deprecated Use EcotoneLiteApplication::bootstrap instead
     *
     * @TODO drop in Ecotone 2.0
     */
    public static function boostrap(array $objectsToRegister = [], array $configurationVariables = [], ?ServiceConfiguration $serviceConfiguration = null, bool $cacheConfiguration = false, ?string $pathToRootCatalog = null): ConfiguredMessagingSystem
    {
        return self::bootstrap($objectsToRegister, $configurationVariables, $serviceConfiguration, $cacheConfiguration, $pathToRootCatalog);
    }
}
