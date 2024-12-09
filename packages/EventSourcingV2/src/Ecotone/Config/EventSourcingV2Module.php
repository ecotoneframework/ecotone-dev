<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\EventSourcingV2\Ecotone\Attribute\Projection;
use Ecotone\EventSourcingV2\Ecotone\EcotoneProjector;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\Test\InMemoryEventStore;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\PriorityBasedOnType;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\ModellingHandlerModule;

#[ModuleAnnotation]
final class EventSourcingV2Module implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $eventSourcedAggregates = $annotationRegistrationService->findAnnotatedClasses(EventSourced::class);
        $eventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);

        return new self($eventSourcedAggregates, $eventHandlers);
    }

    /**
     * @param array<class-string> $eventSourcedAggregates
     * @param array<AnnotatedDefinition> $eventHandlers
     */
    public function __construct(private array $eventSourcedAggregates, private array $eventHandlers)
    {
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        /** @var array<array<string>> $projectionConfigurations */
        $projectionConfigurations = [];
        foreach ($this->eventHandlers as $eventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $eventHandler->getAnnotationForClass();
            /** @var EventHandler $eventHandlerAttribute */
            $eventHandlerAttribute = $eventHandler->getAnnotationForMethod();

            $eventName = ModellingHandlerModule::getNamedMessageChannelForEventHandler($eventHandler, $interfaceToCallRegistry);
            $inputChannelName = self::getNamedMessageChannelFor($projectionAttribute->name, $eventName);

            $messagingConfiguration->registerDefaultChannelFor(SimpleMessageChannelBuilder::createPublishSubscribeChannel($inputChannelName));

            $eventHandlerRoutingKey = ModellingHandlerModule::getHandlerChannel($eventHandler);
            $messagingConfiguration->registerMessageHandler(
                BridgeBuilder::create()
                    ->withInputChannelName($inputChannelName)
                    ->withOutputMessageChannel($eventHandlerRoutingKey)
                    ->withEndpointAnnotations([PriorityBasedOnType::fromAnnotatedFinding($eventHandler)->toAttributeDefinition()])
            );

            $projectionConfigurations[$projectionAttribute->name][$eventName] = $inputChannelName;
        }

        /** @var array<string, Reference> $projectors */
        $projectors = [];
        foreach ($projectionConfigurations as $projectionName => $projectionConfiguration) {
            $projectors[$projectionName] = new Definition(
                EcotoneProjector::class,
                [
                    new Reference(MessagingEntrypoint::class),
                    $projectionConfiguration,
                ],
            );
        }

        // todo: use a real event store from configuration
        $messagingConfiguration->registerServiceDefinition(EventStore::class, new Definition(InMemoryEventStore::class, [$projectors]));
    }

    public static function getNamedMessageChannelFor(string $projectionName, string $eventName): string
    {
        return "projection." .  $projectionName . "." . $eventName;
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
        return [
            new EventSourcingAggregateRepositoryBuilder($this->eventSourcedAggregates),
            new PureEventSourcingAggregateRepositoryBuilder($this->eventSourcedAggregates)
        ];
    }
}