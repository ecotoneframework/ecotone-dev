<?php

namespace Ecotone\Messaging\Handler\Transformer;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvocationProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\StaticMethodInvoker;

/**
 * @licence Apache-2.0
 */
class TransformerMessageProcessorBuilder implements InterceptedMessageProcessorBuilder
{
    public function __construct(
        private Definition|DefinedObject|Reference $transformerObjectDefinition,
        private InterfaceToCallReference $interfaceToCallReference,
        private array $methodParameterConverters = []
    ) {
    }

    public function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition|Reference
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);
        $methodCall = StaticMethodInvoker::getDefinition(
            $this->transformerObjectDefinition,
            $interfaceToCall,
            $this->methodParameterConverters,
        );
        return new Definition(MethodInvocationProcessor::class, [
            $methodCall,
            new Definition(TransformerResultToMessageConverter::class, [$interfaceToCall->getReturnType()]),
        ]);
    }

    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}
