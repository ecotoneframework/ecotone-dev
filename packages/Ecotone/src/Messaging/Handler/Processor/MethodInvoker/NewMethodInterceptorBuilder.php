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
use Ecotone\Messaging\Handler\Processor\MethodInvocationProcessor;

class NewMethodInterceptorBuilder
{
    /**
     * @param array<ParameterConverterBuilder> $defaultParameterConverters
     */
    public function __construct(
        private Reference|Definition|DefinedObject $interceptorDefinition,
        private InterfaceToCallReference           $interceptorInterfaceReference,
        private array                              $defaultParameterConverters,
        private int                                $precedence,
        private Pointcut                           $pointcut,
        private ?string                            $name,
        private bool                               $changeHeaders = false,
    ) {
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
    ): Definition|Reference {
        $interceptorInterface = $builder->getInterfaceToCall($this->interceptorInterfaceReference);
        $interceptedInterface = $interceptedInterfaceToCallReference ? $builder->getInterfaceToCall($interceptedInterfaceToCallReference) : null;

        $methodCallProvider = StaticMethodCallProvider::getDefinition(
            $this->interceptorDefinition,
            $interceptorInterface,
            $this->defaultParameterConverters,
            $interceptedInterface,
            $endpointAnnotations
        );

        $messageConverter = match (true) {
            $interceptorInterface->hasReturnTypeVoid() => new Definition(PassthroughMessageConverter::class),
            $this->changeHeaders => new Definition(HeaderResultMessageConverter::class, [(string) $interceptedInterface]),
            default => new Definition(PayloadResultMessageConverter::class, [
                $interceptorInterface->getReturnType(),
            ])
        };

        return new Definition(MethodInvocationProcessor::class, [
            $methodCallProvider,
            $messageConverter,
        ]);
    }

    public function getPrecedence(): int
    {
        return $this->precedence;
    }

    public function hasName(string $name): bool
    {
        return $this->name === $name;
    }

    public function __toString(): string
    {
        return "{$this->name}.{$this->interceptorInterfaceReference}";
    }
}
