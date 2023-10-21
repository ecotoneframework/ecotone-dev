<?php

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\SymfonyBundle\DepedencyInjection\SymfonyContainerAdapter;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Lite\ContainerImplementationTestCase;

/**
 * @internal
 */
class SymfonyContainerAdapterTest extends ContainerImplementationTestCase
{
    protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $builder->addCompilerPass(new SymfonyContainerAdapter($container));
        $builder->compile();

        return $container;
    }
}