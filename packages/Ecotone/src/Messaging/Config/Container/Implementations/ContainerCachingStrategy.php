<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerHydrator;

interface ContainerCachingStrategy
{
    public function dump(\DI\ContainerBuilder $containerBuilder): ContainerHydrator;
}