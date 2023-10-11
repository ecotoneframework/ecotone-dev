<?php

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;

interface CompilerPass
{
    public function process(ContainerMessagingBuilder $builder): void;
}