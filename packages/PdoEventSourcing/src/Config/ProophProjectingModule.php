<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;
use Ecotone\EventSourcing\Database\ProjectionStateTableManager;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\EventSourcing\Projecting\AggregateIdPartitionProvider;
use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorage;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSource;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Config\StreamFilterRegistryModule;
use Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter;
use Ecotone\Projecting\PartitionProviderReference;
use Ecotone\Projecting\ProjectionRegistry;
use Ecotone\Projecting\ProjectionStateStorageReference;
use Ecotone\Projecting\ProjectionV2StateHandler;
use Ecotone\Projecting\StreamFilter;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Projecting\StreamSourceReference;
use Enqueue\Dbal\DbalConnectionFactory;

use function in_array;

#[ModuleAnnotation]
class ProophProjectingModule implements AnnotationModule
{
    /**
     * @param string[] $projectionNames
     * @param string[] $partitionedProjectionNames
     * @param string[] $globalStreamProjectionNames
     * @param array<array{className: string, methodName: string, projectionName: string, partitionKeyParam: ?string}> $v2StateGateways
     */
    public function __construct(
        private array $projectionNames,
        private array $partitionedProjectionNames,
        private array $globalStreamProjectionNames,
        private array $v2StateGateways = [],
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $allStreamFilters = StreamFilterRegistryModule::collectStreamFilters($annotationRegistrationService, $interfaceToCallRegistry);

        [$partitionedProjectionNames, $globalStreamProjectionNames] = self::resolveProjectionTypes($annotationRegistrationService, $allStreamFilters);

        $projectionNames = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(ProjectionV2::class) as $projectionClassName) {
            $projectionAttribute = $annotationRegistrationService->getAttributeForClass($projectionClassName, ProjectionV2::class);
            $projectionNames[] = $projectionAttribute->name;
        }

        $v2StateGateways = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(ProjectionStateGateway::class) as $gatewayAnnotation) {
            /** @var ProjectionStateGateway $attribute */
            $attribute = $gatewayAnnotation->getAnnotationForMethod();
            if (! in_array($attribute->getProjectionName(), $projectionNames, true)) {
                continue;
            }

            $interfaceToCall = $interfaceToCallRegistry->getFor(
                $gatewayAnnotation->getClassName(),
                $gatewayAnnotation->getMethodName()
            );
            $aggregateIdParam = null;
            foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
                $aggregateIdParam = $parameter->getName();
                break;
            }

            $streamName = null;
            $aggregateType = null;
            if ($aggregateIdParam !== null) {
                $streamFilter = self::resolveStreamFilterForGateway(
                    $annotationRegistrationService,
                    $gatewayAnnotation->getClassName(),
                    $gatewayAnnotation->getMethodName(),
                    $attribute->getProjectionName(),
                    $allStreamFilters,
                );
                $streamName = $streamFilter->streamName;
                $aggregateType = $streamFilter->aggregateType;
            }

            $v2StateGateways[] = [
                'className' => $gatewayAnnotation->getClassName(),
                'methodName' => $gatewayAnnotation->getMethodName(),
                'projectionName' => $attribute->getProjectionName(),
                'aggregateIdParam' => $aggregateIdParam,
                'streamName' => $streamName,
                'aggregateType' => $aggregateType,
            ];
        }

        return new self(
            $projectionNames,
            $partitionedProjectionNames,
            $globalStreamProjectionNames,
            $v2StateGateways,
        );
    }

    /**
     * @param array<string, StreamFilter[]> $allStreamFilters
     */
    private static function resolveStreamFilterForGateway(
        AnnotationFinder $annotationFinder,
        string $gatewayClassName,
        string $gatewayMethodName,
        string $projectionName,
        array $allStreamFilters,
    ): StreamFilter {
        $methodAnnotations = $annotationFinder->getAnnotationsForMethod($gatewayClassName, $gatewayMethodName);
        foreach ($methodAnnotations as $annotation) {
            if ($annotation instanceof FromAggregateStream) {
                return StreamFilterRegistryModule::resolveFromAggregateStream($annotationFinder, $annotation, $projectionName);
            }
        }

        $streamFilters = $allStreamFilters[$projectionName] ?? [];
        $partitionedFilters = array_filter($streamFilters, fn (StreamFilter $f) => $f->aggregateType !== null);

        if (count($partitionedFilters) === 0) {
            throw ConfigurationException::create(
                "#[ProjectionStateGateway('{$projectionName}')] on {$gatewayClassName}::{$gatewayMethodName}() has a parameter for aggregate identifier, "
                . "but projection '{$projectionName}' has no streams with aggregate type. Use #[FromStream] with aggregateType or #[FromAggregateStream] on the projection."
            );
        }

        if (count($partitionedFilters) > 1) {
            throw ConfigurationException::create(
                "#[ProjectionStateGateway('{$projectionName}')] on {$gatewayClassName}::{$gatewayMethodName}() cannot resolve which stream to use for partition key "
                . "because projection '{$projectionName}' has multiple streams. Add #[FromAggregateStream(AggregateClass::class)] on the gateway method to disambiguate."
            );
        }

        return reset($partitionedFilters);
    }

    /**
     * @param array<string, StreamFilter[]> $allStreamFilters
     * @return array{list<string>, list<string>}
     */
    private static function resolveProjectionTypes(
        AnnotationFinder $annotationRegistrationService,
        array $allStreamFilters,
    ): array {
        $partitionedProjectionNames = [];
        $globalStreamProjectionNames = [];

        foreach ($allStreamFilters as $projectionName => $streamFilters) {
            $projectionClass = null;
            foreach ($annotationRegistrationService->findAnnotatedClasses(ProjectionV2::class) as $classname) {
                $projectionAttribute = $annotationRegistrationService->getAttributeForClass($classname, ProjectionV2::class);
                if ($projectionAttribute->name === $projectionName) {
                    $projectionClass = $classname;
                    break;
                }
            }

            if ($projectionClass === null) {
                continue;
            }

            $partitionedAttribute = $annotationRegistrationService->findAttributeForClass($projectionClass, Partitioned::class);
            $isPartitioned = $partitionedAttribute !== null;

            foreach ($streamFilters as $streamFilter) {
                if ($isPartitioned && ! $streamFilter->aggregateType) {
                    throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
                }
                if ($isPartitioned) {
                    if (! in_array($projectionName, $partitionedProjectionNames, true)) {
                        $partitionedProjectionNames[] = $projectionName;
                    }
                } else {
                    if (! in_array($projectionName, $globalStreamProjectionNames, true)) {
                        $globalStreamProjectionNames[] = $projectionName;
                    }
                }
            }
        }

        return [$partitionedProjectionNames, $globalStreamProjectionNames];
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $extensionObjects, EventSourcingConfiguration::createWithDefaults());
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);

        if (! empty($multiTenantConfigurations) && ! empty($this->projectionNames) && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Using Multi-Tenant connection with ProjectionV2 requires Ecotone Enterprise licence.');
        }

        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof EventStreamingChannelAdapter) {
                $this->globalStreamProjectionNames[] = $extensionObject->getProjectionName();
            }
        }

        $hasProjections = $this->projectionNames !== [] || $this->globalStreamProjectionNames !== [];
        $messagingConfiguration->registerServiceDefinition(
            ProjectionStateTableManager::class,
            new Definition(ProjectionStateTableManager::class, [
                ProjectionStateTableManager::DEFAULT_TABLE_NAME,
                $hasProjections,
                $dbalConfiguration->isAutomaticTableInitializationEnabled(),
            ])
        );

        if ($this->partitionedProjectionNames !== [] && ! $eventSourcingConfiguration->isInMemory()) {
            $messagingConfiguration->registerServiceDefinition(
                AggregateIdPartitionProvider::class,
                new Definition(AggregateIdPartitionProvider::class, [
                    new Reference(DbalConnectionFactory::class),
                    new Reference(PdoStreamTableNameProvider::class),
                    $this->partitionedProjectionNames,
                ])
            );
        }

        if (! $eventSourcingConfiguration->isInMemory()) {
            $this->registerGlobalStreamSource($messagingConfiguration);
            $this->registerAggregateStreamSource($messagingConfiguration);
            $this->registerDbalProjectionStateStorage($messagingConfiguration);
        }

        $this->registerV2StateGateways($messagingConfiguration);
    }

    private function registerDbalProjectionStateStorage(Configuration $messagingConfiguration): void
    {
        $hasProjections = $this->projectionNames !== [] || $this->globalStreamProjectionNames !== [];
        if (! $hasProjections) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            DbalProjectionStateStorage::class,
            new Definition(DbalProjectionStateStorage::class, [
                new Reference(DbalConnectionFactory::class),
                new Reference(ProjectionStateTableManager::class),
            ])
        );
    }

    private function registerGlobalStreamSource(Configuration $messagingConfiguration): void
    {
        if ($this->globalStreamProjectionNames === []) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            EventStoreGlobalStreamSource::class,
            new Definition(EventStoreGlobalStreamSource::class, [
                new Reference(DbalConnectionFactory::class),
                new Reference(EcotoneClockInterface::class),
                new Reference(PdoStreamTableNameProvider::class),
                new Reference(StreamFilterRegistry::class),
                $this->globalStreamProjectionNames,
                5_000,
                new Definition(Duration::class, [60 * 1_000_000]),
            ])
        );
    }

    private function registerAggregateStreamSource(Configuration $messagingConfiguration): void
    {
        if ($this->partitionedProjectionNames === []) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            EventStoreAggregateStreamSource::class,
            new Definition(EventStoreAggregateStreamSource::class, [
                new Reference('Ecotone\EventSourcing\EventStore'),
                new Reference(StreamFilterRegistry::class),
                $this->partitionedProjectionNames,
            ])
        );
    }

    private function registerV2StateGateways(Configuration $messagingConfiguration): void
    {
        if ($this->v2StateGateways === []) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            ProjectionV2StateHandler::class,
            new Definition(ProjectionV2StateHandler::class, [
                new Reference(ProjectionRegistry::class),
            ])
        );

        $handlerChannel = 'projection_v2_get_state';

        $messagingConfiguration->registerMessageHandler(
            ServiceActivatorBuilder::create(
                ProjectionV2StateHandler::class,
                InterfaceToCallReference::create(ProjectionV2StateHandler::class, 'getProjectionState')
            )
            ->withMethodParameterConverters([
                HeaderBuilder::create('projectionName', 'ecotone.projectionV2.state.projectionName'),
                HeaderBuilder::createOptional('aggregateId', 'ecotone.projectionV2.state.aggregateId'),
                HeaderBuilder::createOptional('streamName', 'ecotone.projectionV2.state.streamName'),
                HeaderBuilder::createOptional('aggregateType', 'ecotone.projectionV2.state.aggregateType'),
            ])
            ->withInputChannelName($handlerChannel)
        );

        foreach ($this->v2StateGateways as $gateway) {
            $parameterConverters = [
                GatewayHeaderValueBuilder::create(
                    'ecotone.projectionV2.state.projectionName',
                    $gateway['projectionName']
                ),
            ];

            if ($gateway['aggregateIdParam'] !== null) {
                $parameterConverters[] = GatewayHeaderBuilder::create(
                    $gateway['aggregateIdParam'],
                    'ecotone.projectionV2.state.aggregateId'
                );
                $parameterConverters[] = GatewayHeaderValueBuilder::create(
                    'ecotone.projectionV2.state.streamName',
                    $gateway['streamName']
                );
                $parameterConverters[] = GatewayHeaderValueBuilder::create(
                    'ecotone.projectionV2.state.aggregateType',
                    $gateway['aggregateType']
                );
            }

            $messagingConfiguration->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $gateway['className'],
                    $gateway['className'],
                    $gateway['methodName'],
                    $handlerChannel
                )->withParameterConverters($parameterConverters)
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration
            || $extensionObject instanceof EventSourcingConfiguration
            || $extensionObject instanceof EventStreamingChannelAdapter
            || $extensionObject instanceof MultiTenantConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $serviceExtensions, EventSourcingConfiguration::createWithDefaults());
        $extensions = [];

        $extensions[] = new DbalTableManagerReference(ProjectionStateTableManager::class);

        $eventStreamingChannelAdapters = ExtensionObjectResolver::resolve(EventStreamingChannelAdapter::class, $serviceExtensions);

        if (($this->projectionNames || $eventStreamingChannelAdapters) && ! $eventSourcingConfiguration->isInMemory()) {
            $extensions[] = new ProjectionStateStorageReference(DbalProjectionStateStorage::class);
        }

        if ($this->partitionedProjectionNames !== [] && ! $eventSourcingConfiguration->isInMemory()) {
            $extensions[] = new PartitionProviderReference(AggregateIdPartitionProvider::class, $this->partitionedProjectionNames);
        }

        if (! $eventSourcingConfiguration->isInMemory()) {
            $globalStreamProjectionNames = array_unique([
                ...$this->globalStreamProjectionNames,
                ...array_map(fn (EventStreamingChannelAdapter $adapter) => $adapter->getProjectionName(), $eventStreamingChannelAdapters),
            ]);

            if ($globalStreamProjectionNames !== []) {
                $extensions[] = new StreamSourceReference(EventStoreGlobalStreamSource::class);
            }
            if ($this->partitionedProjectionNames !== []) {
                $extensions[] = new StreamSourceReference(EventStoreAggregateStreamSource::class);
            }
        }

        return $extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::EVENT_SOURCING_PACKAGE;
    }
}
