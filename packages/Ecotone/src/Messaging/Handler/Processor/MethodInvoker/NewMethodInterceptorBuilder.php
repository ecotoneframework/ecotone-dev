<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
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
        private InterfaceToCallReference $interceptorInterface,
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
        InterfaceToCallReference $interceptedInterfaceToCallReference,
        array $endpointAnnotations
    ): Definition|Reference
    {
        $interceptorInterface = $builder->getInterfaceToCall($this->interceptorInterface);
        $interceptedInterface = $builder->getInterfaceToCall($interceptedInterfaceToCallReference);
        $parameterConverters = MethodArgumentsFactory::createInterceptedInterfaceAnnotationMethodParameters(
            $interceptorInterface,
            $this->defaultParameterConverters,
            $endpointAnnotations,
            $interceptedInterface,
        );

        $methodCallProvider = new Definition(StaticMethodCallProvider::class, [
            new Reference($interceptorInterface->getInterfaceName()),
            $interceptorInterface->getMethodName(),
            $parameterConverters,
            $interceptorInterface->getInterfaceParametersNames(),
        ]);

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
}