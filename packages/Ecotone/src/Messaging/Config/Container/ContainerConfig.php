<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Psr\Container\ContainerInterface;

class ContainerConfig
{
    public static function buildMessagingSystemInMemoryContainer(Configuration $configuration, ?ContainerInterface $externalContainer = null, array $configurationVariables = []): ConfiguredMessagingSystem
    {
        $container = InMemoryPSRContainer::createFromAssociativeArray($configurationVariables);
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addCompilerPass($configuration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new InMemoryContainerImplementation($container, $externalContainer));
        $containerBuilder->compile();
        return $container->get(ConfiguredMessagingSystem::class);
    }
}