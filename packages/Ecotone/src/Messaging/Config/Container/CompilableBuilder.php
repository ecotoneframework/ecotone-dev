<?php

namespace Ecotone\Messaging\Config\Container;

interface CompilableBuilder
{
    public function compile(ContainerMessagingBuilder $builder): Definition|Reference;
}
