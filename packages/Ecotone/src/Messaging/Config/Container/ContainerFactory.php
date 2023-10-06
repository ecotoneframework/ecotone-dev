<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;

interface ContainerFactory
{
    public function create(ServiceCacheConfiguration $serviceCacheConfiguration): ContainerInterface;
}