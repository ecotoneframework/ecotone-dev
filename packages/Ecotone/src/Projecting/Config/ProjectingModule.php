<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
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
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\MessageHandlerRoutingModule;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\Config\ProjectionBuilder\ProjectionBuilder;
use Ecotone\Projecting\Config\ProjectionBuilder\ProjectionEventHandlerConfiguration;
use Ecotone\Projecting\Dbal\DbalProjectionLifecycleStateStorage;
use Ecotone\Projecting\Dbal\DbalProjectionStateStorage;
use Ecotone\Projecting\EcotoneProjectorExecutor;
use Ecotone\Projecting\InMemory\InMemoryProjectionLifecycleStateStorage;
use Ecotone\Projecting\InMemory\InMemoryProjectionStateStorage;
use Ecotone\Projecting\Lifecycle\EcotoneLifecycleExecutor;
use Ecotone\Projecting\Lifecycle\LifecycleManager;
use Ecotone\Projecting\Lifecycle\ProjectionLifecycleStateStorage;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionStateStorage;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
class ProjectingModule implements AnnotationModule
{
    /**
     * @param AnnotatedDefinition[] $projectionEventHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionInitHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionResetHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionDeleteHandlers
     * @param array<string, string> $asynchronousProjectionChannels key is projection name - value is channel name
     */
    public function __construct(
        private array $projectionEventHandlers,
        private array $projectionInitHandlers,
        private array $projectionResetHandlers,
        private array $projectionDeleteHandlers,
        private array $asynchronousProjectionChannels,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $projectionEventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);
        $projectionInitHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionInitialization::class);
        $projectionResetHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionReset::class);
        $projectionDeleteHandlers = self::buildProjectionLifeCycleHandlers($annotationRegistrationService, ProjectionDelete::class);

        /** @var array<string, string> $asynchronousProjectionChannels */
        $asynchronousProjectionChannels = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(Projection::class) as $projectionClassName) {
            $asynchronousChannelName = self::getProjectionAsynchronousChannel($annotationRegistrationService, $projectionClassName);
            if ($asynchronousChannelName !== null) {
                $projectionAttribute = $annotationRegistrationService->getAttributeForClass($projectionClassName, Projection::class);
                $asynchronousProjectionChannels[$projectionAttribute->name] = $asynchronousChannelName;
            }
        }
        return new self(
            $projectionEventHandlers,
            $projectionInitHandlers,
            $projectionResetHandlers,
            $projectionDeleteHandlers,
            $asynchronousProjectionChannels,
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $projectionBuilders = ExtensionObjectResolver::resolve(ProjectionBuilder::class, $extensionObjects);

        foreach ($projectionBuilders as $projectionBuilder) {
            $projectionName = $projectionBuilder->projectionName;
            $projector = new Definition(EcotoneProjectorExecutor::class, [
                new Reference(MessagingEntrypoint::class),
                $projectionBuilder->projectionEventHandlers,
            ]);

            $projectingManager = new Definition(ProjectingManager::class, [
                new Reference(ProjectionStateStorage::class),
                new Reference(LifecycleManager::class),
                $projector,
                new Reference($projectionBuilder->streamSourceReferenceName),
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

            // Connect projection trigger channel to event bus
            foreach ($projectionBuilder->projectionEventTriggers as $eventName => $priority) {
                $messagingConfiguration->registerMessageHandler(
                    BridgeBuilder::create()
                        ->withInputChannelName($eventName)
                        ->withOutputMessageChannel($this->inputChannelForProjectingManager($projectionName))
                        ->withEndpointAnnotations([$priority->toAttributeDefinition()])
                );
            }

            // Should the projection be triggered asynchronously?
            if (
                $serviceConfiguration->isModulePackageEnabled(ModulePackageList::ASYNCHRONOUS_PACKAGE)
                && $projectionBuilder->asynchronousChannelName !== null
            ) {
                $messagingConfiguration->registerAsynchronousEndpoint(
                    $projectionBuilder->asynchronousChannelName,
                    $this->endpointIdForProjection($projectionName),
                );
            }
        }

        // Register projection state implementations
        $projectingConfiguration = ExtensionObjectResolver::resolveUnique(ProjectingConfiguration::class, $extensionObjects, ProjectingConfiguration::createInMemory());
        $messagingConfiguration->registerServiceDefinition(
            ProjectionStateStorage::class,
            match ($projectingConfiguration->projectionStateStorageReference) {
                InMemoryProjectionStateStorage::class => new Definition(InMemoryProjectionStateStorage::class),
                DbalProjectionStateStorage::class => new Definition(DbalProjectionStateStorage::class, [new Reference(DbalConnectionFactory::class)]),
                default => new Reference($projectingConfiguration->projectionStateStorageReference)
            }
        );

        $messagingConfiguration->registerServiceDefinition(
            ProjectionLifecycleStateStorage::class,
            match ($projectingConfiguration->projectionLifecycleStateStorageReference) {
                InMemoryProjectionLifecycleStateStorage::class => new Definition(InMemoryProjectionLifecycleStateStorage::class),
                DbalProjectionLifecycleStateStorage::class => new Definition(DbalProjectionLifecycleStateStorage::class, [new Reference(DbalConnectionFactory::class)]),
                default => new Reference($projectingConfiguration->projectionLifecycleStateStorageReference)
            }
        );

        // Lifecycle handlers
        $ecotoneLifecycleExecutor = new Definition(EcotoneLifecycleExecutor::class, [
            new Reference(MessagingEntrypoint::class),
            self::registerProjectionLifecycleHandlers($messagingConfiguration, $this->projectionInitHandlers),
            self::registerProjectionLifecycleHandlers($messagingConfiguration, $this->projectionResetHandlers),
            self::registerProjectionLifecycleHandlers($messagingConfiguration, $this->projectionDeleteHandlers),
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
            || $extensionObject instanceof ProjectingConfiguration
            || $extensionObject instanceof StreamSourceBuilder
            || $extensionObject instanceof ProjectionBuilder;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions, ?InterfaceToCallRegistry $interfaceToCallRegistry = null): array
    {
        /** @var array<string, array<string, string>> $eventToChannelMapping first key is projection name - key is event name - value is channel name */
        $eventToChannelMapping = [];

        /** @var array<string, Projection> $projectionAttributes */
        $projectionAttributes = [];
        $projectionsEventHandlersConfiguration = [];
        $projectionsEventTriggeringPriority = [];

        foreach ($this->projectionEventHandlers as $projectionEventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();

            $eventName = MessageHandlerRoutingModule::getRoutingInputMessageChannelForEventHandler($projectionEventHandler, $interfaceToCallRegistry);
            $eventHandlerInputChannel = MessageHandlerRoutingModule::getExecutionMessageHandlerChannel($projectionEventHandler);
            $isReturningUserState = $interfaceToCallRegistry->getFor($projectionEventHandler->getClassName(), $projectionEventHandler->getMethodName())->canReturnValue();

            $eventToChannelMapping[$projectionAttribute->name][$eventName] = $eventHandlerInputChannel;
            $projectionAttributes[$projectionAttribute->name] = $projectionAttribute;
            $projectionsEventHandlersConfiguration[$projectionAttribute->name][$eventName] = new ProjectionEventHandlerConfiguration(
                $eventHandlerInputChannel,
                $isReturningUserState,
            );
            $projectionsEventTriggeringPriority[$projectionAttribute->name][$eventName] = PriorityBasedOnType::fromAnnotatedFinding($projectionEventHandler);
        }

        $projectionBuilders = [];
        foreach ($eventToChannelMapping as $projectionName => $eventToChannelMap) {
            $projectionBuilders[] = new ProjectionBuilder(
                $projectionName,
                $projectionAttributes[$projectionName]->streamSourceReference,
                $projectionsEventHandlersConfiguration[$projectionName],
                $this->asynchronousProjectionChannels[$projectionName],
                $projectionsEventTriggeringPriority[$projectionName],
            );
        }

        return $projectionBuilders;
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
    private static function registerProjectionLifecycleHandlers(Configuration $messagingConfiguration, array $lifecycleHandlers): array {
        $channelsMap = [];
        foreach ($lifecycleHandlers as $projectionName => $lifecycleHandler) {
            $channelsMap[$projectionName] = $lifecycleHandler->getInputMessageChannelName();
            $messagingConfiguration->registerMessageHandler($lifecycleHandler);
        }
        return $channelsMap;
    }

    /**
     * @param class-string $projectionClassName
     */
    private static function getProjectionAsynchronousChannel(AnnotationFinder $annotationRegistrationService, string $projectionClassName): ?string
    {
        $attributes = $annotationRegistrationService->getAnnotationsForClass($projectionClassName);
        foreach ($attributes as $attribute) {
            if ($attribute instanceof Asynchronous) {
                $asynchronousChannelName = $attribute->getChannelName();
                Assert::isTrue(count($asynchronousChannelName) === 1, "Make use of single channel name in Asynchronous annotation for Projection: {$projectionClassName}");
                return array_pop($asynchronousChannelName);
            }
        }
        return null;
    }
}