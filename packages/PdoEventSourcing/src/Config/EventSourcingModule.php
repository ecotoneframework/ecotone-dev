<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\PropagateHeaders;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\EventSourcing\AggregateStreamMapping;
use Ecotone\EventSourcing\AggregateTypeMapping;
use Ecotone\EventSourcing\Console\TagBackfillConsoleCommand;
use Ecotone\EventSourcing\Console\TagVerifySchemaConsoleCommand;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\Tag\DbalTagCollaborator;
use Ecotone\EventSourcing\Dbal\Tag\EnterpriseDbalTagCollaborator;
use Ecotone\EventSourcing\Dbal\Tag\OpenCoreDbalTagCollaborator;
use Ecotone\EventSourcing\Dbal\Tag\TagSchemaVerifier;
use Ecotone\EventSourcing\EventSerializer;
use Ecotone\EventSourcing\EventSourcingRepositoryBuilder;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\AppendStrategy\AppendStrategy;
use Ecotone\EventSourcing\EventStore\GuardedTagBump;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\EventSourcing\EventStore\Tag\InMemoryTagCollaborator;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\Projecting\ProjectionInvariantGuard;
use Ecotone\EventSourcing\SerializingEventStore;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\EventSourcing\Tagging\AggregateCounterTags;
use Ecotone\EventSourcing\Tagging\Config\DynamicConsistencyBoundary;
use Ecotone\EventSourcing\Tagging\EventTagRegistryBuilder;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Filter\MessageFilterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Router\RouterProcessorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;
use Ecotone\Modelling\Config\MessageBusChannel;
use Ecotone\Projecting\ProjectingHeaders;
use Symfony\Component\Uid\Uuid;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class EventSourcingModule extends NoExternalConfigurationModule
{
    /**
     * @param array<class-string, Stream> $streamAttributes
     * @param array<string, string> $projectionStreamMapping
     * @param class-string[] $stateStoredCountedAggregateClasses
     */
    private function __construct(
        private AggregateStreamMapping $aggregateToStreamMapping,
        private AggregateTypeMapping $aggregateTypeMapping,
        private array $streamAttributes,
        private array $projectionStreamMapping,
        private bool $declaresEventTagsOrCountedAggregates,
        private array $stateStoredCountedAggregateClasses,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $aggregateToStreamMapping = [];
        $streamAttributes = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(Stream::class) as $classWithCustomStream) {
            /** @var Stream $attribute */
            $attribute = $annotationRegistrationService->getAttributeForClass($classWithCustomStream, Stream::class);

            $aggregateToStreamMapping[$classWithCustomStream] = $attribute->getName();
            $streamAttributes[$classWithCustomStream] = $attribute;
        }

        $aggregateTypeMapping = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(AggregateType::class) as $aggregateWithCustomType) {
            $attribute = $annotationRegistrationService->getAttributeForClass($aggregateWithCustomType, AggregateType::class);

            $aggregateTypeMapping[$aggregateWithCustomType] = $attribute->getName();
        }

        $projectionStreamMapping = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(Projection::class) as $projectionClassName) {
            $projectionAttribute = $annotationRegistrationService->getAttributeForClass($projectionClassName, Projection::class);

            $projectionStreamMapping[$projectionAttribute->name] = $aggregateToStreamMapping[$projectionClassName] ?? StreamTableRegistry::DEFAULT_STREAM;
        }

        $countedAggregateClasses = array_keys(AggregateCounterTags::declaredAggregateTypesOfCountedAggregatesIn($annotationRegistrationService));

        return new self(
            AggregateStreamMapping::createWith($aggregateToStreamMapping),
            AggregateTypeMapping::createWith($aggregateTypeMapping),
            $streamAttributes,
            $projectionStreamMapping,
            EventTagRegistryBuilder::buildRawDefinitions($annotationRegistrationService) !== [] || $countedAggregateClasses !== [],
            array_values(array_diff($countedAggregateClasses, $annotationRegistrationService->findAnnotatedClasses(EventSourcingAggregate::class))),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $extensionObjects, EventSourcingConfiguration::createWithDefaults());
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createDefaultFor($extensionObjects));
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix($serviceConfiguration);
        $dynamicConsistencyBoundary = DynamicConsistencyBoundary::resolveFrom($extensionObjects);

        if ($dynamicConsistencyBoundary->isEnabled()) {
            CrossConnectionAggregateBoundaryGuard::assertEveryStateStoredAggregateSavesOnTheEventStoreConnection($this->stateStoredCountedAggregateClasses, $eventSourcingConfiguration, $dbalConfiguration);
        }

        $messagingConfiguration->registerServiceDefinition(EventSourcingConfiguration::class, DefinitionHelper::buildDefinitionFromInstance($eventSourcingConfiguration));

        $streamTableRegistry = $this->buildStreamTableRegistry($eventSourcingConfiguration);
        $messagingConfiguration->registerServiceDefinition(StreamTableRegistry::class, $streamTableRegistry->getDefinition());

        $messagingConfiguration->registerServiceDefinition(
            EventStreamTableManager::class,
            new Definition(EventStreamTableManager::class, [
                $streamTableRegistry->tablesFor($eventSourcingConfiguration->getConnectionReferenceName()),
                true,
                $dbalConfiguration->isAutomaticTableInitializationEnabled(),
                $consoleInvocationPrefix,
            ])
        );

        $messagingConfiguration->registerServiceDefinition(
            MissingEventStreamTable::class,
            new Definition(MissingEventStreamTable::class, [$consoleInvocationPrefix])
        );

        $messagingConfiguration->registerServiceDefinition(
            TagTableManager::class,
            new Definition(TagTableManager::class, [
                $this->declaresEventTagsOrCountedAggregates && $dynamicConsistencyBoundary->isEnabled(),
                $dbalConfiguration->isAutomaticTableInitializationEnabled(),
                $consoleInvocationPrefix,
            ])
        );

        $moduleReferenceSearchService->store(AggregateStreamMapping::class, $this->aggregateToStreamMapping);
        $moduleReferenceSearchService->store(AggregateTypeMapping::class, $this->aggregateTypeMapping);

        $this->registerEventStoreInstance($messagingConfiguration, $eventSourcingConfiguration, $streamTableRegistry, $dbalConfiguration, $consoleInvocationPrefix, $interfaceToCallRegistry, $dynamicConsistencyBoundary);
        $this->registerEventStore($messagingConfiguration, $eventSourcingConfiguration);
        $this->registerEventStreamEmitter($messagingConfiguration, $eventSourcingConfiguration);
    }

    private function buildStreamTableRegistry(EventSourcingConfiguration $eventSourcingConfiguration): StreamTableRegistry
    {
        $streams = [
            StreamTableRegistry::DEFAULT_STREAM => [
                'table' => $eventSourcingConfiguration->getEventStreamTableName(),
                'connection' => $eventSourcingConfiguration->getConnectionReferenceName(),
            ],
        ];

        foreach ($this->streamAttributes as $attribute) {
            $streams[$attribute->getName()] = [
                'table' => $attribute->getTableName(),
                'connection' => $attribute->getConnectionReferenceName(),
            ];
        }

        return StreamTableRegistry::createWith($streams, $eventSourcingConfiguration->getConnectionReferenceName());
    }

    private function registerEventStoreInstance(
        Configuration $messagingConfiguration,
        EventSourcingConfiguration $eventSourcingConfiguration,
        StreamTableRegistry $streamTableRegistry,
        DbalConfiguration $dbalConfiguration,
        ?string $consoleInvocationPrefix,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        DynamicConsistencyBoundary $dynamicConsistencyBoundary,
    ): void {
        $messagingConfiguration->registerServiceDefinition(
            EventSerializer::class,
            new Definition(EventSerializer::class, [
                new Reference(ConversionService::REFERENCE_NAME),
                new Reference(EventMapper::class),
            ])
        );

        $dynamicConsistencyBoundary->registerServicesForInMemoryStore($messagingConfiguration);
        $this->registerDbalTagCollaborator($messagingConfiguration, $dynamicConsistencyBoundary);

        if ($eventSourcingConfiguration->isInMemory()) {
            $messagingConfiguration->registerServiceDefinition(
                InMemoryEventStore::class,
                new Definition(InMemoryEventStore::class, [Reference::to(AppendStrategy::class), Reference::to(InMemoryTagCollaborator::class)])
            );
            $messagingConfiguration->registerServiceDefinition(
                EventStoreReference::EVENT_STORE_INSTANCE,
                new Definition(SerializingEventStore::class, [
                    new Reference(InMemoryEventStore::class),
                    new Reference(EventSerializer::class),
                ])
            );
            $messagingConfiguration->registerServiceDefinition(
                GuardedTagBump::class,
                new Reference(InMemoryEventStore::class),
            );

            return;
        }

        $connectionFactories = [];
        foreach ($this->connectionReferenceNames($streamTableRegistry, $eventSourcingConfiguration) as $connectionReferenceName) {
            $connectionFactories[$connectionReferenceName] = new Reference($connectionReferenceName, ContainerImplementation::NULL_ON_INVALID_REFERENCE);
        }

        $messagingConfiguration->registerServiceDefinition(
            DbalEventStore::class,
            new Definition(DbalEventStore::class, [
                new Reference(StreamTableRegistry::class),
                $connectionFactories,
                new Reference(EventSerializer::class),
                $eventSourcingConfiguration->getLoadBatchSize(),
                $eventSourcingConfiguration->isInitializedOnStart() && $dbalConfiguration->isAutomaticTableInitializationEnabled(),
                new Reference(DbalTagCollaborator::class),
                new Reference(ProjectionInvariantGuard::class),
                new Reference(AppendStrategy::class),
                new Reference(MissingEventStreamTable::class),
                $consoleInvocationPrefix,
            ])
        );
        $messagingConfiguration->registerServiceDefinition(
            EventStoreReference::EVENT_STORE_INSTANCE,
            new Reference(DbalEventStore::class)
        );
        $messagingConfiguration->registerServiceDefinition(
            GuardedTagBump::class,
            new Reference(DbalEventStore::class)
        );

        $messagingConfiguration->registerServiceDefinition(
            TagBackfillConsoleCommand::class,
            new Definition(TagBackfillConsoleCommand::class, [new Reference(DbalEventStore::class)])
        );
        $messagingConfiguration->registerServiceDefinition(
            TagSchemaVerifier::class,
            new Definition(TagSchemaVerifier::class, [])
        );
        $messagingConfiguration->registerServiceDefinition(
            TagVerifySchemaConsoleCommand::class,
            new Definition(TagVerifySchemaConsoleCommand::class, [
                new Reference($eventSourcingConfiguration->getConnectionReferenceName()),
                new Reference(TagSchemaVerifier::class),
                $dynamicConsistencyBoundary->isEnabled(),
            ])
        );

        $this->registerConsoleCommand(
            'backfill',
            'ecotone:event-store:backfill-tags',
            TagBackfillConsoleCommand::class,
            $messagingConfiguration,
            $interfaceToCallRegistry,
            'Indexes #[EventTag] rows for events recorded before their class declared its current tags'
        );
        $this->registerConsoleCommand(
            'verify',
            'ecotone:event-store:verify-schema',
            TagVerifySchemaConsoleCommand::class,
            $messagingConfiguration,
            $interfaceToCallRegistry,
            'Checks the tag tables\' primary keys/collation and given legacy streams\' aggregate NOT NULL constraints'
        );
    }

    private function registerDbalTagCollaborator(Configuration $messagingConfiguration, DynamicConsistencyBoundary $dynamicConsistencyBoundary): void
    {
        $messagingConfiguration->registerServiceDefinition(
            OpenCoreDbalTagCollaborator::class,
            new Definition(OpenCoreDbalTagCollaborator::class),
        );
        $messagingConfiguration->registerServiceDefinition(
            EnterpriseDbalTagCollaborator::class,
            new Definition(EnterpriseDbalTagCollaborator::class, [Reference::to(TagResolver::class), Reference::to(LoggingGateway::class)]),
        );
        $messagingConfiguration->registerServiceDefinition(
            DbalTagCollaborator::class,
            $dynamicConsistencyBoundary->definitionFor(DbalTagCollaborator::class, OpenCoreDbalTagCollaborator::class, EnterpriseDbalTagCollaborator::class),
        );
    }

    private function registerConsoleCommand(
        string $methodName,
        string $commandName,
        string $className,
        Configuration $configuration,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        string $description = ''
    ): void {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForReference(
            new Reference($className),
            new InterfaceToCallReference($className, $methodName),
            $commandName,
            true,
            $interfaceToCallRegistry,
            $description
        );

        $configuration
            ->registerMessageHandler($messageHandlerBuilder)
            ->registerConsoleCommand($oneTimeCommandConfiguration);
    }

    /**
     * @return array<string>
     */
    private function connectionReferenceNames(StreamTableRegistry $streamTableRegistry, EventSourcingConfiguration $eventSourcingConfiguration): array
    {
        $connectionReferenceNames = [$eventSourcingConfiguration->getConnectionReferenceName()];
        foreach ($streamTableRegistry->declaredStreamNames() as $streamName) {
            $connectionReferenceName = $streamTableRegistry->connectionReferenceFor($streamName);
            if (! in_array($connectionReferenceName, $connectionReferenceNames, true)) {
                $connectionReferenceNames[] = $connectionReferenceName;
            }
        }

        return $connectionReferenceNames;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [
            ...$this->buildEventSourcingRepositoryBuilder($serviceExtensions),
            new DbalTableManagerReference(EventStreamTableManager::class),
            new DbalTableManagerReference(TagTableManager::class),
        ];
    }

    private function buildEventSourcingRepositoryBuilder(array $serviceExtensions): array
    {
        foreach ($serviceExtensions as $serviceExtension) {
            if ($serviceExtension instanceof EventSourcingRepositoryBuilder) {
                return [];
            }
        }

        $eventSourcingRepositories = [];
        foreach ($serviceExtensions as $extensionObject) {
            if ($extensionObject instanceof EventSourcingConfiguration) {
                $eventSourcingRepositories[] = EventSourcingRepositoryBuilder::create();
            }
        }

        return $eventSourcingRepositories ?: [EventSourcingRepositoryBuilder::create()];
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
            [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents'), HeaderBuilder::createOptional('appendCondition', 'ecotone.eventSourcing.eventStore.appendCondition')],
            [GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), GatewayPayloadBuilder::create('streamEvents'), GatewayHeaderBuilder::create('appendCondition', 'ecotone.eventSourcing.eventStore.appendCondition')],
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

        $this->registerEventStoreAction(
            'loadAggregateEvents',
            [
                HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'),
                HeaderBuilder::createOptional('aggregateType', 'ecotone.eventSourcing.eventStore.aggregateType'),
                HeaderBuilder::create('aggregateId', 'ecotone.eventSourcing.eventStore.aggregateId'),
                HeaderBuilder::create('fromVersion', 'ecotone.eventSourcing.eventStore.fromVersion'),
                HeaderBuilder::createOptional('count', 'ecotone.eventSourcing.eventStore.count'),
                HeaderBuilder::create('eventNames', 'ecotone.eventSourcing.eventStore.eventNames'),
                HeaderBuilder::create('deserialize', 'ecotone.eventSourcing.eventStore.deserialize'),
            ],
            [
                GatewayHeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'),
                GatewayHeaderBuilder::create('aggregateType', 'ecotone.eventSourcing.eventStore.aggregateType'),
                GatewayHeaderBuilder::create('aggregateId', 'ecotone.eventSourcing.eventStore.aggregateId'),
                GatewayHeaderBuilder::create('fromVersion', 'ecotone.eventSourcing.eventStore.fromVersion'),
                GatewayHeaderBuilder::create('count', 'ecotone.eventSourcing.eventStore.count'),
                GatewayHeaderBuilder::create('eventNames', 'ecotone.eventSourcing.eventStore.eventNames'),
                GatewayHeaderBuilder::create('deserialize', 'ecotone.eventSourcing.eventStore.deserialize'),
            ],
            $eventSourcingConfiguration,
            $configuration
        );

        $this->registerEventStoreAction(
            'loadByCriteria',
            [PayloadBuilder::create('criteria')],
            [GatewayPayloadBuilder::create('criteria')],
            $eventSourcingConfiguration,
            $configuration
        );
    }

    private function registerEventStoreAction(string $methodName, array $endpointConverters, array $gatewayConverters, EventSourcingConfiguration $eventSourcingConfiguration, Configuration $configuration): void
    {
        $messageHandlerBuilder = EventStoreBuilder::create($methodName, $endpointConverters, $eventSourcingConfiguration, new Reference(EventStoreReference::EVENT_STORE_INSTANCE));
        $configuration->registerMessageHandler($messageHandlerBuilder);

        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create($eventSourcingConfiguration->getEventStoreReferenceName(), EventStore::class, $methodName, $messageHandlerBuilder->getInputMessageChannelName())
                ->withParameterConverters($gatewayConverters)
        );
    }

    private function registerEventStreamEmitter(Configuration $configuration, EventSourcingConfiguration $eventSourcingConfiguration): void
    {
        $eventStoreHandler = EventStoreBuilder::create('appendTo', [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents'), HeaderBuilder::createOptional('appendCondition', 'ecotone.eventSourcing.eventStore.appendCondition')], $eventSourcingConfiguration, new Reference(EventStoreReference::EVENT_STORE_INSTANCE))
            ->withInputChannelName(Uuid::v7()->toRfc4122())
        ;
        $configuration->registerMessageHandler($eventStoreHandler);

        $eventBusChannelName = Uuid::v7()->toRfc4122();
        $configuration->registerMessageHandler(
            SplitterBuilder::createMessagePayloadSplitter()
                ->withInputChannelName($eventBusChannelName)
                ->withOutputMessageChannel(MessageBusChannel::EVENT_CHANNEL_NAME_BY_OBJECT)
        );

        $linkingRouterHandler =
            MessageProcessorActivatorBuilder::create()
                ->withInputChannelName(Uuid::v7()->toRfc4122())
                ->chain(new Definition(DeclaredStreamValidator::class, [new Reference(StreamTableRegistry::class)]))
                ->chain(MessageFilterBuilder::createNotBoolHeaderFilter(ProjectingHeaders::PROJECTION_LIVE, false))
                ->chain(RouterProcessorBuilder::createRecipientListRouter([
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
            MessageProcessorActivatorBuilder::create()
                ->withInputChannelName(Uuid::v7()->toRfc4122())
                ->chain(new Definition(StreamNameMapper::class, [$this->projectionStreamMapping]))
                ->chain(MessageFilterBuilder::createNotBoolHeaderFilter(ProjectingHeaders::PROJECTION_LIVE))
                ->chain(RouterProcessorBuilder::createRecipientListRouter([
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
}
