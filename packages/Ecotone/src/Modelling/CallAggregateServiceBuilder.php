<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\CompilableParameterConverterBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Enricher\PropertyEditorAccessor;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodArgumentsFactory;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\AggregateEvents;
use Ecotone\Modelling\Attribute\AggregateVersion;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingSaga;
use Exception;

use function uniqid;

class CallAggregateServiceBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters, MessageHandlerBuilderWithOutputChannel
{
    private InterfaceToCall $interfaceToCall;
    /**
     * @var ParameterConverterBuilder[]
     */
    private array $methodParameterConverterBuilders = [];
    /**
     * @var string[]
     */
    private array $requiredReferences = [];
    /**
     * @var bool
     */
    private bool $isCommandHandler;
    /**
     * @var string[]
     */
    private array $aggregateRepositoryReferenceNames = [];
    private bool $isVoidMethod;
    private EventSourcingHandlerExecutor $eventSourcingHandlerExecutor;
    private ?string $aggregateMethodWithEvents;
    private ?string $aggregateVersionProperty;
    private bool $isAggregateVersionAutomaticallyIncreased = true;
    private bool $isEventSourced = false;

    private function __construct(ClassDefinition $aggregateClassDefinition, string $methodName, bool $isCommandHandler, InterfaceToCallRegistry $interfaceToCallRegistry)
    {
        $this->isCommandHandler = $isCommandHandler;

        $this->initialize($aggregateClassDefinition, $methodName, $interfaceToCallRegistry);
    }

    private function initialize(ClassDefinition $aggregateClassDefinition, string $methodName, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $interfaceToCall = $interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $methodName);
        $this->isVoidMethod = $interfaceToCall->getReturnType()->isVoid();

        $aggregateMethodWithEvents    = null;
        $aggregateEventsAnnotation = TypeDescriptor::create(AggregateEvents::class);
        foreach ($aggregateClassDefinition->getPublicMethodNames() as $method) {
            $methodToCheck = $interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $method);

            if ($methodToCheck->hasMethodAnnotation($aggregateEventsAnnotation)) {
                $aggregateMethodWithEvents = $method;
                break;
            }
        }
        $this->aggregateMethodWithEvents = $aggregateMethodWithEvents;

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
                /** @var AggregateVersion $annotation */
                $annotation = $property->getAnnotation($versionAnnotation);
                $this->isAggregateVersionAutomaticallyIncreased = $annotation->isAutoIncreased();
            }
        }
        $this->aggregateVersionProperty             = $aggregateVersionPropertyName;

        if ($this->isEventSourced) {
            Assert::isTrue((bool)$this->aggregateVersionProperty, "{$interfaceToCall->getInterfaceName()} is event sourced aggregate. Event Sourced aggregates are required to define version property. Make use of " . WithAggregateVersioning::class . ' or implement your own.');
        }

        $this->interfaceToCall = $interfaceToCall;
        $this->eventSourcingHandlerExecutor = EventSourcingHandlerExecutor::createFor($aggregateClassDefinition, $this->isEventSourced, $interfaceToCallRegistry);
        $isFactoryMethod = $this->interfaceToCall->isStaticallyCalled();
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
    public function getRequiredReferenceNames(): array
    {
        return $this->requiredReferences;
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

    /**
     * @inheritDoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        $orderedAroundInterceptors = AroundInterceptorReference::createAroundInterceptorsWithChannel($referenceSearchService, $this->orderedAroundInterceptors, $this->getEndpointAnnotations(), $this->interfaceToCall);
        $methodParameterConverters = MethodArgumentsFactory::createDefaultMethodParameters($this->interfaceToCall, $this->methodParameterConverterBuilders, $this->getEndpointAnnotations(), null, false);
        $handler = ServiceActivatorBuilder::createWithDirectReference(
            new CallAggregateService($this->interfaceToCall, $this->isEventSourced, $channelResolver, $methodParameterConverters, $orderedAroundInterceptors, $referenceSearchService, new PropertyReaderAccessor(), PropertyEditorAccessor::create($referenceSearchService), $this->isCommandHandler, $this->interfaceToCall->isStaticallyCalled(), $this->eventSourcingHandlerExecutor, $this->aggregateVersionProperty, $this->isAggregateVersionAutomaticallyIncreased, $this->aggregateMethodWithEvents),
            'call'
        )
            ->withPassThroughMessageOnVoidInterface($this->isVoidMethod)
            ->withOutputMessageChannel($this->outputMessageChannelName);

        return $handler->build($channelResolver, $referenceSearchService);
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        $interceptors = [];
        foreach (AroundInterceptorReference::orderedInterceptors($this->orderedAroundInterceptors) as $aroundInterceptorReference) {
            if ($interceptor = $aroundInterceptorReference->compile($builder, $this->getEndpointAnnotations(), $this->interfaceToCall)) {
                $interceptors[] = $interceptor;
            } else {
                // Cannot continue without every interceptor being compilable
                throw new Exception("Cannot compile {$this} due to un-compilable interceptor {$aroundInterceptorReference}");
            }
        }

        // TODO: code duplication with ServiceActivatorBuilder
        $methodParameterConverterBuilders = MethodArgumentsFactory::createDefaultMethodParameters($this->interfaceToCall, $this->methodParameterConverterBuilders, $this->getEndpointAnnotations(), null, false);

        $compiledMethodParameterConverters = [];
        foreach ($methodParameterConverterBuilders as $index => $methodParameterConverter) {
            if (! ($methodParameterConverter instanceof CompilableParameterConverterBuilder)) {
                // Cannot continue without every parameter converters compilable
                return null;
            }
            $compiledMethodParameterConverters[] = $methodParameterConverter->compile($builder, $this->interfaceToCall, $this->interfaceToCall->getInterfaceParameters()[$index]);
        }

        $callAggregateService = new Definition(CallAggregateService::class, [
            InterfaceToCallReference::fromInstance($this->interfaceToCall),
            $this->isEventSourced,
            new Reference(ChannelResolver::class),
            $compiledMethodParameterConverters,
            $interceptors,
            new Reference(ReferenceSearchService::class),
            new Reference(PropertyReaderAccessor::class),
            new Reference(PropertyEditorAccessor::class),
            $this->isCommandHandler,
            $this->interfaceToCall->isStaticallyCalled(),
            $this->eventSourcingHandlerExecutor,
            $this->aggregateVersionProperty,
            $this->isAggregateVersionAutomaticallyIncreased,
            $this->aggregateMethodWithEvents,
        ]);

        $reference = $builder->register(uniqid(CallAggregateService::class), $callAggregateService);
        $interfaceToCall = $builder->getInterfaceToCall(new InterfaceToCallReference(CallAggregateService::class, 'call'));

        $serviceActivator = ServiceActivatorBuilder::create($reference, $interfaceToCall)
            ->withOutputMessageChannel($this->outputMessageChannelName)
            ->compile($builder);
        return $serviceActivator;
    }

    /**
     * @param string[] $aggregateRepositoryReferenceNames
     */
    public function withAggregateRepositoryFactories(array $aggregateRepositoryReferenceNames): self
    {
        $this->aggregateRepositoryReferenceNames = $aggregateRepositoryReferenceNames;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [
            $interfaceToCallRegistry->getFor($this->interfaceToCall->getInterfaceName(), $this->interfaceToCall->getMethodName()),
            $interfaceToCallRegistry->getFor(CallAggregateService::class, 'call'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor($this->interfaceToCall->getInterfaceName(), $this->interfaceToCall->getMethodName());
    }

    public function __toString()
    {
        return sprintf('Aggregate Handler - %s with name `%s` for input channel `%s`', (string)$this->interfaceToCall, $this->getEndpointId(), $this->getInputMessageChannelName());
    }
}
