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
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreMultiStreamSourceBuilder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
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

        // Iterate over all projections and gather FromStream attributes per class (supports repeatable usage)
        foreach ($annotationRegistrationService->findAnnotatedClasses(ProjectionV2::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->findAttributeForClass($classname, ProjectionV2::class);
            $customScopeStrategyAttribute = $annotationRegistrationService->findAttributeForClass($classname, Partitioned::class);

            // collect all FromStream attributes for this class (repeatable)
            $classAnnotations = $annotationRegistrationService->getAnnotationsForClass($classname);
            $streamAttributes = array_values(array_filter($classAnnotations, static fn($a) => $a instanceof FromStream));

            if (! $projectionAttribute || empty($streamAttributes)) {
                continue;
            }

            $projectionName = $projectionAttribute->name;
            $handledProjections[] = $projectionName;

            // Determine partitionHeaderName from CustomScopeStrategy attribute
            $partitionHeaderName = $customScopeStrategyAttribute?->partitionHeaderName;

            if ($partitionHeaderName !== null) {
                // Partitioned projections must target a single stream (aggregate stream)
                if (count($streamAttributes) > 1) {
                    throw ConfigurationException::create("Projection {$projectionName} cannot be partitioned by aggregate id when multiple streams are configured");
                }
                /** @var FromStream $single */
                $single = $streamAttributes[0];
                $aggregateType = $single->aggregateType ?: throw ConfigurationException::create("Aggregate type must be provided for projection {$projectionName} as partition header name is provided");
                $extensions[] = new EventStoreAggregateStreamSourceBuilder(
                    $projectionName,
                    $aggregateType,
                    $single->getStream(),
                );
                $extensions[] = new AggregateIdPartitionProviderBuilder($projectionName, $aggregateType, $single->getStream());
            } else {
                if (count($streamAttributes) > 1) {
                    // Multi-stream: build stream name -> stream source map
                    $map = [];
                    foreach ($streamAttributes as $attribute) {
                        $map[$attribute->getStream()] = new EventStoreGlobalStreamSourceBuilder($attribute->getStream(), []);
                    }
                    $extensions[] = new EventStoreMultiStreamSourceBuilder(
                        $map,
                        [$projectionName],
                    );
                } else {
                    $attribute = $streamAttributes[0];
                    $extensions[] = new EventStoreGlobalStreamSourceBuilder($attribute->getStream(), [$projectionName]);
                }
            }
        }

        if (! empty($handledProjections)) {
            $extensions[] = new DbalProjectionStateStorageBuilder($handledProjections);
        }

        return new self($extensions);
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
}
