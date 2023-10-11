<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\LiteContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Psr\Container\ContainerInterface;

class LiteContainerImplementationTest extends ContainerImplementationTestCase
{
    protected static function getContainerFrom(ContainerMessagingBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        $container = InMemoryPSRContainer::createEmpty();
        $builder->addCompilerPass(new LiteContainerImplementation($container, $externalContainer));
        $builder->compile();
        return $container;
    }
}