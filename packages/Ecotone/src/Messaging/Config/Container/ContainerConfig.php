<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Psr\Container\ContainerInterface;

class ContainerConfig
{
    public static function buildMessagingSystemInMemoryContainer(Configuration $configuration, ?ContainerInterface $externalContainer = null, ?ConfigurationVariableService $configurationVariableService = null, ?ProxyFactory $proxyFactory = null): ConfiguredMessagingSystem
    {
        $container = InMemoryPSRContainer::createFromAssociativeArray([
            ConfigurationVariableService::REFERENCE_NAME => $configurationVariableService ?? InMemoryConfigurationVariableService::createEmpty(),
            ProxyFactory::class => $proxyFactory ?? new ProxyFactory(''),
        ]);
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addCompilerPass($configuration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new InMemoryContainerImplementation($container, $externalContainer));
        $containerBuilder->compile();
        return $container->get(ConfiguredMessagingSystem::class);
    }
}