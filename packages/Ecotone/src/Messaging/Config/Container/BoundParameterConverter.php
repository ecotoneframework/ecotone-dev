<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InterfaceToCall;

class BoundParameterConverter implements CompilableBuilder
{
    public function __construct(
        private CompilableParameterConverterBuilder $parameterConverterBuilder,
        private InterfaceToCall $interfaceToCall,
    ) {
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return $this->parameterConverterBuilder->compile($builder, $this->interfaceToCall);
    }
}
