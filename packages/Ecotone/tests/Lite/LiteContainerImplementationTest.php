<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Psr\Container\ContainerInterface;

class LiteContainerImplementationTest extends ContainerImplementationTestCase
{
    protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        $container = InMemoryPSRContainer::createEmpty();
        $builder->addCompilerPass(new InMemoryContainerImplementation($container, $externalContainer));
        $builder->compile();
        return $container;
    }
}