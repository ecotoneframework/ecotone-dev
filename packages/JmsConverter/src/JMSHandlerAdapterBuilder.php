<?php

namespace Ecotone\JMSConverter;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Type;

/**
 * licence Apache-2.0
 */
class JMSHandlerAdapterBuilder implements CompilableBuilder
{
    public function __construct(
        private Type $fromType,
        private Type $toType,
        private Definition|Reference|string $objectToCallOn,
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
