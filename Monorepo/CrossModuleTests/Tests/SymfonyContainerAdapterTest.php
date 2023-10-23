<?php

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Lite\ContainerImplementationTestCase;

/**
 * @internal
 */
class SymfonyContainerAdapterTest extends ContainerImplementationTestCase
{
    protected static ?SimpleSymfonyKernel $bootedKernel = null;

    protected function setUp(): void
    {
        if (self::$bootedKernel) {
            self::$bootedKernel->shutdown();
            self::$bootedKernel = null;
        }
    }

    protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        self::$bootedKernel = new SimpleSymfonyKernel($builder);
        self::$bootedKernel->boot();

        return self::$bootedKernel->getContainer();
    }
}