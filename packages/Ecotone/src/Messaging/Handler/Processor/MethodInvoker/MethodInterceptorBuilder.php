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

/**
 * @licence Apache-2.0
 */
class MethodInterceptorBuilder implements InterceptorWithPointCut
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

    /**
     * @param array<ParameterConverterBuilder> $defaultParameterConverters
     */
    public static function create(
        Reference|Definition|DefinedObject       $interceptorReference,
        InterfaceToCall $interceptorInterface,
        int             $precedence,
        string          $pointcut,
        bool            $changeHeaders = false,
        array           $defaultParameterConverters = [],
        string          $name = ''
    ): self {
        $pointcut = $pointcut ? Pointcut::createWith($pointcut) : Pointcut::initializeFrom($interceptorInterface, $defaultParameterConverters);
        $defaultName = $interceptorReference instanceof Reference ? $interceptorReference->getId() : $interceptorInterface->toString();
        return new self(
            $interceptorReference,
            InterfaceToCallReference::fromInstance($interceptorInterface),
            $defaultParameterConverters,
            $precedence,
            $pointcut,
            $name ?: $defaultName,
            $changeHeaders
        );
    }

    public function doesItCutWith(InterfaceToCall $interfaceToCall, iterable $endpointAnnotations): bool
    {
        return $this->pointcut->doesItCut($interfaceToCall, $endpointAnnotations);
    }

    /**
     * @param array<AttributeDefinition> $endpointAnnotations
     */
    public function compileForInterceptedInterface(
        MessagingContainerBuilder $builder,
        InterfaceToCallReference  $interceptedInterfaceToCallReference,
        array                     $endpointAnnotations = []
    ): Definition|Reference {
        $interceptorInterface = $builder->getInterfaceToCall($this->interceptorInterfaceReference);
        $interceptedInterface = $builder->getInterfaceToCall($interceptedInterfaceToCallReference);

        $methodCallProvider = StaticMethodInvoker::getDefinition(
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
