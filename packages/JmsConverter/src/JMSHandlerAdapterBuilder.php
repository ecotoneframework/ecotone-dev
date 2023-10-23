<?php

namespace Ecotone\JMSConverter;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\TypeDescriptor;

class JMSHandlerAdapterBuilder implements CompilableBuilder
{
    public function __construct(
        private TypeDescriptor $fromType,
        private TypeDescriptor $toType,
        private Definition|Reference $objectToCallOn,
        private string $methodName,
    ) {
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(JMSHandlerAdapter::class, [
            $this->fromType,
            $this->toType,
            $this->objectToCallOn,
            $this->methodName,
        ]);
    }
}
