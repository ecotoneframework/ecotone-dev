<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Config\Polling\PollingProjectionConfiguration;
use Ecotone\EventSourcing\Config\Polling\ProophPollingProjectionRoutingExtension;
use Ecotone\EventSourcing\Projecting\AggregateIdPartitionProviderBuilder;
use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorageBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSourceBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSourceBuilder;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Projecting\Attribute\Polling;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\Config\ProjectingModule;
use Ecotone\Projecting\EventStoreAdapter\EventStoreChannelAdapter;
use Ecotone\Projecting\EventStoreAdapter\PollingProjectionChannelAdapter;

#[ModuleAnnotation]
class ProophProjectingModule implements AnnotationModule
{
    /**
     * @param array<PollingProjectionConfiguration> $pollingProjections
     */
    public function __construct(
        private array $extensions,
        private array $pollingProjections = []
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $handledProjections = [];
        $extensions = [];
        $pollingProjections = [];

        foreach ($annotationRegistrationService->findAnnotatedClasses(FromStream::class) as $classname) {
            $projectionAttribute = $annotationRegistrationService->findAttributeForClass($classname, Projection::class);
            $streamAttribute = $annotationRegistrationService->findAttributeForClass($classname, FromStream::class);
            $pollingAttribute = $annotationRegistrationService->findAttributeForClass($classname, Polling::class);
            $asynchronousAttribute = $annotationRegistrationService->findAttributeForClass($classname, Asynchronous::class);

            if (! $projectionAttribute || ! $streamAttribute) {
                continue;
            }

            $projectionName = $projectionAttribute->name;
            $handledProjections[] = $projectionName;

            // Validate: Polling cannot be combined with Asynchronous
            if ($pollingAttribute && $asynchronousAttribute) {
                throw ConfigurationException::create(
                    "Projection '{$projectionName}' cannot use both #[Polling] and #[Asynchronous] attributes. " .
                    'A projection must be either polling-based or event-driven (synchronous/asynchronous), not both.'
                );
            }

            // Validate: Polling can only be used with global stream sources (non-partitioned)
            if ($pollingAttribute && $projectionAttribute->partitionHeaderName) {
                throw ConfigurationException::create(
                    "Projection '{$projectionName}' cannot use #[Polling] attribute with partitioned projections. " .
                    'Polling is only supported for global stream sources (projections without partitionHeaderName).'
                );
            }

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

            if ($pollingAttribute) {
                $pollingProjections[] = new PollingProjectionConfiguration(
                    $projectionName,
                    $pollingAttribute->getEndpointId()
                );
            }
        }

        if (! empty($handledProjections)) {
            $extensions[] = new DbalProjectionStateStorageBuilder($handledProjections);
        }

        return new self($extensions, $pollingProjections);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->pollingProjections as $pollingProjection) {
            $messagingConfiguration->registerConsumer(
                InboundChannelAdapterBuilder::createWithDirectObject(
                    ProjectingModule::inputChannelForProjectingManager($pollingProjection->projectionName),
                    new PollingProjectionChannelAdapter(),
                    $interfaceToCallRegistry->getFor(PollingProjectionChannelAdapter::class, 'execute')
                )
                    ->withEndpointId($pollingProjection->endpointId)
            );
        }
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

        $extensions[] = new ProophPollingProjectionRoutingExtension();

        return $extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::EVENT_SOURCING_PACKAGE;
    }
}
