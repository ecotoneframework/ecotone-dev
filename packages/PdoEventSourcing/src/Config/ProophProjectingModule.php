<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\Stream;
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
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\NamedEvent;
use Ecotone\Modelling\Config\Routing\BusRoutingMapBuilder;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter;

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
        $namedEvents = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(NamedEvent::class) as $className) {
            $attribute = $annotationRegistrationService->getAttributeForClass($className, NamedEvent::class);
            $namedEvents[$className] = $attribute->getName();
        }

        $projectionEventNames = self::collectProjectionEventNames($annotationRegistrationService, $interfaceToCallRegistry, $namedEvents);

        $extensions = self::resolveConfigs($annotationRegistrationService, $projectionEventNames);

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
     * Resolve stream configurations from FromStream attributes.
     *
     * @return list<ProjectionComponentBuilder>
     */
    private static function resolveConfigs(
        AnnotationFinder $annotationRegistrationService,
        array            $projectionEventNames
    ): array {
        $extensions = [];
        $partitionProviders = [];

        foreach ($annotationRegistrationService->findAnnotatedClasses(ProjectionV2::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->getAttributeForClass($classname, ProjectionV2::class);
            $streamAttributes = [
                ...$annotationRegistrationService->getAnnotationsForClass($classname, FromStream::class),
                ...\array_map(
                    fn (FromAggregateStream $aggregateStreamAttribute) => self::resolveFromAggregateStream($annotationRegistrationService, $aggregateStreamAttribute, $projectionAttribute->name),
                    $annotationRegistrationService->getAnnotationsForClass($classname, FromAggregateStream::class)
                ),
            ];
            $partitionedAttribute = $annotationRegistrationService->findAttributeForClass($classname, Partitioned::class);

            if (empty($streamAttributes)) {
                continue;
            }

            $projectionName = $projectionAttribute->name;
            $isPartitioned = $partitionedAttribute !== null;

            // @todo: Partitioned projections cannot be declared with multiple streams because the current partition provider cannot merge partitions from multiple streams.
            if ($isPartitioned && count($streamAttributes) > 1) {
                throw ConfigurationException::create(
                    "Partitioned projection {$projectionName} cannot declare multiple streams. Use a single aggregate stream or remove #[Partitioned]."
                );
            }

            $sources = [];
            foreach ($streamAttributes as $streamAttribute) {
                if ($isPartitioned && ! $streamAttribute->aggregateType) {
                    throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
                }
                if ($isPartitioned) {
                    $sourceIdentifier = $streamAttribute->stream.'.'.$streamAttribute->aggregateType;
                    $sources[$sourceIdentifier] = new EventStoreAggregateStreamSourceBuilder(
                        $projectionName,
                        $streamAttribute->aggregateType,
                        $streamAttribute->stream,
                        $projectionEventNames[$projectionName] ?? [],
                    );
                    $partitionProviders[$streamAttribute->stream] ??= new AggregateIdPartitionProviderBuilder(
                        $projectionName,
                        $streamAttribute->aggregateType,
                        $streamAttribute->stream,
                    );
                } else {
                    $sources[$streamAttribute->stream] = new EventStoreGlobalStreamSourceBuilder(
                        $streamAttribute->stream,
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

    /**
     * Resolve stream configurations from FromAggregateStream attributes.
     */
    private static function resolveFromAggregateStream(
        AnnotationFinder $annotationRegistrationService,
        FromAggregateStream $aggregateStreamAttribute,
        string $projectionName
    ): FromStream {
        $aggregateClass = $aggregateStreamAttribute->aggregateClass;

        $eventSourcingAggregateAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, EventSourcingAggregate::class);
        if ($eventSourcingAggregateAttribute === null) {
            throw ConfigurationException::create("Class {$aggregateClass} referenced in #[AggregateStream] for projection {$projectionName} must be an EventSourcingAggregate. Add #[EventSourcingAggregate] attribute to the class.");
        }

        $streamAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, Stream::class);
        $streamName = $streamAttribute?->getName() ?? $aggregateClass;

        $aggregateTypeAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, AggregateType::class);
        $aggregateType = $aggregateTypeAttribute?->getName() ?? $aggregateClass;

        return new FromStream($streamName, $aggregateType, $aggregateStreamAttribute->eventStoreReferenceName);
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
        $extensions = [...$this->extensions];

        foreach ($serviceExtensions as $extensionObject) {
            if (! ($extensionObject instanceof EventStreamingChannelAdapter)) {
                continue;
            }

            $projectionName = $extensionObject->getProjectionName();
            $extensions[] = new EventStoreGlobalStreamSourceBuilder(
                $extensionObject->fromStream,
                [$projectionName]
            );
        }

        $extensions[] = new DbalTableManagerReference(ProjectionStateTableManager::class);

        $eventSourcingConfiguration = ExtensionObjectResolver::resolveUnique(EventSourcingConfiguration::class, $serviceExtensions, EventSourcingConfiguration::createWithDefaults());
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

    /**
     * Collect event names for each partitioned projection.
     * Returns empty array for projections that use catch-all patterns or object types.
     *
     * @param array<class-string, string> $namedEvents Map of class name to named event name
     * @return array<string, array<string>> Map of projection name to event names (empty array means no filtering)
     */
    private static function collectProjectionEventNames(
        AnnotationFinder $annotationRegistrationService,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        array $namedEvents
    ): array {
        $projectionEventNames = [];
        $disabledFiltering = [];
        $routingMapBuilder = new BusRoutingMapBuilder();

        foreach ($annotationRegistrationService->findCombined(ProjectionV2::class, EventHandler::class) as $projectionEventHandler) {
            /** @var ProjectionV2 $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();
            $projectionName = $projectionAttribute->name;

            if (! isset($projectionEventNames[$projectionName])) {
                $projectionEventNames[$projectionName] = [];
            }

            if (isset($disabledFiltering[$projectionName])) {
                continue;
            }

            $routes = $routingMapBuilder->getRoutesFromAnnotatedFinding($projectionEventHandler, $interfaceToCallRegistry);
            foreach ($routes as $route) {
                // Check for catch-all pattern - disable filtering by keeping empty array
                if ($route === '*' || $route === 'object') {
                    $projectionEventNames[$projectionName] = [];
                    $disabledFiltering[$projectionName] = true;
                    break;
                }

                // Check for glob patterns (containing * but not exactly *)
                if (str_contains($route, '*')) {
                    throw ConfigurationException::create(
                        "Projection {$projectionName} uses glob pattern '{$route}' which is not allowed. " .
                        'For query optimization, event handlers must use explicit event names. Use union type parameters instead.'
                    );
                }

                // Check if route is a class with NamedEvent annotation
                if (class_exists($route) && isset($namedEvents[$route])) {
                    $projectionEventNames[$projectionName][] = $namedEvents[$route];
                } else {
                    $projectionEventNames[$projectionName][] = $route;
                }
            }
        }

        // Deduplicate event names (skip disabled ones which are empty arrays)
        foreach ($projectionEventNames as $projectionName => $eventNames) {
            if (! isset($disabledFiltering[$projectionName]) && $eventNames !== []) {
                $projectionEventNames[$projectionName] = array_values(array_unique($eventNames));
            }
        }

        return $projectionEventNames;
    }
}
