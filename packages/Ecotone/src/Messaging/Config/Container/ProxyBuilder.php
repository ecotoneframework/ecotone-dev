<?php

namespace Ecotone\Messaging\Config\Container;

interface ProxyBuilder
{
    public function registerProxy(ContainerMessagingBuilder $builder): Reference;
}