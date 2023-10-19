<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InterfaceToCall;

interface CompilableParameterConverterBuilder
{
    public function compile(ContainerMessagingBuilder $builder, InterfaceToCall $interfaceToCall): Definition|Reference;
}
