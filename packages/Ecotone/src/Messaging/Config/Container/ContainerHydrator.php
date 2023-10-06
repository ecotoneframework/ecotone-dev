<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;

interface ContainerHydrator
{
    public function create(ServiceCacheConfiguration $serviceCacheConfiguration): ContainerInterface;
}