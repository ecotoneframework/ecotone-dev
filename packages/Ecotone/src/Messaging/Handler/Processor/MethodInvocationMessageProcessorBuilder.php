<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

class MethodInvocationMessageProcessorBuilder extends InterceptedMessageProcessorBuilder
{
    public function __construct(
        private Reference|Definition $object,
        private string $methodName,
        private array $defaultParameterConverters = [],
    )
    {
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        // TODO: Implement compile() method.
    }

    function getInterceptedInterface(): InterfaceToCallReference
    {
        // TODO: Implement getInterceptedInterface() method.
    }
}