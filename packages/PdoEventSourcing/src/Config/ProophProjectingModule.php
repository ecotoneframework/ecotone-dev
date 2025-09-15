<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\FromStream;
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
use Ecotone\Projecting\Attribute\Projection;

#[ModuleAnnotation]
class ProophProjectingModule implements AnnotationModule
{
    public function __construct(private array $extensions)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $handledProjections = [];
        $extensions = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(FromStream::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->getAttributeForClass($classname, Projection::class);
            $streamAttribute = $annotationRegistrationService->getAttributeForClass($classname, FromStream::class);
            if (! $projectionAttribute || ! $streamAttribute) {
                continue;
            }

            $projectionName = $projectionAttribute->name;
            $handledProjections[] = $projectionName;
            if ($projectionAttribute->partitionHeaderName) {
                $aggregateType = $streamAttribute->aggregateType ?: throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
                $extensions[] = new EventStoreAggregateStreamSourceBuilder(
                    $projectionName,
                    $aggregateType,
                    $streamAttribute->stream,
                );
                $extensions[] = new AggregateIdPartitionProviderBuilder($projectionName, $aggregateType, $streamAttribute->stream);
            } else {
                $extensions[] = new EventStoreGlobalStreamSourceBuilder(
                    $streamAttribute->stream,
                    [$projectionName],
                );
            }
        }
        if (! empty($handledProjections)) {
            $extensions[] = new DbalProjectionStateStorageBuilder($handledProjections);
        }
        return new self($extensions);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return $this->extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::EVENT_SOURCING_PACKAGE;
    }
}
