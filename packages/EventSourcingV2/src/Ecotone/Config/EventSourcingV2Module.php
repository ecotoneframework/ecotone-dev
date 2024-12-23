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
use Ecotone\EventSourcingV2\Ecotone\EcotoneAsynchronousProjectionRunner;
use Ecotone\EventSourcingV2\Ecotone\EcotoneAsynchronousProjectionTrigger;
use Ecotone\EventSourcingV2\Ecotone\EcotoneProjector;
use Ecotone\EventSourcingV2\Ecotone\StandaloneAggregate\EventSourcingAggregateRepositoryBuilder;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\Test\InMemoryEventStore;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\PriorityBasedOnType;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\ModellingHandlerModule;

#[ModuleAnnotation]
final class EventSourcingV2Module implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $eventSourcedAggregates = $annotationRegistrationService->findAnnotatedClasses(EventSourced::class);
        $projectionsClasses = $annotationRegistrationService->findAnnotatedClasses(Projection::class);
        $eventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);

        /** @var array<string, Asynchronous> $asynchronousProjections */
        $asynchronousProjections = [];
        foreach ($projectionsClasses as $projectionClassName) {
            $attributes = $annotationRegistrationService->getAnnotationsForClass($projectionClassName);
            $projectionAttribute = null;
            $asyncAttribute = null;
            foreach ($attributes as $attribute) {
                if ($attribute instanceof Projection) {
                    if ($asyncAttribute) {
                        $asynchronousProjections[$attribute->name] = $asyncAttribute;
                        break;
                    } else {
                        $projectionAttribute = $attribute;
                    }
                }
                if ($attribute instanceof Asynchronous) {
                    if ($projectionAttribute) {
                        $asynchronousProjections[$projectionAttribute->name] = $attribute;
                        break;
                    } else {
                        $asyncAttribute = $attribute;
                    }
                }
            }
        }

        return new self($eventSourcedAggregates, $eventHandlers, $asynchronousProjections);
    }

    /**
     * @param array<class-string> $eventSourcedAggregates
     * @param array<AnnotatedDefinition> $eventHandlers
     * @param array<string, Asynchronous> $asynchronousProjections
     */
    public function __construct(private array $eventSourcedAggregates, private array $eventHandlers, private array $asynchronousProjections)
    {
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        /** @var array<array<string>> $projectionConfigurations */
        $projectionConfigurations = [];
        $eventToAsyncProjectionTrigger = [];
        foreach ($this->eventHandlers as $eventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $eventHandler->getAnnotationForClass();

            $eventName = ModellingHandlerModule::getNamedMessageChannelForEventHandler($eventHandler, $interfaceToCallRegistry);
            $inputChannelName = self::getNamedMessageChannelFor($projectionAttribute->name, $eventName);

            $messagingConfiguration->registerDefaultChannelFor(SimpleMessageChannelBuilder::createPublishSubscribeChannel($inputChannelName));

            $eventHandlerTriggeringRoutingKey = ModellingHandlerModule::getHandlerChannel($eventHandler);
            $eventHandlerExecutionRoutingKey = isset($this->asynchronousProjections[$projectionAttribute->name])
                ? AsynchronousModule::getHandlerExecutionChannel($eventHandlerTriggeringRoutingKey)
                : $eventHandlerTriggeringRoutingKey;

            $messagingConfiguration->registerMessageHandler(
                BridgeBuilder::create()
                    ->withInputChannelName($inputChannelName)
                    ->withOutputMessageChannel($eventHandlerExecutionRoutingKey)
                    ->withEndpointAnnotations([PriorityBasedOnType::fromAnnotatedFinding($eventHandler)->toAttributeDefinition()])
            );

            $projectionConfigurations[$projectionAttribute->name][$eventName] = $inputChannelName;
            if (isset($this->asynchronousProjections[$projectionAttribute->name])) {
                $eventToAsyncProjectionTrigger[$eventName] ??= [];
                if (!in_array($projectionAttribute->name, $eventToAsyncProjectionTrigger[$eventName])) {
                    $eventToAsyncProjectionTrigger[$eventName][] = $projectionAttribute->name;
                }
            }
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
        $projectorsReference = new Reference(self::class . ".projectors");
        $messagingConfiguration->registerServiceDefinition($projectorsReference, new Definition(InMemoryPSRContainer::class, [$projectors], "createFromAssociativeArray"));

        $permanentProjectors = [];

        // Asynchronous projections
        if ($this->asynchronousProjections) {
            $messagingConfiguration->registerServiceDefinition(EcotoneAsynchronousProjectionRunner::class, new Definition(EcotoneAsynchronousProjectionRunner::class, [
                new Reference(EventStore::class),
                $projectorsReference,
            ]));
            $messagingConfiguration->registerServiceDefinition(EcotoneAsynchronousProjectionTrigger::class, new Definition(EcotoneAsynchronousProjectionTrigger::class, [
                new Reference(MessagingEntrypoint::class),
                $eventToAsyncProjectionTrigger,
            ]));
            $permanentProjectors[] = new Reference(EcotoneAsynchronousProjectionTrigger::class);
            $messagingConfiguration->registerMessageHandler(
                ServiceActivatorBuilder::create(
                    EcotoneAsynchronousProjectionRunner::class,
                    "run"
                )
                    ->withEndpointId(EcotoneAsynchronousProjectionRunner::PROJECTION_RUNNER_CHANNEL . ".endpoint")
                    ->withInputChannelName(EcotoneAsynchronousProjectionRunner::PROJECTION_RUNNER_CHANNEL)
            );

            foreach ($this->asynchronousProjections as $projectionName => $asynchronousAttribute) {
                $messagingConfiguration->registerAsynchronousEndpoint(
                    $asynchronousAttribute->getChannelName(),
                    EcotoneAsynchronousProjectionRunner::PROJECTION_RUNNER_CHANNEL . ".endpoint"
                );
            }
        }

        // todo: use a real event store from configuration
        $messagingConfiguration->registerServiceDefinition(EventStore::class, new Definition(InMemoryEventStore::class, [
            $projectorsReference,
            $permanentProjectors,
        ]));

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
            new PureEventSourcingAggregateRepositoryBuilder($this->eventSourcedAggregates),
        ];
    }
}