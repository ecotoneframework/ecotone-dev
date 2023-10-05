<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerFactory;
use Psr\Container\ContainerInterface;

class CachedContainerFactory implements ContainerFactory
{
    public function __construct(private string $containerClassName, private string $cachePath)
    {
    }

    public function create(): ContainerInterface
    {
        if (!\class_exists($this->containerClassName)) {
            require_once $this->cachePath;
        }

        return new $this->containerClassName();
    }
}