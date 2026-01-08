<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\StreamFilter;
use Ecotone\Projecting\StreamFilterRegistry;

/**
 * Collects stream filters from #[FromStream] and #[FromAggregateStream] attributes
 * and registers StreamFilterRegistry as a service.
 */
#[ModuleAnnotation]
class StreamFilterRegistryModule implements AnnotationModule
{
    /** @param array<string, StreamFilter[]> $streamFilters */
    public function __construct(private array $streamFilters)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(self::collectStreamFilters($annotationRegistrationService));
    }

    /**
     * Collects stream filters from all #[ProjectionV2] classes.
     * This method can be reused by other modules that need stream filter information.
     *
     * @return array<string, StreamFilter[]> Map of projection name to stream filters
     */
    public static function collectStreamFilters(AnnotationFinder $annotationFinder): array
    {
        $streamFilters = [];

        foreach ($annotationFinder->findAnnotatedClasses(ProjectionV2::class) as $classname) {
            $projectionAttribute = $annotationFinder->getAttributeForClass($classname, ProjectionV2::class);
            $projectionName = $projectionAttribute->name;

            foreach ($annotationFinder->getAnnotationsForClass($classname, FromStream::class) as $streamAttribute) {
                $streamFilters[$projectionName][] = new StreamFilter(
                    $streamAttribute->stream,
                    $streamAttribute->aggregateType,
                    $streamAttribute->eventStoreReferenceName,
                );
            }

            foreach ($annotationFinder->getAnnotationsForClass($classname, FromAggregateStream::class) as $aggregateStreamAttribute) {
                $streamFilters[$projectionName][] = self::resolveFromAggregateStream($annotationFinder, $aggregateStreamAttribute, $projectionName);
            }
        }

        return $streamFilters;
    }

    private static function resolveFromAggregateStream(
        AnnotationFinder $annotationFinder,
        FromAggregateStream $attribute,
        string $projectionName
    ): StreamFilter {
        $aggregateClass = $attribute->aggregateClass;

        $eventSourcingAggregateAttribute = $annotationFinder->findAttributeForClass($aggregateClass, EventSourcingAggregate::class);
        if ($eventSourcingAggregateAttribute === null) {
            throw ConfigurationException::create("Class {$aggregateClass} referenced in #[FromAggregateStream] for projection {$projectionName} must be an EventSourcingAggregate.");
        }

        // Resolve stream name from #[Stream] attribute if available
        $streamName = $aggregateClass;
        if (class_exists(Stream::class)) {
            $streamAttribute = $annotationFinder->findAttributeForClass($aggregateClass, Stream::class);
            $streamName = $streamAttribute?->getName() ?? $aggregateClass;
        }

        // Resolve aggregate type from #[AggregateType] attribute if available
        $aggregateType = $aggregateClass;
        $aggregateTypeAttribute = $annotationFinder->findAttributeForClass($aggregateClass, AggregateType::class);
        $aggregateType = $aggregateTypeAttribute?->getName() ?? $aggregateClass;

        return new StreamFilter($streamName, $aggregateType, $attribute->eventStoreReferenceName);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $filtersDefinition = [];
        foreach ($this->streamFilters as $projectionName => $filters) {
            $filtersDefinition[$projectionName] = [];
            foreach ($filters as $filter) {
                $filtersDefinition[$projectionName][] = new Definition(StreamFilter::class, [
                    $filter->streamName,
                    $filter->aggregateType,
                    $filter->eventStoreReferenceName,
                ]);
            }
        }

        $messagingConfiguration->registerServiceDefinition(
            StreamFilterRegistry::class,
            new Definition(StreamFilterRegistry::class, [$filtersDefinition])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions, ?InterfaceToCallRegistry $interfaceToCallRegistry = null): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}

