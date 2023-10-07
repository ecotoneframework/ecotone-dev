<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerHydrator;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;

class CachedContainerStrategy implements ContainerCachingStrategy
{
    public function __construct(private ServiceCacheConfiguration $cacheConfiguration)
    {
        if (!$this->cacheConfiguration->getPath()) {
            throw new \InvalidArgumentException("Cache path is not set");
        }
    }

    public function dump(\DI\ContainerBuilder $containerBuilder): ContainerHydrator
    {
        $containerClassName = 'EcotoneContainer';
        $containerBuilder->enableCompilation($this->cacheConfiguration->getPath(), $containerClassName);
        $containerBuilder->writeProxiesToFile(true, $this->cacheConfiguration->getPath());
        $containerBuilder->build();
        return new CachedContainerHydrator($containerClassName);
    }
}