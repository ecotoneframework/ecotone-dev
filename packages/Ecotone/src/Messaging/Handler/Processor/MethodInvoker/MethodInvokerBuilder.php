<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvocationProcessor;
use function is_string;

/**
 * licence Apache-2.0
 */
class MethodInvokerBuilder extends InterceptedMessageProcessorBuilder
{
    private function __construct(
        private object|string $reference,
        private InterfaceToCallReference $interfaceToCallReference,
        private array $methodParametersConverterBuilders = [],
        array $endpointAnnotations = []
    ) {
        $this->endpointAnnotations = $endpointAnnotations;
    }

    public static function create(object|string $definition, InterfaceToCallReference $interfaceToCallReference, array $methodParametersConverterBuilders = [], array $endpointAnnotations = []): self
    {
        return new self($definition, $interfaceToCallReference, $methodParametersConverterBuilders, $endpointAnnotations);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);

        if (is_string($this->reference)) {
            $reference = $interfaceToCall->isStaticallyCalled() ? $this->reference : new Reference($this->reference);
        } else {
            $reference = $this->reference;
        }

        $methodCallProvider = StaticMethodCallProvider::getDefinition(
            $reference,
            $interfaceToCall,
            $this->methodParametersConverterBuilders,
            $interfaceToCall,
            $this->getEndpointAnnotations(),
        );

        return new Definition(MethodInvocationProcessor::class, [
            $methodCallProvider,
            $interfaceToCall->getReturnType(),
        ]);

//        return new Definition(MethodInvoker::class, [
//            $reference,
//            $interfaceToCall->getMethodName(),
//            $compiledMethodParameterConverters,
//            $interfaceToCall->getInterfaceParametersNames(),
//            true,
//        ]);
    }

    function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}
