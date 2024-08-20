<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;

use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvokerProcessor;

use function is_string;

/**
 * licence Apache-2.0
 */
class MethodInvokerBuilder implements InterceptedMessageProcessorBuilder
{
    /**
     * @var AttributeDefinition[] $endpointAnnotations
     */
    private array $endpointAnnotations;
    private bool $shouldPassTroughMessageIfVoid = false;
    private bool $changeHeaders = false;

    private ?CompilableBuilder $resultToMessageConverter = null;
    private bool $isInterceptionEnabled = true;

    /**
     * @param array<ParameterConverterBuilder> $methodParametersConverterBuilders
     * @param array<AttributeDefinition> $endpointAnnotations
     */
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

    public function withPassTroughMessageIfVoid(bool $shouldPassTroughMessageIfVoid): self
    {
        $this->shouldPassTroughMessageIfVoid = $shouldPassTroughMessageIfVoid;

        return $this;
    }

    public function withChangeHeaders(bool $changeHeaders): self
    {
        $this->changeHeaders = $changeHeaders;

        return $this;
    }

    public function withResultToMessageConverter(CompilableBuilder $compilableBuilder): self
    {
        $this->resultToMessageConverter = $compilableBuilder;

        return $this;
    }

    public function withInterceptionDisabled(): self
    {
        $this->isInterceptionEnabled = false;

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition|Reference
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);

        $messageConverter = match (true) {
            $this->resultToMessageConverter !== null => $this->resultToMessageConverter->compile($builder),
            $this->shouldPassTroughMessageIfVoid && $interfaceToCall->getReturnType()->isVoid() => new Definition(PassthroughMessageConverter::class),
            $this->changeHeaders => new Definition(HeaderResultMessageConverter::class, [(string) $interfaceToCall]),
            default => new Definition(PayloadResultMessageConverter::class, [
                $interfaceToCall->getReturnType(),
            ])
        };

        return new Definition(MethodInvokerProcessor::class, [
            $this->compileWithoutProcessor($builder),
            $messageConverter,
        ]);
    }

    public function compileWithoutProcessor(MessagingContainerBuilder $builder): Definition
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);

        if ($this->reference instanceof Definition
            && is_a($this->reference->getClassName(), MethodInvokerObjectResolver::class, true)) {
            $objectToInvokeOnResolver = $this->reference;
        } else {
            $objectToInvokeOnResolver = new Definition(MethodInvokerStaticObjectResolver::class, [
                is_string($this->reference) && !$interfaceToCall->isStaticallyCalled() ? Reference::to($this->reference) : $this->reference
            ]);
        }

        $parameterConvertersBuilders = MethodArgumentsFactory::createDefaultMethodParameters($interfaceToCall, $this->methodParametersConverterBuilders);
        $parameterConverters = array_map(
            fn (ParameterConverterBuilder $parameterConverterBuilder) => $parameterConverterBuilder->compile($interfaceToCall),
            $parameterConvertersBuilders
        );

        $aroundInterceptors = [];
        if ($this->isInterceptionEnabled) {
            $interceptorsConfiguration = $builder->getRelatedInterceptors(
                $this->interfaceToCallReference,
                $this->endpointAnnotations,
            );
            foreach ($interceptorsConfiguration->getAroundInterceptors() as $aroundInterceptor) {
                $aroundInterceptors[] = $aroundInterceptor->compileForInterceptedInterface($builder, $this->interfaceToCallReference, $this->endpointAnnotations);
            }
        }
        return new Definition(MethodInvoker::class, [
            $objectToInvokeOnResolver,
            $interfaceToCall->getMethodName(),
            $parameterConverters,
            $interfaceToCall->getInterfaceParametersNames(),
            $aroundInterceptors,
        ]);
    }

    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}
