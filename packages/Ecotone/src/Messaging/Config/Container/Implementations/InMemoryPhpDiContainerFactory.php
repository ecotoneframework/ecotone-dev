<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use DI\ContainerBuilder;
use Ecotone\Messaging\Config\Container\ContainerFactory;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Psr\Container\ContainerInterface;

class InMemoryPhpDiContainerFactory implements ContainerFactory
{
    public function __construct(private ContainerMessagingBuilder $builder)
    {
    }

    public function create(): ContainerInterface
    {
        $container = new ContainerBuilder();
        $this->builder->process(new PhpDiContainerBuilder($container));
        return $container->build();
    }
}