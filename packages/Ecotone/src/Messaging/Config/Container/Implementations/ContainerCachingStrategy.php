<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerFactory;

interface ContainerCachingStrategy
{
    public function dump(\DI\ContainerBuilder $containerBuilder): ContainerFactory;
}