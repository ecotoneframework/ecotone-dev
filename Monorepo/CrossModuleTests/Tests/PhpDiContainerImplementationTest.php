<?php

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Lite\PhpDiContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Lite\ContainerImplementationTestCase;

/**
 * @internal
 */
class PhpDiContainerImplementationTest extends ContainerImplementationTestCase
{
    protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        $container = new \DI\ContainerBuilder();
        $containerClass = 'EcotonePhpDi_'.uniqid();
        $cacheDirectory = sys_get_temp_dir();
        $container->enableCompilation($cacheDirectory, $containerClass);
        $builder->addCompilerPass(new PhpDiContainerImplementation($container));
        $builder->compile();
        $container->build();

        require_once $cacheDirectory . '/' . $containerClass . '.php';
        return new $containerClass();
    }
}
