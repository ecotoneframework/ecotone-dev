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

    public static function buildForComponentTesting(CompilableBuilder $componentBuilder) {
        $container = InMemoryPSRContainer::createEmpty();
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addCompilerPass(new InMemoryContainerImplementation($container));
        $messagingBuilder = new ContainerMessagingBuilder($containerBuilder);
        $reference = $componentBuilder->compile($messagingBuilder);

        if ($reference instanceof Reference) {
            return $container->get($reference->getId());
        } else {
            throw new \InvalidArgumentException("Reference must be returned");
        }
    }
}