<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerHydrator;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Psr\Container\ContainerInterface;

class CachedContainerHydrator implements ContainerHydrator
{
    public function __construct(private string $containerClassName)
    {
    }

    public function create(ServiceCacheConfiguration $serviceCacheConfiguration): ContainerInterface
    {
        if (!\class_exists($this->containerClassName)) {
            require_once $serviceCacheConfiguration->getPath(). DIRECTORY_SEPARATOR . $this->containerClassName . ".php";
        }

        return new $this->containerClassName();
    }
}