<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\AggregateType;
use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ModuleAnnotation;
use Ecotone\Api\PropagateHeaders;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\EventSourcing\AggregateStreamMapping;
use Ecotone\EventSourcing\AggregateTypeMapping;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\EventSourcingRepositoryBuilder;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\EventSourcing\ProophEventMapper;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\DefinitionHelper;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Filter\MessageFilterBuilder;
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
    private function __construct(private AggregateStreamMapping $aggregateToStreamMapping, private AggregateTypeMapping $aggregateTypeMapping)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
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

        return new self(AggregateStreamMapping::createWith($aggregateToStreamMapping), AggregateTypeMapping::createWith($aggregateTypeMapping));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $extensionObjects, EventSourcingConfiguration::createWithDefaults());
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        $messagingConfiguration->registerServiceDefinition(EventSourcingConfiguration::class, DefinitionHelper::buildDefinitionFromInstance($eventSourcingConfiguration));
        $messagingConfiguration->registerServiceDefinition(
            EventStreamTableManager::class,
            new Definition(EventStreamTableManager::class, [
                $eventSourcingConfiguration->getEventStreamTableName(),
                true,
                $dbalConfiguration->isAutomaticTableInitializationEnabled(),
            ])
        );

        $messagingConfiguration->registerServiceDefinition(ProophEventMapper::class, Definition::createFor(ProophEventMapper::class, [Reference::to(EventMapper::class)]));
        $moduleReferenceSearchService->store(AggregateStreamMapping::class, $this->aggregateToStreamMapping);
        $moduleReferenceSearchService->store(AggregateTypeMapping::class, $this->aggregateTypeMapping);

        $messagingConfiguration->registerServiceDefinition(LazyProophEventStore::class, new Definition(LazyProophEventStore::class, [
            new Reference(EventSourcingConfiguration::class),
            new Reference(ProophEventMapper::class),
            new Reference($eventSourcingConfiguration->getConnectionReferenceName(), ContainerImplementation::NULL_ON_INVALID_REFERENCE),
            Reference::to(EventStreamTableManager::class),
        ]));

        // Register PdoStreamTableNameProvider as an alias to LazyProophEventStore
        $messagingConfiguration->registerServiceDefinition(
            PdoStreamTableNameProvider::class,
            Reference::to(LazyProophEventStore::class)
        );

        $this->registerEventStore($messagingConfiguration, $eventSourcingConfiguration);
        $this->registerEventStreamEmitter($messagingConfiguration, $eventSourcingConfiguration);
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [
            ...$this->buildEventSourcingRepositoryBuilder($serviceExtensions),
            new DbalTableManagerReference(EventStreamTableManager::class),
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
            new Reference(ProophEventMapper::class),
            new Reference($eventSourcingConfiguration->getConnectionReferenceName(), ContainerImplementation::NULL_ON_INVALID_REFERENCE),
            Reference::to(EventStreamTableManager::class),
        ]));

        $eventStoreHandler = EventStoreBuilder::create('appendTo', [HeaderBuilder::create('streamName', 'ecotone.eventSourcing.eventStore.streamName'), PayloadBuilder::create('streamEvents')], $eventSourcingConfiguration, $eventStoreReference)
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
                ->chain(new Definition(StreamNameMapper::class))
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
