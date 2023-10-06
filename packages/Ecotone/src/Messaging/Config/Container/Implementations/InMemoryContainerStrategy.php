<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerFactory;

class InMemoryContainerStrategy implements ContainerCachingStrategy
{
    public function dump(\DI\ContainerBuilder $containerBuilder): ContainerFactory
    {
        return new InMemoryPhpDiContainerFactory($containerBuilder);
    }
}