<?php

namespace Ecotone\Modelling\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Attribute\InputOutputEndpointAnnotation;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Parameter\ConfigurationVariable;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\Config\MessageHandlerLogger;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\IgnorePayload;
use Ecotone\Modelling\Attribute\NotUniqueHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Modelling\QueryBus;
use ReflectionMethod;
use ReflectionParameter;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class MessageHandlerRoutingModule implements AnnotationModule
{
    private BusRouterBuilder $commandBusByObject;
    private BusRouterBuilder $queryBusByObject;
    private BusRouterBuilder $eventBusByObject;
    private BusRouterBuilder $commandBusByName;
    private BusRouterBuilder $queryBusByName;
    private BusRouterBuilder $eventBusByName;

    public function __construct(BusRouterBuilder $commandBusByObject, BusRouterBuilder $commandBusByName, BusRouterBuilder $queryBusByObject, BusRouterBuilder $queryBusByName, BusRouterBuilder $eventBusByObject, BusRouterBuilder $eventBusByName)
    {
        $this->commandBusByObject       = $commandBusByObject;
        $this->queryBusByObject         = $queryBusByObject;
        $this->eventBusByObject         = $eventBusByObject;
        $this->commandBusByName         = $commandBusByName;
        $this->queryBusByName           = $queryBusByName;
        $this->eventBusByName           = $eventBusByName;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $uniqueObjectChannels = [];
        $uniqueNameChannels = [];
        return new self(
            BusRouterBuilder::createCommandBusByObject(self::getCommandBusByObjectMapping($annotationRegistrationService, $interfaceToCallRegistry, false, $uniqueObjectChannels)),
            BusRouterBuilder::createCommandBusByName(self::getCommandBusByNamesMapping($annotationRegistrationService, $interfaceToCallRegistry, false, $uniqueNameChannels)),
            BusRouterBuilder::createQueryBusByObject(self::getQueryBusByObjectsMapping($annotationRegistrationService, $interfaceToCallRegistry, $uniqueObjectChannels)),
            BusRouterBuilder::createQueryBusByName(self::getQueryBusByNamesMapping($annotationRegistrationService, $interfaceToCallRegistry, $uniqueNameChannels)),
            BusRouterBuilder::createEventBusByObject(self::getEventBusByObjectsMapping($annotationRegistrationService, $interfaceToCallRegistry, false)),
            BusRouterBuilder::createEventBusByName(self::getEventBusByNamesMapping($annotationRegistrationService, $interfaceToCallRegistry, false))
        );
    }

    public static function getCommandBusByObjectMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, bool $hasToBeDistributed, array &$uniqueChannels = []): array
    {
        $objectCommandHandlers = [];
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, CommandHandler::class) as $registration) {
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }
            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) && ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $classChannel = MessageHandlerRoutingModule::getFirstParameterClassIfAny($registration, $interfaceToCallRegistry);
            if ($classChannel) {
                $objectCommandHandlers[$classChannel][] = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
                $objectCommandHandlers[$classChannel]   = array_unique($objectCommandHandlers[$classChannel]);
                $uniqueChannels[$classChannel][]        = $registration;
            }
        }
        foreach ($annotationRegistrationService->findAnnotatedMethods(CommandHandler::class) as $registration) {
            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }
            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) && ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $classChannel = MessageHandlerRoutingModule::getFirstParameterClassIfAny($registration, $interfaceToCallRegistry);
            if ($classChannel) {
                $objectCommandHandlers[$classChannel][] = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
                $objectCommandHandlers[$classChannel]   = array_unique($objectCommandHandlers[$classChannel]);
                $uniqueChannels[$classChannel][]        = $registration;
            }
        }

        self::verifyUniqueness($uniqueChannels);

        return $objectCommandHandlers;
    }

    public static function getCommandBusByNamesMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, bool $hasToBeDistributed, array &$uniqueChannels = []): array
    {
        $namedCommandHandlers = [];
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, CommandHandler::class) as $registration) {
            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) && ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $namedChannel = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
            if ($namedChannel) {
                $namedCommandHandlers[$namedChannel][] = $namedChannel;
                $namedCommandHandlers[$namedChannel]   = array_unique($namedCommandHandlers[$namedChannel]);
                $uniqueChannels[$namedChannel][]       = $registration;
            }
        }
        foreach ($annotationRegistrationService->findAnnotatedMethods(CommandHandler::class) as $registration) {
            if ($registration->hasMethodAnnotation(Asynchronous::class)) {
                /** @var Asynchronous $asynchronous */
                $asynchronous = $registration->getMethodAnnotationsWithType(Asynchronous::class)[0];
                /** @var CommandHandler $annotationForMethod */
                $annotationForMethod = $registration->getAnnotationForMethod();
                Assert::isTrue(! in_array($annotationForMethod->getInputChannelName(), $asynchronous->getChannelName()), "Command Handler routing key can't be equal to asynchronous channel name in {$registration}");
            }

            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }
            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) && ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $namedChannel = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
            if ($namedChannel) {
                $namedCommandHandlers[$namedChannel][] = $namedChannel;
                $namedCommandHandlers[$namedChannel]   = array_unique($namedCommandHandlers[$namedChannel]);
                $uniqueChannels[$namedChannel][]       = $registration;
            }
        }

        self::verifyUniqueness($uniqueChannels);

        return $namedCommandHandlers;
    }

    public static function getQueryBusByObjectsMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, array &$uniqueChannels = []): array
    {
        $objectQueryHandlers = [];
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, QueryHandler::class) as $registration) {
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }

            $classChannel = MessageHandlerRoutingModule::getFirstParameterClassIfAny($registration, $interfaceToCallRegistry);
            if ($classChannel) {
                $objectQueryHandlers[$classChannel][] = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
                $objectQueryHandlers[$classChannel]   = array_unique($objectQueryHandlers[$classChannel]);
                $uniqueChannels[$classChannel][]      = $registration;
            }
        }
        foreach ($annotationRegistrationService->findAnnotatedMethods(QueryHandler::class) as $registration) {
            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }

            $classChannel = MessageHandlerRoutingModule::getFirstParameterClassIfAny($registration, $interfaceToCallRegistry);
            if ($classChannel) {
                $objectQueryHandlers[$classChannel][] = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
                $objectQueryHandlers[$classChannel]   = array_unique($objectQueryHandlers[$classChannel]);
                $uniqueChannels[$classChannel][]      = $registration;
            }
        }

        self::verifyUniqueness($uniqueChannels);

        return $objectQueryHandlers;
    }

    public static function getFirstParameterClassIfAny(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): ?string
    {
        $type = TypeDescriptor::create(self::getFirstParameterTypeFor($registration, $interfaceToCallRegistry));

        if ($type->isClassOrInterface() && ! $type->isClassOfType(TypeDescriptor::create(Message::class))) {
            return $type->toString();
        }

        return null;
    }

    public static function getFirstParameterEventPayloadClasses(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $type = TypeDescriptor::create(self::getFirstParameterTypeFor($registration, $interfaceToCallRegistry));
        if ($type->isClassOrInterface() && ! $type->isClassOfType(TypeDescriptor::create(Message::class))) {
            if ($type->isUnionType()) {
                return array_map(fn (TypeDescriptor $type) => $type->toString(), $type->getUnionTypes());
            }

            return [$type->toString()];
        }

        return [];
    }

    public static function getFirstParameterTypeFor(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): string
    {
        $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());

        if ($interfaceToCall->hasMethodAnnotation(TypeDescriptor::create(IgnorePayload::class)) || $interfaceToCall->hasNoParameters()) {
            return TypeDescriptor::ARRAY;
        }

        $firstParameterType = $interfaceToCall->getFirstParameter()->getTypeDescriptor();

        if ($firstParameterType->isClassOrInterface() && ! $firstParameterType->isClassOfType(TypeDescriptor::create(Message::class))) {
            $reflectionParameter = new ReflectionParameter([$registration->getClassName(), $registration->getMethodName()], 0);

            foreach ($reflectionParameter->getAttributes() as $attribute) {
                if (in_array($attribute->getName(), [ConfigurationVariable::class, Header::class, Headers::class, \Ecotone\Messaging\Attribute\Parameter\Reference::class])) {
                    return TypeDescriptor::ARRAY;
                }
            }

            return $firstParameterType;
        }

        return TypeDescriptor::ARRAY;
    }

    public static function getQueryBusByNamesMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, array &$uniqueChannels = []): array
    {
        $namedQueryHandlers = [];
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, QueryHandler::class) as $registration) {
            $namedChannel                        = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
            $namedQueryHandlers[$namedChannel][] = $namedChannel;
            $namedQueryHandlers[$namedChannel]   = array_unique($namedQueryHandlers[$namedChannel]);
            $uniqueChannels[$namedChannel][]     = $registration;
        }
        foreach ($annotationRegistrationService->findAnnotatedMethods(QueryHandler::class) as $registration) {
            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }

            $namedChannel                        = self::getRoutingInputMessageChannelFor($registration, $interfaceToCallRegistry);
            $namedQueryHandlers[$namedChannel][] = $namedChannel;
            $namedQueryHandlers[$namedChannel]   = array_unique($namedQueryHandlers[$namedChannel]);
            $uniqueChannels[$namedChannel][]     = $registration;
        }

        self::verifyUniqueness($uniqueChannels);

        return $namedQueryHandlers;
    }

    public static function getEventBusByObjectsMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, bool $hasToBeDistributed): array
    {
        $objectEventHandlers = [];
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, EventHandler::class) as $registration) {
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }
            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) || ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $unionEventClasses           = self::getFirstParameterEventPayloadClasses($registration, $interfaceToCallRegistry);
            $namedMessageChannelFor = self::getRoutingInputMessageChannelForEventHandler($registration, $interfaceToCallRegistry);

            foreach ($unionEventClasses as $classChannel) {
                $objectEventHandlers[$classChannel][] = $namedMessageChannelFor;
                $objectEventHandlers[$classChannel]   = array_unique($objectEventHandlers[$classChannel]);
            }
        }
        foreach ($annotationRegistrationService->findAnnotatedMethods(EventHandler::class) as $registration) {
            if ($registration->hasMethodAnnotation(Asynchronous::class)) {
                /** @var Asynchronous $asynchronous */
                $asynchronous = $registration->getMethodAnnotationsWithType(Asynchronous::class)[0];
                /** @var EventHandler $annotationForMethod */
                $annotationForMethod = $registration->getAnnotationForMethod();
                Assert::isTrue(! in_array($annotationForMethod->getListenTo(), $asynchronous->getChannelName()), "Event Handler listen to routing can't be equal to asynchronous channel name in {$registration}");
            }

            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }
            if (self::hasMessageNameDefined($registration)) {
                continue;
            }

            if ($hasToBeDistributed && (! $registration->hasMethodAnnotation(Distributed::class) || ! $registration->hasClassAnnotation(Distributed::class))) {
                continue;
            }

            $unionEventClasses           = self::getFirstParameterEventPayloadClasses($registration, $interfaceToCallRegistry);
            $namedMessageChannelFor = self::getRoutingInputMessageChannelForEventHandler($registration, $interfaceToCallRegistry);
            foreach ($unionEventClasses as $classChannel) {
                if (! EventBusRouter::isRegexBasedRoute($namedMessageChannelFor)) {
                    $objectEventHandlers[$classChannel][] = $namedMessageChannelFor;
                    $objectEventHandlers[$classChannel]   = array_unique($objectEventHandlers[$classChannel]);
                }
            }
        }

        return $objectEventHandlers;
    }

    public static function getEventBusByNamesMapping(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry, bool $hasToBeDistributed): array
    {
        $namedEventHandlers = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(EventHandler::class) as $registration) {
            /** @var EventHandler $annotation */
            $annotation = $registration->getAnnotationForMethod();
            if ($registration->hasClassAnnotation(Aggregate::class)) {
                continue;
            }
            if ($hasToBeDistributed && ! $registration->hasMethodAnnotation(Distributed::class)) {
                continue;
            }

            if ($annotation->getListenTo()) {
                $chanelName = self::getRoutingInputMessageChannelForEventHandler($registration, $interfaceToCallRegistry);
                $namedEventHandlers[$chanelName][] = $chanelName;
                $namedEventHandlers[$chanelName]   = array_unique($namedEventHandlers[$chanelName]);
            }
        }
        foreach ($annotationRegistrationService->findCombined(Aggregate::class, EventHandler::class) as $registration) {
            $channelName = self::getRoutingInputMessageChannelForEventHandler($registration, $interfaceToCallRegistry);
            if (EventBusRouter::isRegexBasedRoute($channelName)) {
                throw ConfigurationException::create("Can not registered regex listen to channel for aggregates in {$registration}");
            }
            if ($hasToBeDistributed && ! $registration->hasMethodAnnotation(Distributed::class)) {
                continue;
            }

            $namedEventHandlers[$channelName][] = $channelName;
            $namedEventHandlers[$channelName]   = array_unique($namedEventHandlers[$channelName]);
        }

        return $namedEventHandlers;
    }

    private static function isForTheSameAggregate(array $aggregateMethodUsage, $uniqueChannelName, string $oppositeMethodType, AnnotatedFinding $registration): bool
    {
        return ! isset($aggregateMethodUsage[$uniqueChannelName][$oppositeMethodType])
            || $aggregateMethodUsage[$uniqueChannelName][$oppositeMethodType]->getClassName() === $registration->getClassName();
    }

    /**
     * @param AnnotatedDefinition[][] $uniqueChannels
     *
     * @throws MessagingException
     */
    private static function verifyUniqueness(array $uniqueChannels): void
    {
        $notUniqueHandlerAnnotation = TypeDescriptor::create(NotUniqueHandler::class);
        $aggregateAnnotation        = TypeDescriptor::create(Aggregate::class);
        foreach ($uniqueChannels as $uniqueChannelName => $registrations) {
            $combinedRegistrationNames = '';
            $registrationsToVerify     = [];
            $aggregateMethodUsage      = [];
            foreach ($registrations as $registration) {
                if ($registration->hasMethodAnnotation($notUniqueHandlerAnnotation)) {
                    continue;
                }

                if ($registration->hasClassAnnotation($aggregateAnnotation)) {
                    $isStatic           = (new ReflectionMethod($registration->getClassName(), $registration->getMethodName()))->isStatic();
                    $methodType         = $isStatic ? 'factory' : 'action';
                    $oppositeMethodType = $isStatic ? 'action' : 'factory';
                    if (! isset($aggregateMethodUsage[$uniqueChannelName][$methodType])) {
                        $aggregateMethodUsage[$uniqueChannelName][$methodType] = $registration;
                        if (self::isForTheSameAggregate($aggregateMethodUsage, $uniqueChannelName, $oppositeMethodType, $registration)) {
                            continue;
                        }
                    }

                    $registrationsToVerify[] = $aggregateMethodUsage[$uniqueChannelName][$methodType];
                }

                $registrationsToVerify[] = $registration;
            }

            if (count($registrationsToVerify) <= 1) {
                continue;
            }

            foreach ($registrationsToVerify as $registration) {
                $combinedRegistrationNames .= " {$registration->getClassName()}:{$registration->getMethodName()}";
            }

            throw ConfigurationException::create("Channel name `{$uniqueChannelName}` should be unique, but is used in multiple handlers:{$combinedRegistrationNames}");
        }
    }

    /**
     * This allows to decouple input channel for routing from specific channel that executes the Handler.
     * This way each handler can be treated separately (e.g. for async processing)
     */
    public static function getExecutionMessageHandlerChannel(AnnotatedFinding $registration): string
    {
        /** @var EndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        return $annotationForMethod->getEndpointId() . '.target';
    }

    public static function getRoutingInputMessageChannelForEventHandler(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): string
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        $inputChannelName = null;
        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        }

        if (! $inputChannelName) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            if ($interfaceToCall->hasNoParameters()) {
                throw ConfigurationException::create("Missing command class or listen routing for {$registration}.");
            }
            $inputChannelName = $interfaceToCall->getFirstParameterTypeHint();
        }

        return $inputChannelName;
    }

    public static function getRoutingInputMessageChannelFor(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): string
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        } else {
            $inputChannelName = $annotationForMethod->getInputChannelName();
        }

        if (! $inputChannelName) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            if ($interfaceToCall->hasNoParameters()) {
                throw ConfigurationException::create("Missing class type hint or routing key for {$registration}.");
            }
            if ($interfaceToCall->getFirstParameter()->getTypeDescriptor()->isUnionType()) {
                throw ConfigurationException::create("Query and Command handlers can not be registered with union Command type in {$registration}");
            }
            $inputChannelName = $interfaceToCall->getFirstParameterTypeHint();
        }

        return $inputChannelName;
    }

    public static function hasMessageNameDefined(AnnotatedFinding $registration): bool
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        } else {
            $inputChannelName = $annotationForMethod->getInputChannelName();
        }

        return $inputChannelName ? true : false;
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration->registerServiceDefinition(
            MessageHeadersPropagatorInterceptor::class,
            new Definition(MessageHeadersPropagatorInterceptor::class)
        );
        $messagingConfiguration->registerServiceDefinition(
            MessageHandlerLogger::class,
            new Definition(MessageHandlerLogger::class)
        );

        $propagateHeadersInterfaceToCall = $interfaceToCallRegistry->getFor(MessageHeadersPropagatorInterceptor::class, 'propagateHeaders');
        $storeHeadersInterfaceToCall = $interfaceToCallRegistry->getFor(MessageHeadersPropagatorInterceptor::class, 'storeHeaders');
        $pointcut =
            CommandBus::class . '||' .
            EventBus::class . '||' .
            QueryBus::class . '||' .
            AsynchronousRunningEndpoint::class . '||' .
            PropagateHeaders::class . '||' .
            MessagingEntrypointWithHeadersPropagation::class . '||' .
            MessageGateway::class;

        $messagingConfiguration
            ->registerBeforeMethodInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to(MessageHeadersPropagatorInterceptor::class),
                    $propagateHeadersInterfaceToCall,
                    Precedence::ENDPOINT_HEADERS_PRECEDENCE - 2,
                    $pointcut,
                    true,
                    [
                        AllHeadersBuilder::createWith('headers'),
                    ]
                )
            )
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    MessageHeadersPropagatorInterceptor::class,
                    $storeHeadersInterfaceToCall,
                    Precedence::ENDPOINT_HEADERS_PRECEDENCE - 1,
                    $pointcut,
                    ParameterConverterAnnotationFactory::create()->createParameterConverters($storeHeadersInterfaceToCall),
                )
            )
            ->registerMessageHandler($this->commandBusByObject)
            ->registerMessageHandler($this->commandBusByName)
            ->registerMessageHandler($this->queryBusByObject)
            ->registerMessageHandler($this->queryBusByName)
            ->registerMessageHandler($this->eventBusByObject)
            ->registerMessageHandler($this->eventBusByName);
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
