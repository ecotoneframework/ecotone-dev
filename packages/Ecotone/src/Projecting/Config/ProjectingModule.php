<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\PriorityBasedOnType;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\MessageHandlerRoutingModule;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\EcotoneProjectorExecutor;
use Ecotone\Projecting\ProjectingManager;
use Test\Ecotone\Projecting\InMemoryProjectionStateStorage;

#[ModuleAnnotation]
class ProjectingModule implements AnnotationModule
{
    /**
     * @param AnnotatedDefinition[] $projectionEventHandlers
     */
    public function __construct(
        private array $projectionEventHandlers,
        private AsynchronousModule $asynchronousModule
    )
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $projectionEventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);

        return new self(
            $projectionEventHandlers,
            AsynchronousModule::create($annotationRegistrationService, $interfaceToCallRegistry),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        /** @var array<string, array<string, string>> $eventToChannelMapping first key is projection name - key is event name - value is channel name */
        $eventToChannelMapping = [];

        /** @var array<string, array<string>> $eventToProjectionMapping key is event name - value is list of projections to trigger */
        $eventToProjectionsMapping = [];

        /** @var array<string, Projection> $projectionAttributes */
        $projectionAttributes = [];

        foreach ($this->projectionEventHandlers as $projectionEventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();
            /** @var EventHandler $handlerAttribute */
            $handlerAttribute = $projectionEventHandler->getAnnotationForMethod();

            $eventName = MessageHandlerRoutingModule::getRoutingInputMessageChannelForEventHandler($projectionEventHandler, $interfaceToCallRegistry);
            $eventHandlerTriggeringInputChannel = MessageHandlerRoutingModule::getExecutionMessageHandlerChannel($projectionEventHandler);
            $eventHandlerSynchronousInputChannel = $serviceConfiguration->isModulePackageEnabled(ModulePackageList::ASYNCHRONOUS_PACKAGE) ? $this->asynchronousModule->getSynchronousChannelFor($eventHandlerTriggeringInputChannel, $handlerAttribute->getEndpointId()) : $eventHandlerTriggeringInputChannel;

            $eventToChannelMapping[$projectionAttribute->name][$eventName] = $eventHandlerSynchronousInputChannel;
            $eventToProjectionsMapping[$eventName][] = $projectionAttribute->name;
            $eventToProjectionsMapping[$eventName] = array_unique($eventToProjectionsMapping[$eventName]);
            $projectionAttributes[$projectionAttribute->name] = $projectionAttribute;

            $messagingConfiguration->registerMessageHandler(
                BridgeBuilder::create()
                    ->withInputChannelName($eventName)
                    ->withOutputMessageChannel($this->inputChannelForProjectingManager($projectionAttribute->name))
                    ->withEndpointAnnotations([PriorityBasedOnType::fromAnnotatedFinding($projectionEventHandler)->toAttributeDefinition()])
            );
        }

        $messagingConfiguration->registerServiceDefinition(InMemoryProjectionStateStorage::class);

        foreach ($eventToChannelMapping as $projectionName => $eventToChannelMap) {
            $projectionAttribute = $projectionAttributes[$projectionName];
            if ($projectionAttribute->streamSourceReference === null) {
                continue;
            }

            $projector = new Definition(
                EcotoneProjectorExecutor::class,
                [
                    new Reference(MessagingEntrypoint::class),
                    $eventToChannelMap,
                ]
            );

            $projectingManager = new Definition(
                ProjectingManager::class,
                [
                    new Reference(InMemoryProjectionStateStorage::class),
                    $projector,
                    new Reference($projectionAttribute->streamSourceReference),
                    $projectionName,
                ]
            );

            $messagingConfiguration->registerMessageHandler(
                MessageProcessorActivatorBuilder::create()
                    ->chainInterceptedProcessor(
                        MethodInvokerBuilder::create(
                            $projectingManager,
                            InterfaceToCallReference::create(ProjectingManager::class, 'execute'),
                            [
                                HeaderBuilder::create('partitionKey', MessageHeaders::EVENT_AGGREGATE_ID)
                            ],
                        )
                    )
                    ->withEndpointId($this->endpointIdForProjection($projectionName))
                    ->withInputChannelName($this->inputChannelForProjectingManager($projectionName))
            );
        }


    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ServiceConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    private function endpointIdForProjection(string $projectionName): string
    {
        return 'projecting_manager_endpoint:' . $projectionName;
    }

    public function inputChannelForProjectingManager(string $projectionName): string
    {
        return 'projecting_manager_handler:' . $projectionName;
    }
}