<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Psr\Container\ContainerInterface;

final class ProxyGenerator
{
    public static function createFor(string $referenceName, ContainerInterface $container, string $interface, string $cacheDirectoryPath): object
    {
        $proxyFactory = ProxyFactory::createWithCache($cacheDirectoryPath);

        return $proxyFactory->createProxyClassWithAdapter(
            $interface,
            new EcotoneRemoteAdapter($container, $referenceName)
        );
    }
}
