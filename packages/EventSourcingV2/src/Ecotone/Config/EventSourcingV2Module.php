<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\Test\InMemoryEventStore;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
final class EventSourcingV2Module implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $eventSourcedAggregates = $annotationRegistrationService->findAnnotatedClasses(EventSourced::class);

        return new self($eventSourcedAggregates);
    }

    /**
     * @param array<class-string> $eventSourcedAggregates
     */
    public function __construct(private array $eventSourcedAggregates)
    {
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // todo: use a real event store from configuration
        $messagingConfiguration->registerServiceDefinition("eventStoreV2", new Definition(InMemoryEventStore::class));
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return "eventSourcingV2";
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [new EventSourcingAggregateRepositoryBuilder($this->eventSourcedAggregates)];
    }
}