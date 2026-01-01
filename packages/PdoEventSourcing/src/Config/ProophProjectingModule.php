<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\EventSourcing\Projecting\AggregateIdPartitionProviderBuilder;
use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorageBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSourceBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
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
use Ecotone\Projecting\EventStoreAdapter\EventStoreChannelAdapter;

#[ModuleAnnotation]
class ProophProjectingModule implements AnnotationModule
{
    public function __construct(
        private array $extensions
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $handledProjections = [];
        $extensions = [];

        $namedEvents = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(NamedEvent::class) as $className) {
            $attribute = $annotationRegistrationService->getAttributeForClass($className, NamedEvent::class);
            $namedEvents[$className] = $attribute->getName();
        }

        $projectionEventNames = self::collectProjectionEventNames($annotationRegistrationService, $interfaceToCallRegistry, $namedEvents);

        $resolvedConfigs = [
            ...self::resolveFromStreamConfigs($annotationRegistrationService, $projectionEventNames),
            ...self::resolveFromAggregateStreamConfigs($annotationRegistrationService, $projectionEventNames),
        ];

        foreach ($resolvedConfigs as $config) {
            $handledProjections[] = $config['projectionName'];
            $extensions = [...$extensions, ...self::createStreamSourceExtensions($config)];
        }

        if ($handledProjections !== []) {
            $extensions[] = new DbalProjectionStateStorageBuilder($handledProjections);
        }

        return new self($extensions);
    }

    /**
     * Resolve stream configurations from FromStream attributes.
     *
     * @return array<array{projectionName: string, streamName: string, aggregateType: ?string, isPartitioned: bool, eventNames: array}>
     */
    private static function resolveFromStreamConfigs(
        AnnotationFinder $annotationRegistrationService,
        array $projectionEventNames
    ): array {
        $configs = [];

        foreach ($annotationRegistrationService->findAnnotatedClasses(FromStream::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->findAttributeForClass($classname, ProjectionV2::class);
            $streamAttribute = $annotationRegistrationService->findAttributeForClass($classname, FromStream::class);
            $partitionedAttribute = $annotationRegistrationService->findAttributeForClass($classname, Partitioned::class);

            if (! $projectionAttribute || ! $streamAttribute) {
                continue;
            }

            $projectionName = $projectionAttribute->name;
            $isPartitioned = $partitionedAttribute !== null;

            if ($isPartitioned && ! $streamAttribute->aggregateType) {
                throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
            }

            $configs[] = [
                'projectionName' => $projectionName,
                'streamName' => $streamAttribute->stream,
                'aggregateType' => $streamAttribute->aggregateType,
                'isPartitioned' => $isPartitioned,
                'eventNames' => $projectionEventNames[$projectionName] ?? [],
            ];
        }

        return $configs;
    }

    /**
     * Resolve stream configurations from FromAggregateStream attributes.
     *
     * @return array<array{projectionName: string, streamName: string, aggregateType: ?string, isPartitioned: bool, eventNames: array}>
     */
    private static function resolveFromAggregateStreamConfigs(
        AnnotationFinder $annotationRegistrationService,
        array $projectionEventNames
    ): array {
        $configs = [];

        foreach ($annotationRegistrationService->findAnnotatedClasses(FromAggregateStream::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->findAttributeForClass($classname, ProjectionV2::class);
            $aggregateStreamAttribute = $annotationRegistrationService->findAttributeForClass($classname, FromAggregateStream::class);
            $partitionedAttribute = $annotationRegistrationService->findAttributeForClass($classname, Partitioned::class);

            if (! $projectionAttribute || ! $aggregateStreamAttribute) {
                continue;
            }

            $aggregateClass = $aggregateStreamAttribute->aggregateClass;
            $projectionName = $projectionAttribute->name;

            $eventSourcingAggregateAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, EventSourcingAggregate::class);
            if ($eventSourcingAggregateAttribute === null) {
                throw ConfigurationException::create("Class {$aggregateClass} referenced in #[AggregateStream] for projection {$projectionName} must be an EventSourcingAggregate. Add #[EventSourcingAggregate] attribute to the class.");
            }

            $streamAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, Stream::class);
            $streamName = $streamAttribute?->getName() ?? $aggregateClass;

            $aggregateTypeAttribute = $annotationRegistrationService->findAttributeForClass($aggregateClass, AggregateType::class);
            $aggregateType = $aggregateTypeAttribute?->getName() ?? $aggregateClass;

            $configs[] = [
                'projectionName' => $projectionName,
                'streamName' => $streamName,
                'aggregateType' => $aggregateType,
                'isPartitioned' => $partitionedAttribute !== null,
                'eventNames' => $projectionEventNames[$projectionName] ?? [],
            ];
        }

        return $configs;
    }

    /**
     * Create stream source extensions based on resolved configuration.
     *
     * @param array{projectionName: string, streamName: string, aggregateType: ?string, isPartitioned: bool, eventNames: array} $config
     * @return ProjectionComponentBuilder[]
     */
    private static function createStreamSourceExtensions(array $config): array
    {
        if ($config['isPartitioned']) {
            return [
                new EventStoreAggregateStreamSourceBuilder(
                    $config['projectionName'],
                    $config['aggregateType'],
                    $config['streamName'],
                    $config['eventNames'],
                ),
                new AggregateIdPartitionProviderBuilder(
                    $config['projectionName'],
                    $config['aggregateType'],
                    $config['streamName']
                ),
            ];
        }

        return [
            new EventStoreGlobalStreamSourceBuilder(
                $config['streamName'],
                [$config['projectionName']],
            ),
        ];
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // Polling projection registration is now handled by ProjectingAttributeModule
    }

    public function canHandle($extensionObject): bool
    {
        // EventStoreChannelAdapter is now handled by EventStoreAdapterModule in Ecotone package
        return false;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $extensions = [...$this->extensions];

        foreach ($serviceExtensions as $extensionObject) {
            if (! ($extensionObject instanceof EventStoreChannelAdapter)) {
                continue;
            }

            $projectionName = $extensionObject->getProjectionName();
            $extensions[] = new EventStoreGlobalStreamSourceBuilder(
                $extensionObject->fromStream,
                [$projectionName]
            );
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
