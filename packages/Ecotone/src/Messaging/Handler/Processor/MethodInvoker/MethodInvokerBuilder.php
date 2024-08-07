<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;

use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvocationProcessor;

use function is_string;

/**
 * licence Apache-2.0
 */
class MethodInvokerBuilder extends InterceptedMessageProcessorBuilder
{
    /**
     * @var AttributeDefinition[] $endpointAnnotations
     */
    private array $endpointAnnotations;
    private bool $shouldPassTroughMessageIfVoid = false;
    private bool $changeHeaders = false;

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

    public function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition|Reference
    {
        $interfaceToCall = $builder->getInterfaceToCall($this->interfaceToCallReference);

        if (is_string($this->reference)) {
            $reference = $interfaceToCall->isStaticallyCalled() ? $this->reference : new Reference($this->reference);
        } else {
            $reference = $this->reference;
        }

        $methodCallProvider = StaticMethodInvocationProvider::getDefinition(
            $reference,
            $interfaceToCall,
            $this->methodParametersConverterBuilders,
            $interfaceToCall,
            $this->endpointAnnotations,
        );
        if ($interceptorsConfiguration) {
            $methodCallProvider = $builder->interceptMethodCall($this->interfaceToCallReference, $this->endpointAnnotations, $methodCallProvider);
        }

        // @todo: this is only used for tests, in production it is always payload converter
        $messageConverter = match (true) {
            $this->shouldPassTroughMessageIfVoid && $interfaceToCall->getReturnType()->isVoid() => new Definition(PassthroughMessageConverter::class),
            $this->changeHeaders => new Definition(HeaderResultMessageConverter::class, [(string) $interfaceToCall]),
            default => new Definition(PayloadResultMessageConverter::class, [
                $interfaceToCall->getReturnType(),
            ])
        };

        return new Definition(MethodInvocationProcessor::class, [
            $methodCallProvider,
            $messageConverter,
        ]);
    }

    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return $this->interfaceToCallReference;
    }
}
