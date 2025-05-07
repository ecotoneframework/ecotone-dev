<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Doctrine\DBAL\Connection;
use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
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
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\MessageHandlerRoutingModule;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\Dbal\DbalProjectionLifecycleStateStorage;
use Ecotone\Projecting\Dbal\DbalProjectionStateStorage;
use Ecotone\Projecting\EcotoneProjectorExecutor;
use Ecotone\Projecting\InMemory\InMemoryProjectionLifecycleStateStorage;
use Ecotone\Projecting\InMemory\InMemoryProjectionStateStorage;
use Ecotone\Projecting\Lifecycle\EcotoneLifecycleExecutor;
use Ecotone\Projecting\Lifecycle\LifecycleExecutor;
use Ecotone\Projecting\Lifecycle\LifecycleManager;
use Ecotone\Projecting\Lifecycle\ProjectionLifecycleStateStorage;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionStateStorage;
use Test\Ecotone\EventSourcing\Fixture\TicketWithLimitedLoad\ProjectionConfiguration;

#[ModuleAnnotation]
class ProjectingModule implements AnnotationModule
{
    /**
     * @param AnnotatedDefinition[] $projectionEventHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionInitHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionResetHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionDeleteHandlers
     */
    public function __construct(
        private array $projectionEventHandlers,
        private array $projectionInitHandlers,
        private array $projectionResetHandlers,
        private array $projectionDeleteHandlers,
        private AsynchronousModule $asynchronousModule
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $projectionEventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);
        $projectionInitHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionInitialization::class);
        $projectionResetHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionReset::class);
        $projectionDeleteHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionDelete::class);

        return new self(
            $projectionEventHandlers,
            $projectionInitHandlers,
            $projectionResetHandlers,
            $projectionDeleteHandlers,
            AsynchronousModule::create($annotationRegistrationService, $interfaceToCallRegistry),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $projectingConfiguration = ExtensionObjectResolver::resolveUnique(ProjectingConfiguration::class, $extensionObjects, ProjectingConfiguration::createInMemory());
        /** @var array<string, array<string, string>> $eventToChannelMapping first key is projection name - key is event name - value is channel name */
        $eventToChannelMapping = [];

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
            $projectionAttributes[$projectionAttribute->name] = $projectionAttribute;

            $messagingConfiguration->registerMessageHandler(
                BridgeBuilder::create()
                    ->withInputChannelName($eventName)
                    ->withOutputMessageChannel($this->inputChannelForProjectingManager($projectionAttribute->name))
                    ->withEndpointAnnotations([PriorityBasedOnType::fromAnnotatedFinding($projectionEventHandler)->toAttributeDefinition()])
            );
        }

        foreach ($eventToChannelMapping as $projectionName => $eventToChannelMap) {
            $projectionAttribute = $projectionAttributes[$projectionName];
            if ($projectionAttribute->streamSourceReference === null) {
                continue;
            }

            $projector = new Definition(EcotoneProjectorExecutor::class, [
                new Reference(MessagingEntrypoint::class),
                $eventToChannelMap,
            ]);

            $projectingManager = new Definition(ProjectingManager::class, [
                new Reference(ProjectionStateStorage::class),
                new Reference(LifecycleManager::class),
                $projector,
                new Reference($projectionAttribute->streamSourceReference),
                $projectionName,
            ]);

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

        // Register implementations
        $messagingConfiguration->registerServiceDefinition(
            ProjectionStateStorage::class,
            match ($projectingConfiguration->projectionStateStorageReference) {
                InMemoryProjectionStateStorage::class => new Definition(InMemoryProjectionStateStorage::class),
                DbalProjectionStateStorage::class => new Definition(DbalProjectionStateStorage::class, [new Reference(Connection::class)]),
                default => new Reference($projectingConfiguration->projectionStateStorageReference)
            }
        );

        $messagingConfiguration->registerServiceDefinition(
            ProjectionLifecycleStateStorage::class,
            match ($projectingConfiguration->projectionLifecycleStateStorageReference) {
                InMemoryProjectionLifecycleStateStorage::class => new Definition(InMemoryProjectionLifecycleStateStorage::class),
                DbalProjectionLifecycleStateStorage::class => new Definition(DbalProjectionLifecycleStateStorage::class, [new Reference(Connection::class)]),
                default => new Reference($projectingConfiguration->projectionLifecycleStateStorageReference)
            }
        );

        // Lifecycle handlers
        $ecotoneLifecycleExecutor = new Definition(EcotoneLifecycleExecutor::class, [
            new Reference(MessagingEntrypoint::class),
            self::registerProjectionLifeCycleHandlers($messagingConfiguration, $this->projectionInitHandlers),
            self::registerProjectionLifeCycleHandlers($messagingConfiguration, $this->projectionResetHandlers),
            self::registerProjectionLifeCycleHandlers($messagingConfiguration, $this->projectionDeleteHandlers),
        ]);

        $messagingConfiguration->registerServiceDefinition(
            LifecycleManager::class,
            new Definition(LifecycleManager::class, [
                new Reference(ProjectionStateStorage::class),
                new Reference(ProjectionLifecycleStateStorage::class),
                $ecotoneLifecycleExecutor,
            ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ServiceConfiguration
            || $extensionObject instanceof ProjectingConfiguration;
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

    private function inputChannelForProjectingManager(string $projectionName): string
    {
        return 'projecting_manager_handler:' . $projectionName;
    }

    /**
     * @param class-string $lifecycleClassname
     * @return array<string, MessageProcessorActivatorBuilder> key is projection name, value is MessageProcessorActivatorBuilder for lifecycle handler
     */
    private static function buildProjectionLifeCycleHandlers(AnnotationFinder $annotationRegistrationService, string $lifecycleClassname): array {
        $lifecycleHandlers = $annotationRegistrationService->findCombined(Projection::class, $lifecycleClassname);
        /** @var array<string, MessageProcessorActivatorBuilder> $projectionLifecycleChannelsMap */
        $projectionLifecycleMessageProcessorActivatorBuilderMap = [];
        foreach ($lifecycleHandlers as $lifecycleHandler) {
            $projectionReferenceName = AnnotatedDefinitionReference::getReferenceForClassName($annotationRegistrationService, $lifecycleHandler->getClassName());
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $lifecycleHandler->getAnnotationForClass();

            $projectionLifecycleMessageProcessorActivatorBuilderMap[$projectionAttribute->name] = MessageProcessorActivatorBuilder::create()
                ->chainInterceptedProcessor(
                    MethodInvokerBuilder::create(
                        new Reference($projectionReferenceName),
                        InterfaceToCallReference::create($lifecycleHandler->getClassName(), $lifecycleHandler->getMethodName())
                    )
                )
                ->withInputChannelName("projecting_lifecycle_handler:" . $projectionAttribute->name . ":" . $lifecycleClassname);
        }
        return $projectionLifecycleMessageProcessorActivatorBuilderMap;
    }

    /**
     * @param Configuration $messagingConfiguration
     * @param array<string, MessageProcessorActivatorBuilder> $lifecycleHandlers key is projection name, value is MessageProcessorActivatorBuilder for lifecycle handler
     * @return array<string, string> key is projection name, value is channel name
     */
    private static function registerProjectionLifeCycleHandlers(Configuration $messagingConfiguration, array $lifecycleHandlers): array {
        $channelsMap = [];
        foreach ($lifecycleHandlers as $projectionName => $lifecycleHandler) {
            $channelsMap[$projectionName] = $lifecycleHandler->getInputMessageChannelName();
            $messagingConfiguration->registerMessageHandler($lifecycleHandler);
        }
        return $channelsMap;
    }
}