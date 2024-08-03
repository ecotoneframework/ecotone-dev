<?php

namespace Ecotone\Messaging\Handler\Transformer;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\StaticMethodCallProvider;

class TransformerMessageProcessorBuilder extends InterceptedMessageProcessorBuilder
{
    public function __construct(
        private Definition|DefinedObject|Reference $transformerObjectDefinition,
        private InterfaceToCallReference $interfaceToCallReference,
        private array $methodParameterConverters = []
    )
    {
    }

    public function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition|Reference
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);
        $methodCall = StaticMethodCallProvider::getDefinition(
            $this->transformerObjectDefinition,
            $interfaceToCall,
            $this->methodParameterConverters,
        );
        return new Definition(TransformerMessageProcessor::class, [
            $methodCall,
            $interfaceToCall->getReturnType(),
        ]);
    }

    function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}