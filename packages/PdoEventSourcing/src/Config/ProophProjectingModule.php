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
use Ecotone\EventSourcing\Projecting\AggregateIdPartitionProviderBuilder;
use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorageBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSourceBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSourceBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreMultiStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\Config\StreamFilterRegistryModule;
use Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter;
use Ecotone\Projecting\StreamFilter;

#[ModuleAnnotation]
class ProophProjectingModule implements AnnotationModule
{
    /**
     * @param ProjectionComponentBuilder[] $extensions
     * @param string[] $projectionNames
     */
    public function __construct(
        private array $extensions,
        private array $projectionNames,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $allStreamFilters = StreamFilterRegistryModule::collectStreamFilters($annotationRegistrationService, $interfaceToCallRegistry);

        $extensions = self::resolveConfigs($annotationRegistrationService, $allStreamFilters);

        $projectionNames = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(ProjectionV2::class) as $projectionClassName) {
            $projectionAttribute = $annotationRegistrationService->getAttributeForClass($projectionClassName, ProjectionV2::class);
            $projectionNames[] = $projectionAttribute->name;
        }

        return new self(
            $extensions,
            $projectionNames,
        );
    }

    /**
     * @param array<string, StreamFilter[]> $allStreamFilters
     * @return list<ProjectionComponentBuilder>
     */
    private static function resolveConfigs(
        AnnotationFinder $annotationRegistrationService,
        array $allStreamFilters,
    ): array {
        $extensions = [];
        $partitionProviders = [];

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

            $sources = [];
            foreach ($streamFilters as $streamFilter) {
                if ($isPartitioned && ! $streamFilter->aggregateType) {
                    throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
                }
                if ($isPartitioned) {
                    $sourceIdentifier = $streamFilter->streamName . '.' . $streamFilter->aggregateType;
                    $sources[$sourceIdentifier] = new EventStoreAggregateStreamSourceBuilder(
                        $projectionName,
                        $streamFilter,
                    );
                    if (! isset($partitionProviders[$projectionName])) {
                        $partitionProviders[$projectionName] = new AggregateIdPartitionProviderBuilder($projectionName);
                    }
                } else {
                    $sources[$streamFilter->streamName] = new EventStoreGlobalStreamSourceBuilder(
                        $streamFilter,
                        [$projectionName]
                    );
                }
            }
            if (count($sources) > 1) {
                $extensions[] = new EventStoreMultiStreamSourceBuilder(
                    $sources,
                    [$projectionName],
                );
            } else {
                $extensions[] = current($sources);
            }
            $extensions = [...$extensions, ...array_values($partitionProviders)];
        }

        return $extensions;
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        $messagingConfiguration->registerServiceDefinition(
            ProjectionStateTableManager::class,
            new Definition(ProjectionStateTableManager::class, [
                ProjectionStateTableManager::DEFAULT_TABLE_NAME,
                $this->projectionNames !== [],
                $dbalConfiguration->isAutomaticTableInitializationEnabled(),
            ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $serviceExtensions, EventSourcingConfiguration::createWithDefaults());
        $extensions = $eventSourcingConfiguration->isInMemory() ? [] : [...$this->extensions];

        foreach ($serviceExtensions as $extensionObject) {
            if (! ($extensionObject instanceof EventStreamingChannelAdapter)) {
                continue;
            }

            $projectionName = $extensionObject->getProjectionName();
            $extensions[] = new EventStoreGlobalStreamSourceBuilder(
                new StreamFilter($extensionObject->fromStream),
                [$projectionName]
            );
        }

        $extensions[] = new DbalTableManagerReference(ProjectionStateTableManager::class);

        $eventStreamingChannelAdapters = ExtensionObjectResolver::resolve(EventStreamingChannelAdapter::class, $serviceExtensions);

        if (($this->projectionNames || $eventStreamingChannelAdapters) && ! $eventSourcingConfiguration->isInMemory()) {
            $projectionNames = array_unique([...$this->projectionNames, ...array_map(fn (EventStreamingChannelAdapter $adapter) => $adapter->getProjectionName(), $eventStreamingChannelAdapters)]);

            $extensions[] = new DbalProjectionStateStorageBuilder($projectionNames);
        }

        return $extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::EVENT_SOURCING_PACKAGE;
    }
}
