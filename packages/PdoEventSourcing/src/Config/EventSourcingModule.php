<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\AggregateStreamMapping;
use Ecotone\EventSourcing\AggregateTypeMapping;
use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionChannelAdapter;
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionExecutorBuilder;
use Ecotone\EventSourcing\EventMapper;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventSourcingRepositoryBuilder;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\EventSourcing\ProjectionLifeCycleConfiguration;
use Ecotone\EventSourcing\ProjectionManager;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\ProjectionStreamSource;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleCommandParameter;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\Chain\ChainMessageHandlerBuilder;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Filter\MessageFilterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Router\RouterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;
use Ecotone\Messaging\Handler\Transformer\TransformerBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\IgnorePayload;
use Ecotone\Modelling\Attribute\NamedEvent;
use Ecotone\Modelling\Config\BusModule;
use Ecotone\Modelling\Config\ModellingHandlerModule;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[ModuleAnnotation]
class EventSourcingModule extends NoExternalConfigurationModule
{
    public const ECOTONE_ES_STOP_PROJECTION = 'ecotone:es:stop-projection';
    public const ECOTONE_ES_RESET_PROJECTION = 'ecotone:es:reset-projection';
    public const ECOTONE_ES_DELETE_PROJECTION = 'ecotone:es:delete-projection';
    public const ECOTONE_ES_INITIALIZE_PROJECTION = 'ecotone:es:initialize-projection';
    public const ECOTONE_ES_TRIGGER_PROJECTION = 'ecotone:es:trigger-projection';
    /**
     * @var ProjectionSetupConfiguration[]
     */
    private array $projectionSetupConfigurations;
    /** @var ServiceActivatorBuilder[] */
    private array $projectionLifeCycleServiceActivators = [];
    private EventMapper $eventMapper;
    private AggregateStreamMapping $aggregateToStreamMapping;
    private AggregateTypeMapping $aggregateTypeMapping;

    /**
     * @var ProjectionSetupConfiguration[]
     * @var AnnotatedDefinition[] $projectionEventHandlers
     * @var ServiceActivatorBuilder[]
     * @var GatewayProxyBuilder[]
     */
    private function __construct(array $projectionConfigurations, private array $projectionEventHandlers, private AsynchronousModule $asynchronousModule, array $projectionLifeCycleServiceActivators, EventMapper $eventMapper, AggregateStreamMapping $aggregateToStreamMapping, AggregateTypeMapping $aggregateTypeMapping, private array $projectionStateGateways)
    {
        $this->projectionSetupConfigurations = $projectionConfigurations;
        $this->projectionLifeCycleServiceActivators = $projectionLifeCycleServiceActivators;
        $this->eventMapper = $eventMapper;
        $this->aggregateToStreamMapping = $aggregateToStreamMapping;
        $this->aggregateTypeMapping = $aggregateTypeMapping;
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $fromClassToNameMapping = [];
        $fromNameToClassMapping = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(NamedEvent::class) as $namedEventClass) {
            /** @var NamedEvent $attribute */
            $attribute = $annotationRegistrationService->getAttributeForClass($namedEventClass, NamedEvent::class);

            if (array_key_exists($attribute->getName(), $fromNameToClassMapping)) {
                throw new RuntimeException(sprintf('Named Events should have unique names. However, `%s` is used more than once.', $attribute->getName()));
            }

            $fromClassToNameMapping[$namedEventClass] = $attribute->getName();
            $fromNameToClassMapping[$attribute->getName()] = $namedEventClass;
        }

        $aggregateToStreamMapping = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(Stream::class) as $aggregateWithCustomStream) {
            /** @var Stream $attribute */
            $attribute = $annotationRegistrationService->getAttributeForClass($aggregateWithCustomStream, Stream::class);

            $aggregateToStreamMapping[$aggregateWithCustomStream] = $attribute->getName();
        }

        $aggregateTypeMapping = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(AggregateType::class) as $aggregateWithCustomType) {
            /** @var Stream $attribute */
            $attribute = $annotationRegistrationService->getAttributeForClass($aggregateWithCustomType, AggregateType::class);

            $aggregateTypeMapping[$aggregateWithCustomType] = $attribute->getName();
        }

        $projectionStateGateways = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(ProjectionStateGateway::class) as $projectionStateGatewayConfiguration) {
            /** @var ProjectionStateGateway $attribute */
            $attribute = $projectionStateGatewayConfiguration->getAnnotationForMethod();

            $projectionStateGateways[] = GatewayProxyBuilder::create(
                $projectionStateGatewayConfiguration->getClassName(),
                $projectionStateGatewayConfiguration->getClassName(),
                $projectionStateGatewayConfiguration->getMethodName(),
                ProjectionManagerBuilder::getProjectionManagerActionChannel(
                    $attribute->getProjectioManagerReference(),
                    'getProjectionState'
                )
            )->withParameterConverters([
                GatewayHeaderValueBuilder::create('ecotone.eventSourcing.manager.name', $attribute->getProjectionName()),
            ]);
        }

        $projectionClassNames = $annotationRegistrationService->findAnnotatedClasses(Projection::class);
        $projectionEventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);
        $projectionSetupConfigurations = [];
        $projectionLifeCyclesServiceActivators = [];

        foreach ($projectionClassNames as $projectionClassName) {
            $referenceName = AnnotatedDefinitionReference::getReferenceForClassName($annotationRegistrationService, $projectionClassName);

            $projectionLifeCycle = ProjectionLifeCycleConfiguration::create();

            $classDefinition = ClassDefinition::createUsingAnnotationParser(TypeDescriptor::create($projectionClassName), $annotationRegistrationService);
            $projectionInitialization = TypeDescriptor::create(ProjectionInitialization::class);
            $projectionDelete = TypeDescriptor::create(ProjectionDelete::class);
            $projectionReset = TypeDescriptor::create(ProjectionReset::class);
            $parameterConverterFactory = ParameterConverterAnnotationFactory::create();
            foreach ($classDefinition->getPublicMethodNames() as $publicMethodName) {
                foreach ($annotationRegistrationService->getAnnotationsForMethod($projectionClassName, $publicMethodName) as $attribute) {
                    $attributeType = TypeDescriptor::createFromVariable($attribute);
                    $interfaceToCall = $interfaceToCallRegistry->getFor($classDefinition->getClassType()->toString(), $publicMethodName);
                    if ($attributeType->equals($projectionInitialization)) {
                        $requestChannel = Uuid::uuid4()->toString();
                        $projectionLifeCycle = $projectionLifeCycle->withInitializationRequestChannel($requestChannel);
                        $projectionLifeCyclesServiceActivators[] = ServiceActivatorBuilder::create(
                            $referenceName,
                            $interfaceToCall
                        )
                            ->withInputChannelName($requestChannel)
                            ->withMethodParameterConverters(
                                $parameterConverterFactory->createParameterWithDefaults($interfaceToCall, (bool)$interfaceToCall->hasMethodAnnotation(TypeDescriptor::create(IgnorePayload::class)))
                            );
                    }
                    if ($attributeType->equals($projectionDelete)) {
                        $requestChannel = Uuid::uuid4()->toString();
                        $projectionLifeCycle = $projectionLifeCycle->withDeleteRequestChannel($requestChannel);
                        $projectionLifeCyclesServiceActivators[] = ServiceActivatorBuilder::create(
                            $referenceName,
                            $interfaceToCall
                        )
                            ->withInputChannelName($requestChannel)
                            ->withMethodParameterConverters(
                                $parameterConverterFactory->createParameterWithDefaults($interfaceToCall, (bool)$interfaceToCall->hasMethodAnnotation(TypeDescriptor::create(IgnorePayload::class)))
                            );
                    }
                    if ($attributeType->equals($projectionReset)) {
                        $requestChannel = Uuid::uuid4()->toString();
                        $projectionLifeCycle = $projectionLifeCycle->withResetRequestChannel($requestChannel);
                        $projectionLifeCyclesServiceActivators[] = ServiceActivatorBuilder::create(
                            $referenceName,
                            $interfaceToCall
                        )
                            ->withInputChannelName($requestChannel)
                            ->withMethodParameterConverters(
                                $parameterConverterFactory->createParameterWithDefaults($interfaceToCall, (bool)$interfaceToCall->hasMethodAnnotation(TypeDescriptor::create(IgnorePayload::class)))
                            );
                    }
                }
            }

            $attributes = $annotationRegistrationService->getAnnotationsForClass($projectionClassName);
            /** @var Projection $projectionAttribute */
            $projectionAttribute = null;
            /** @var Asynchronous|null $asynchronousChannelName */
            $asynchronousChannelName = null;
            foreach ($attributes as $attribute) {
                if ($attribute instanceof Projection) {
                    $projectionAttribute = $attribute;
                }
                if ($attribute instanceof Asynchronous) {
                    $asynchronousChannelName = $attribute->getChannelName();
                    Assert::isTrue(count($asynchronousChannelName) === 1, "Make use of single channel name in Asynchronous annotation for Projection: {$projectionClassName}");
                    $asynchronousChannelName = array_pop($asynchronousChannelName);
                }
            }

            Assert::keyNotExists($projectionSetupConfigurations, $projectionAttribute->getName(), "Can't define projection with name {$projectionAttribute->getName()} twice");

            if ($projectionAttribute->isFromAll()) {
                $projectionConfiguration = ProjectionSetupConfiguration::create(
                    $projectionAttribute->getName(),
                    $projectionLifeCycle,
                    $projectionAttribute->getEventStoreReferenceName(),
                    ProjectionStreamSource::forAllStreams(),
                    $asynchronousChannelName
                );
            } elseif ($projectionAttribute->getFromStreams()) {
                $projectionConfiguration = ProjectionSetupConfiguration::create(
                    $projectionAttribute->getName(),
                    $projectionLifeCycle,
                    $projectionAttribute->getEventStoreReferenceName(),
                    ProjectionStreamSource::fromStreams($projectionAttribute->getFromStreams()),
                    $asynchronousChannelName
                );
            } else {
                $projectionConfiguration = ProjectionSetupConfiguration::create(
                    $projectionAttribute->getName(),
                    $projectionLifeCycle,
                    $projectionAttribute->getEventStoreReferenceName(),
                    ProjectionStreamSource::fromCategories($projectionAttribute->getFromCategories()),
                    $asynchronousChannelName
                );
            }

            $projectionSetupConfigurations[$projectionAttribute->getName()] = $projectionConfiguration;
        }

        return new self($projectionSetupConfigurations, $projectionEventHandlers, AsynchronousModule::create($annotationRegistrationService, $interfaceToCallRegistry), $projectionLifeCyclesServiceActivators, EventMapper::createWith($fromClassToNameMapping, $fromNameToClassMapping), AggregateStreamMapping::createWith($aggregateToStreamMapping), AggregateTypeMapping::createWith($aggregateTypeMapping), $projectionStateGateways);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $extensionObjects, EventSourcingConfiguration::createWithDefaults());

        $messagingConfiguration->registerServiceDefinition(EventSourcingConfiguration::class, DefinitionHelper::buildDefinitionFromInstance($eventSourcingConfiguration));

        $messagingConfiguration->registerServiceDefinition(LazyProophEventStore::class, new Definition(LazyProophEventStore::class, [
            new Reference(EventSourcingConfiguration::class),
            new Reference(EventMapper::class),
            new Reference($eventSourcingConfiguration->getConnectionReferenceName(), ContainerImplementation::NULL_ON_INVALID_REFERENCE),
        ]));

        $this->registerProjections($serviceConfiguration, $interfaceToCallRegistry, $moduleReferenceSearchService, $messagingConfiguration, $extensionObjects, $eventSourcingConfiguration);
        foreach ($this->projectionLifeCycleServiceActivators as $serviceActivator) {
            $messagingConfiguration->registerMessageHandler($serviceActivator);
        }
        $this->registerEventStore($messagingConfiguration, $eventSourcingConfiguration);
        $this->registerEventStreamEmitter($messagingConfiguration, $eventSourcingConfiguration);
        $this->registerProjectionManager($messagingConfiguration, $eventSourcingConfiguration);
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof EventSourcingConfiguration
            || $extensionObject instanceof ProjectionRunningConfiguration
            || $extensionObject instanceof ServiceConfiguration;
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        foreach ($serviceExtensions as $serviceExtension) {
            if ($serviceExtension instanceof EventSourcingRepositoryBuilder) {
                return [];
            }
        }

        $eventSourcingRepositories = [];
        foreach ($serviceExtensions as $extensionObject) {
            if ($extensionObject instanceof EventSourcingConfiguration) {
                $eventSourcingRepositories[] = EventSourcingRepositoryBuilder::create($extensionObject);
            }
        }

        return $eventSourcingRepositories ?: [EventSourcingRepositoryBuilder::create(EventSourcingConfiguration::createWithDefaults())];
    }

    private function registerEventStore(Configuration $configuration, EventSourcingConfiguration $eventSourcingConfiguration): void
    {
        $this->registerEventStoreAction(
            'create',
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents'), HeaderBuilder::create('streamMetadata', 'ecotone.eventSourcing.eventStore.streamMetadata')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), GatewayPayloadBuilder::create('streamEvents'), GatewayHeaderBuilder::create('streamMetadata', 'ecotone.eventSourcing.eventStore.streamMetadata')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerEventStoreAction(
            'appendTo',
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), GatewayPayloadBuilder::create('streamEvents')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerEventStoreAction(
            'delete',
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerEventStoreAction(
            'hasStream',
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerEventStoreAction(
            'load',
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), HeaderBuilder::create('fromNumber', 'ecotone.eventSourcing.eventStore.fromNumber'), HeaderBuilder::createOptional('count', 'ecotone.eventSourcing.eventStore.count'), HeaderBuilder::createOptional('metadataMatcher', 'ecotone.eventSourcing.eventStore.metadataMatcher'), HeaderBuilder::create('deserialize', 'ecotone.eventSourcing.eventStore.deserialize')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), GatewayHeaderBuilder::create('fromNumber', 'ecotone.eventSourcing.eventStore.fromNumber'), GatewayHeaderBuilder::create('count', 'ecotone.eventSourcing.eventStore.count'), GatewayHeaderBuilder::create('metadataMatcher', 'ecotone.eventSourcing.eventStore.metadataMatcher'), GatewayHeaderBuilder::create('deserialize', 'ecotone.eventSourcing.eventStore.deserialize')],
            $eventSourcingConfiguration,
            $configuration
        );

        foreach ($this->projectionStateGateways as $projectionStateGateway) {
            $configuration->registerGatewayBuilder($projectionStateGateway);
        }
    }

    private function registerProjectionManager(Configuration $configuration, EventSourcingConfiguration $eventSourcingConfiguration): void
    {
        $this->registerProjectionManagerAction(
            'deleteProjection',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name'), HeaderBuilder::create('deleteEmittedEvents', 'ecotone.eventSourcing.manager.deleteEmittedEvents')],
            [
                GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name'),
                GatewayHeaderValueBuilder::create('ecotone.eventSourcing.manager.deleteEmittedEvents', true),
                GatewayHeadersBuilder::create('metadata'),
            ],
            $eventSourcingConfiguration,
            $configuration,
            self::ECOTONE_ES_DELETE_PROJECTION,
            [ConsoleCommandParameter::create('name', 'ecotone.eventSourcing.manager.name', false), ConsoleCommandParameter::createWithDefaultValue('deleteEmittedEvents', 'ecotone.eventSourcing.manager.deleteEmittedEvents', true, false, true)]
        );

        $this->registerProjectionManagerAction(
            'resetProjection',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [
                GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name'),
                GatewayHeadersBuilder::create('metadata'),
            ],
            $eventSourcingConfiguration,
            $configuration,
            self::ECOTONE_ES_RESET_PROJECTION,
            [ConsoleCommandParameter::create('name', 'ecotone.eventSourcing.manager.name', false)]
        );

        $this->registerProjectionManagerAction(
            'stopProjection',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            $eventSourcingConfiguration,
            $configuration,
            self::ECOTONE_ES_STOP_PROJECTION,
            [ConsoleCommandParameter::create('name', 'ecotone.eventSourcing.manager.name', false)]
        );

        $this->registerProjectionManagerAction(
            'initializeProjection',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [
                GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name'),
                GatewayHeadersBuilder::create('metadata'),
            ],
            $eventSourcingConfiguration,
            $configuration,
            self::ECOTONE_ES_INITIALIZE_PROJECTION,
            [ConsoleCommandParameter::create('name', 'ecotone.eventSourcing.manager.name', false)]
        );

        $this->registerProjectionManagerAction(
            'triggerProjection',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [
                GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name'),
                GatewayHeadersBuilder::create('metadata'),
            ],
            $eventSourcingConfiguration,
            $configuration,
            self::ECOTONE_ES_TRIGGER_PROJECTION,
            [ConsoleCommandParameter::create('name', 'ecotone.eventSourcing.manager.name', false)]
        );

        $this->registerProjectionManagerAction(
            'hasInitializedProjectionWithName',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerProjectionManagerAction(
            'getProjectionStatus',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerProjectionManagerAction(
            'getProjectionState',
            [HeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            [GatewayHeaderBuilder::create('name', 'ecotone.eventSourcing.manager.name')],
            $eventSourcingConfiguration,
            $configuration
        );
    }

    private function registerProjectionManagerAction(string $methodName, array $endpointConverters, array $gatewayConverters, EventSourcingConfiguration $eventSourcingConfiguration, Configuration $configuration, ?string $consoleCommandName = null, array $consoleCommandParameters = []): void
    {
        $messageHandlerBuilder = ProjectionManagerBuilder::create($methodName, $endpointConverters, $eventSourcingConfiguration, $this->projectionSetupConfigurations);
        $configuration->registerMessageHandler($messageHandlerBuilder);
        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create($eventSourcingConfiguration->getProjectManagerReferenceName(), ProjectionManager::class, $methodName, $messageHandlerBuilder->getInputMessageChannelName())
                ->withParameterConverters($gatewayConverters)
        );

        if ($consoleCommandName) {
            $configuration->registerConsoleCommand(
                ConsoleCommandConfiguration::create(
                    $messageHandlerBuilder->getInputMessageChannelName(),
                    $consoleCommandName,
                    $consoleCommandParameters
                )
            );
        }
    }

    private function registerEventStoreAction(string $methodName, array $endpointConverters, array $gatewayConverters, EventSourcingConfiguration $eventSourcingConfiguration, Configuration $configuration): void
    {
        $messageHandlerBuilder = EventStoreBuilder::create($methodName, $endpointConverters, $eventSourcingConfiguration, Reference::to(LazyProophEventStore::class));
        $configuration->registerMessageHandler($messageHandlerBuilder);

        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create($eventSourcingConfiguration->getEventStoreReferenceName(), EventStore::class, $methodName, $messageHandlerBuilder->getInputMessageChannelName())
                ->withParameterConverters($gatewayConverters)
        );
    }

    private function registerEventStreamEmitter(Configuration $configuration, EventSourcingConfiguration $eventSourcingConfiguration): void
    {
        $eventSourcingConfiguration = (clone $eventSourcingConfiguration)->withSimpleStreamPersistenceStrategy();
        $eventSourcingConfigurationReference = new Reference(EventSourcingConfiguration::class.'.eventStreamEmitter');
        $configuration->registerServiceDefinition($eventSourcingConfigurationReference->getId(), DefinitionHelper::buildDefinitionFromInstance($eventSourcingConfiguration));
        $eventStoreReference = new Reference(LazyProophEventStore::class.'.eventStreamEmitter');
        $configuration->registerServiceDefinition($eventStoreReference->getId(), new Definition(LazyProophEventStore::class, [
            $eventSourcingConfigurationReference,
            new Reference(EventMapper::class),
            new Reference($eventSourcingConfiguration->getConnectionReferenceName(), ContainerImplementation::NULL_ON_INVALID_REFERENCE),
        ]));

        $eventStoreHandler = EventStoreBuilder::create('appendTo', [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents')], $eventSourcingConfiguration, $eventStoreReference)
            ->withInputChannelName(Uuid::uuid4()->toString())
        ;
        $configuration->registerMessageHandler($eventStoreHandler);

        $eventBusChannelName = Uuid::uuid4()->toString();
        $configuration->registerMessageHandler(
            SplitterBuilder::createMessagePayloadSplitter()
                ->withInputChannelName($eventBusChannelName)
                ->withOutputMessageChannel(BusModule::EVENT_CHANNEL_NAME_BY_OBJECT)
        );

        $linkingRouterHandler =
            ChainMessageHandlerBuilder::create()
                ->withInputChannelName(Uuid::uuid4()->toString())
                /** linkTo can be used outside of Projection, then we should NOT filter events out */
                ->chain(MessageFilterBuilder::createBoolHeaderFilter(ProjectionEventHandler::PROJECTION_IS_REBUILDING, false))
                ->withOutputMessageHandler(RouterBuilder::createRecipientListRouter([
                    $eventStoreHandler->getInputMessageChannelName(),
                    $eventBusChannelName,
                ]));
        $configuration->registerMessageHandler($linkingRouterHandler);

        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create(EventStreamEmitter::class, EventStreamEmitter::class, 'linkTo', $linkingRouterHandler->getInputMessageChannelName())
                ->withEndpointAnnotations([new AttributeDefinition(PropagateHeaders::class)])
                ->withParameterConverters([GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), GatewayPayloadBuilder::create('streamEvents')], )
        );


        $emittingRouterHandler =
            ChainMessageHandlerBuilder::create()
                ->withInputChannelName(Uuid::uuid4()->toString())
                ->chain(TransformerBuilder::createWithDirectObject(new StreamNameMapper(), 'map'))
                ->chain(MessageFilterBuilder::createBoolHeaderFilter(ProjectionEventHandler::PROJECTION_IS_REBUILDING))
                ->withOutputMessageHandler(RouterBuilder::createRecipientListRouter([
                    $eventStoreHandler->getInputMessageChannelName(),
                    $eventBusChannelName,
                ]));
        $configuration->registerMessageHandler($emittingRouterHandler);

        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create(EventStreamEmitter::class, EventStreamEmitter::class, 'emit', $emittingRouterHandler->getInputMessageChannelName())
                ->withEndpointAnnotations([new AttributeDefinition(PropagateHeaders::class)])
                ->withParameterConverters([GatewayPayloadBuilder::create('streamEvents')])
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::EVENT_SOURCING_PACKAGE;
    }

    private function registerProjections(ServiceConfiguration $serviceConfiguration, InterfaceToCallRegistry $interfaceToCallRegistry, ModuleReferenceSearchService $moduleReferenceSearchService, Configuration $messagingConfiguration, array $extensionObjects, EventSourcingConfiguration $eventSourcingConfiguration): void
    {
        $projectionRunningConfigurations = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ProjectionRunningConfiguration) {
                $projectionRunningConfigurations[$extensionObject->getProjectionName()] = $extensionObject;
            }
        }
        foreach ($this->projectionEventHandlers as $projectionEventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();
            /** @var EndpointAnnotation $handlerAttribute */
            $handlerAttribute = $projectionEventHandler->getAnnotationForMethod();
            $projectionConfiguration = $this->projectionSetupConfigurations[$projectionAttribute->getName()];

            /** @TODO in case of Projection Event Handlers we don't need to register them asynchronously, so this can be simplified to assume always synchronous */
            $eventHandlerTriggeringInputChannel = ModellingHandlerModule::getHandlerChannel($projectionEventHandler);
            $eventHandlerSynchronousInputChannel = $serviceConfiguration->isModulePackageEnabled(ModulePackageList::ASYNCHRONOUS_PACKAGE) ? $this->asynchronousModule->getSynchronousChannelFor($eventHandlerTriggeringInputChannel, $handlerAttribute->getEndpointId()) : $eventHandlerTriggeringInputChannel;

            $this->projectionSetupConfigurations[$projectionAttribute->getName()] = $projectionConfiguration->withProjectionEventHandler(
                ModellingHandlerModule::getNamedMessageChannelForEventHandler($projectionEventHandler, $interfaceToCallRegistry),
                $projectionEventHandler->getClassName(),
                $projectionEventHandler->getMethodName(),
                $eventHandlerSynchronousInputChannel
            );
            if (array_key_exists($projectionAttribute->getName(), $projectionRunningConfigurations)) {
                $projectionRunningConfiguration = $projectionRunningConfigurations[$projectionAttribute->getName()];
                $this->projectionSetupConfigurations[$projectionAttribute->getName()] = $this->projectionSetupConfigurations[$projectionAttribute->getName()]
                    ->withPolling($projectionRunningConfiguration->isPolling());
            }
        }

        $moduleReferenceSearchService->store(EventMapper::class, $this->eventMapper);
        $moduleReferenceSearchService->store(AggregateStreamMapping::class, $this->aggregateToStreamMapping);
        $moduleReferenceSearchService->store(AggregateTypeMapping::class, $this->aggregateTypeMapping);

        foreach ($this->projectionSetupConfigurations as $index => $projectionSetupConfiguration) {
            $projectionRunningConfiguration = ProjectionRunningConfiguration::createEventDriven($projectionSetupConfiguration->getProjectionName());
            if (array_key_exists($projectionSetupConfiguration->getProjectionName(), $projectionRunningConfigurations)) {
                $projectionRunningConfiguration = $projectionRunningConfigurations[$projectionSetupConfiguration->getProjectionName()];
            }

            $projectionSetupConfiguration = $projectionSetupConfiguration
                ->withOptions(
                    array_merge(
                        $projectionSetupConfiguration->getProjectionOptions(),
                        $projectionRunningConfiguration->getOptions()
                    )
                );

            /** Our main entrypoint for projection execution */
            $messagingConfiguration->registerMessageHandler(
                (new ProjectionExecutorBuilder($projectionSetupConfiguration, $this->projectionSetupConfigurations, 'execute'))
                    ->withEndpointId($projectionSetupConfiguration->getProjectionEndpointId())
                    ->withInputChannelName($projectionSetupConfiguration->getProjectionInputChannel())
            );

            if ($projectionRunningConfiguration->isPolling()) {
                $messagingConfiguration->registerConsumer(
                    InboundChannelAdapterBuilder::createWithDirectObject(
                        $projectionSetupConfiguration->getProjectionInputChannel(),
                        new ProjectionChannelAdapter(),
                        $interfaceToCallRegistry->getFor(ProjectionChannelAdapter::class, 'run')
                    )
                        ->withEndpointId($projectionSetupConfiguration->getProjectionName())
                );

                continue;
            }

            /** Projection will be called sync or async triggered by Event Bus. In that case we need to connect them to event related channels */
            foreach ($projectionSetupConfiguration->getProjectionEventHandlerConfigurations() as $eventHandlerConfiguration) {
                $messagingConfiguration->registerMessageHandler(
                    BridgeBuilder::create()
                        ->withInputChannelName($eventHandlerConfiguration->getEventBusRoutingKey())
                        ->withOutputMessageChannel($projectionSetupConfiguration->getProjectionInputChannel())
                );

                if ($serviceConfiguration->isModulePackageEnabled(ModulePackageList::ASYNCHRONOUS_PACKAGE) && $projectionSetupConfiguration->isAsynchronous()) {
                    $messagingConfiguration->registerAsynchronousEndpoint(
                        $projectionSetupConfiguration->getAsynchronousChannelName(),
                        $projectionSetupConfiguration->getProjectionEndpointId()
                    );
                }
            }
        }
    }
}
