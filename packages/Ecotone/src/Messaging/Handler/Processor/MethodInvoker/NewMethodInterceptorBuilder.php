<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\ChangeHeadersMethodInvocationProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvocationProcessor;
use Ecotone\Messaging\Handler\Processor\PasstroughMethodInvocationProcessor;

class NewMethodInterceptorBuilder
{
    /**
     * @param array<ParameterConverterBuilder> $defaultParameterConverters
     */
    public function __construct(
        private Reference|Definition|DefinedObject $interceptorDefinition,
        private string $methodName,
        private array $defaultParameterConverters,
        private int $precedence,
        private Pointcut $pointcut,
        private bool $changeHeaders = false,
    )
    {
    }

    public function doesItCutWith(InterfaceToCall $interfaceToCall, array $endpointAnnotations): bool
    {
        return $this->pointcut->doesItCut($interfaceToCall, $endpointAnnotations);
    }

    /**
     * @param array<AttributeDefinition> $endpointAnnotations
     */
    public function compileForInterceptedInterface(
        MessagingContainerBuilder $builder,
        ?InterfaceToCallReference $interceptedInterfaceToCallReference = null,
        array $endpointAnnotations = []
    ): Definition|Reference
    {
        $interceptorInterface = $builder->getInterfaceToCallForObject($this->interceptorDefinition, $this->methodName);
        $interceptedInterface = $interceptedInterfaceToCallReference ? $builder->getInterfaceToCall($interceptedInterfaceToCallReference) : null;

        $methodCallProvider = StaticMethodCallProvider::getDefinition(
            $this->interceptorDefinition,
            $interceptorInterface,
            $this->defaultParameterConverters,
            $interceptedInterface,
            $endpointAnnotations
        );

        return match (true) {
            $interceptorInterface->hasReturnTypeVoid() => new Definition(PasstroughMethodInvocationProcessor::class, [$methodCallProvider]),
            $this->changeHeaders => new Definition(ChangeHeadersMethodInvocationProcessor::class, [$methodCallProvider, (string) $interceptorInterface]),
            default => new Definition(MethodInvocationProcessor::class, [$methodCallProvider, $interceptorInterface->getReturnType()]),
        };
    }

    public function getPrecedence(): int
    {
        return $this->precedence;
    }

    public function __toString(): string
    {
        return $this->interceptorDefinition . "::" . $this->methodName;
    }
}