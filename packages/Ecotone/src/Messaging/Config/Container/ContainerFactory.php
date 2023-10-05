<?php

namespace Ecotone\Messaging\Config\Container;

use Psr\Container\ContainerInterface;

interface ContainerFactory
{
    public function create(): ContainerInterface;
}