<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Lite\LazyInMemoryContainer;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\ContainerDefinitionsHolder;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Psr\Container\ContainerInterface;

class ContainerConfig
{
    public static function buildMessagingSystemInMemoryContainer(
        Configuration $configuration,
        ?ContainerInterface $externalContainer = null,
        ?ConfigurationVariableService $configurationVariableService = null,
        ?ProxyFactory $proxyFactory = null,
    ): ConfiguredMessagingSystem
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addCompilerPass($configuration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->compile();
        $container = new LazyInMemoryContainer($containerBuilder->getDefinitions(), $externalContainer);
        $container->set(ConfigurationVariableService::REFERENCE_NAME, $configurationVariableService ?? InMemoryConfigurationVariableService::createEmpty());
        $container->set(ProxyFactory::class, $proxyFactory ?? new ProxyFactory(ServiceCacheConfiguration::noCache()));
        return $container->get(ConfiguredMessagingSystem::class);
    }

    public static function buildDefinitionHolder(
        Configuration $configuration,
    ): ContainerDefinitionsHolder
    {
        $definitionHolder = new ContainerDefinitionsHolder($configuration->getRegisteredConsoleCommands());
        $ecotoneBuilder = new ContainerBuilder();
        $ecotoneBuilder->addCompilerPass($configuration);
        $ecotoneBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $ecotoneBuilder->addCompilerPass($definitionHolder);
        $ecotoneBuilder->compile();
        return $definitionHolder;
    }
}
