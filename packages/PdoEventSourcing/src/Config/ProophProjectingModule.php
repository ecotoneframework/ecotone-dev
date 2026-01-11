<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use function count;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Database\DbalTableManagerReference;
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
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Config\StreamFilterRegistryModule;
use Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter;
use Ecotone\Projecting\PartitionProviderReference;
use Ecotone\Projecting\ProjectionStateStorageReference;
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
     */
    public function __construct(
        private array $projectionNames,
        private array $partitionedProjectionNames,
        private array $globalStreamProjectionNames,
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

        return new self(
            $projectionNames,
            $partitionedProjectionNames,
            $globalStreamProjectionNames,
        );
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

            if ($isPartitioned && count($streamFilters) > 1) {
                throw ConfigurationException::create(
                    "Partitioned projection {$projectionName} cannot declare multiple streams. Use a single aggregate stream or remove #[Partitioned]."
                );
            }

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

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration
            || $extensionObject instanceof EventSourcingConfiguration
            || $extensionObject instanceof EventStreamingChannelAdapter;
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
