<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerHydrator;

class InMemoryContainerStrategy implements ContainerCachingStrategy
{
    public function dump(\DI\ContainerBuilder $containerBuilder): ContainerHydrator
    {
        return new InMemoryPhpDiContainerHydrator($containerBuilder);
    }
}
