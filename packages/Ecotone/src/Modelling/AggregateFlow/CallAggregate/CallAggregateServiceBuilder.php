<?php

namespace Ecotone\Modelling\AggregateFlow\CallAggregate;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodArgumentsFactory;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\AggregateMethodCallProvider;
use Ecotone\Modelling\Attribute\AggregateVersion;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingSaga;
use Ecotone\Modelling\WithAggregateVersioning;

/**
 * licence Apache-2.0
 */
class CallAggregateServiceBuilder extends InterceptedMessageProcessorBuilder
{
    private InterfaceToCall $interfaceToCall;
    /**
     * @var ParameterConverterBuilder[]
     */
    private array $methodParameterConverterBuilders = [];
    /**
     * @var bool
     */
    private bool $isCommandHandler;
    private ?string $aggregateVersionProperty;
    private bool $isEventSourced = false;

    private function __construct(ClassDefinition $aggregateClassDefinition, string $methodName, bool $isCommandHandler, InterfaceToCallRegistry $interfaceToCallRegistry)
    {
        $this->isCommandHandler = $isCommandHandler;

        $this->initialize($aggregateClassDefinition, $methodName, $interfaceToCallRegistry);
    }

    private function initialize(ClassDefinition $aggregateClassDefinition, string $methodName, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $interfaceToCall = $interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $methodName);

        $eventSourcedAggregateAnnotation = TypeDescriptor::create(EventSourcingAggregate::class);
        $eventSourcedSagaAnnotation = TypeDescriptor::create(EventSourcingSaga::class);
        if ($interfaceToCall->hasClassAnnotation($eventSourcedAggregateAnnotation) || $interfaceToCall->hasClassAnnotation($eventSourcedSagaAnnotation)) {
            $this->isEventSourced = true;
        }

        $aggregateVersionPropertyName = null;
        $versionAnnotation             = TypeDescriptor::create(AggregateVersion::class);
        foreach ($aggregateClassDefinition->getProperties() as $property) {
            if ($property->hasAnnotation($versionAnnotation)) {
                $aggregateVersionPropertyName = $property->getName();
                // TODO: should throw exception if more than one version property
            }
        }
        $this->aggregateVersionProperty             = $aggregateVersionPropertyName;

        if ($this->isEventSourced) {
            Assert::isTrue((bool)$this->aggregateVersionProperty, "{$interfaceToCall->getInterfaceName()} is event sourced aggregate. Event Sourced aggregates are required to define version property. Make use of " . WithAggregateVersioning::class . ' or implement your own.');
        }

        $this->interfaceToCall = $interfaceToCall;
        $isFactoryMethod = $this->interfaceToCall->isFactoryMethod();
        if (! $this->isEventSourced && $isFactoryMethod) {
            Assert::isTrue($this->interfaceToCall->getReturnType()->isClassNotInterface(), "Factory method {$this->interfaceToCall} for standard aggregate should return object. Did you wanted to register Event Sourced Aggregate?");
        }
    }

    public static function create(ClassDefinition $aggregateClassDefinition, string $methodName, bool $isCommandHandler, InterfaceToCallRegistry $interfaceToCallRegistry): self
    {
        return new self($aggregateClassDefinition, $methodName, $isCommandHandler, $interfaceToCallRegistry);
    }

    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverterBuilders;
    }

    /**
     * @inheritDoc
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);

        $this->methodParameterConverterBuilders = $methodParameterConverterBuilders;

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder, ?MethodInterceptorsConfiguration $interceptorsConfiguration = null): Definition
    {
        // TODO: code duplication with ServiceActivatorBuilder
        $methodParameterConverterBuilders = MethodArgumentsFactory::createDefaultMethodParameters($this->interfaceToCall, $this->methodParameterConverterBuilders);

        $compiledMethodParameterConverters = [];
        foreach ($methodParameterConverterBuilders as $index => $methodParameterConverter) {
            $compiledMethodParameterConverters[] = $methodParameterConverter->compile($this->interfaceToCall);
        }

        $aggregateMethodCallProvider = new Definition(AggregateMethodCallProvider::class, [
            $this->interfaceToCall->getInterfaceName(),
            $this->interfaceToCall->getMethodName(),
            $compiledMethodParameterConverters,
            $this->interfaceToCall->getInterfaceParametersNames(),
        ]);
        if ($interceptorsConfiguration) {
            $aggregateMethodCallProvider = $builder->interceptMethodCall($this->getInterceptedInterface(), [], $aggregateMethodCallProvider);
        }

        return new Definition(CallAggregateMessageProcessor::class, [
            $aggregateMethodCallProvider,
            $this->interfaceToCall->getReturnType(),
            new Reference(PropertyReaderAccessor::class),
            $this->isCommandHandler,
            $this->interfaceToCall->isFactoryMethod() ?? false,
            $this->aggregateVersionProperty,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(): InterfaceToCallReference
    {
        return InterfaceToCallReference::fromInstance($this->interfaceToCall);
    }

    public function __toString()
    {
        return sprintf('Call Aggregate Handler - %s', (string)$this->interfaceToCall);
    }
}
