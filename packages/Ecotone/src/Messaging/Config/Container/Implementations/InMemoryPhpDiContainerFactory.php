<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use DI\ContainerBuilder;
use Ecotone\Messaging\Config\Container\ContainerFactory;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;

class InMemoryPhpDiContainerFactory implements ContainerFactory
{
    public function __construct(private ContainerBuilder $builder)
    {
    }

    public function create(ServiceCacheConfiguration $serviceCacheConfiguration): ContainerInterface
    {
        return $this->builder->build();
    }
}