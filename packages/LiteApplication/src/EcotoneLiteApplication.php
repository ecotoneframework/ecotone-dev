<?php

declare(strict_types=1);

namespace Ecotone\Lite;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\LazyConfiguredMessagingSystem;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ProxyGenerator;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\InMemoryConfigurationVariableService;

class EcotoneLiteApplication
{
    public const CONFIGURED_MESSAGING_SYSTEM = ConfiguredMessagingSystem::class;

    public static function boostrap(array $objectsToRegister = [], array $configurationVariables = [], ?ServiceConfiguration $configuration = null, bool $cacheConfiguration = false, ?string $pathToRootCatalog = null): ConfiguredMessagingSystem
    {
        if (! $configuration) {
            $configuration = ServiceConfiguration::createWithDefaults();
        }

        if ($configuration->isLoadingCatalogEnabled() && ! $configuration->getLoadedCatalog()) {
            $configuration = $configuration
                                ->withLoadCatalog('src');
        }

//        moving out of vendor catalog
        $rootCatalog = $pathToRootCatalog ?: __DIR__ . '/../../../';

        $container = new LiteDIContainer($configuration, $cacheConfiguration, $configurationVariables);

        foreach ($objectsToRegister as $referenceName => $object) {
            $container->set($referenceName, $object);
        }

        $messagingConfiguration       = MessagingSystemConfiguration::prepare(
            $rootCatalog,
            $container,
            InMemoryConfigurationVariableService::create($configurationVariables),
            $configuration,
            $cacheConfiguration
        );

        foreach ($messagingConfiguration->getRegisteredGateways() as $gatewayProxyBuilder) {
            $container->set($gatewayProxyBuilder->getReferenceName(), ProxyGenerator::createFor(
                $gatewayProxyBuilder->getReferenceName(),
                $container,
                $gatewayProxyBuilder->getInterfaceName(),
                $cacheConfiguration ? $configuration->getCacheDirectoryPath() : sys_get_temp_dir()
            ));
        }

        $messagingSystem = $messagingConfiguration->buildMessagingSystemFromConfiguration(
            new \Ecotone\Lite\PsrContainerReferenceSearchService($container, ['logger' => new EchoLogger(), ConfiguredMessagingSystem::class => new LazyConfiguredMessagingSystem($container)])
        );

        $container->set(self::CONFIGURED_MESSAGING_SYSTEM, $messagingSystem);

        return $messagingSystem;
    }
}
