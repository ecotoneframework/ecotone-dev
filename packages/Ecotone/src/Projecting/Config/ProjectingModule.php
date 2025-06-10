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
use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerBuilder;
use Ecotone\Messaging\Handler\Router\RouterProcessor;
use Ecotone\Messaging\Handler\Router\RouteToChannelResolver;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\NamedEvent;
use Ecotone\Modelling\Config\Routing\BusRouteSelector;
use Ecotone\Modelling\Config\Routing\BusRoutingKeyResolver;
use Ecotone\Modelling\Config\Routing\BusRoutingMapBuilder;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\Config\ProjectionBuilder\ProjectionBuilder;
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
use Ramsey\Uuid\Uuid;

#[ModuleAnnotation]
class ProjectingModule implements AnnotationModule
{
    /**
     * @param array<string, ProjectionBuilder> $configuredProjections
     * @param array<string, string> $namedEvents key is class name, value is event name
     * @param array<string, MessageProcessorActivatorBuilder> $projectionInitHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionResetHandlers
     * @param array<string, MessageProcessorActivatorBuilder> $projectionDeleteHandlers
     */
    public function __construct(
        private array $configuredProjections,
        private array $namedEvents,
        private array $projectionInitHandlers,
        private array $projectionResetHandlers,
        private array $projectionDeleteHandlers
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
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
        /** @var array<string, AnnotatedDefinition[]> $eventHandlersByProjectionName */
        $eventHandlersByProjectionName = [];
        foreach ($annotationRegistrationService->findCombined(Projection::class, EventHandler::class) as $projectionEventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();
            if ($projectionAttribute->disableDefaultProjectionHandler) {
                continue;
            }
            $eventHandlersByProjectionName[$projectionAttribute->name][] = $projectionEventHandler;
        }

        /** @var array<string, ProjectionBuilder> $configuredProjections */
        $configuredProjections = [];
        foreach ($eventHandlersByProjectionName as $projectionName => $projectionEventHandlers) {
            $configuredProjections[$projectionName] = new ProjectionBuilder(
                $projectionName,
                $projectionEventHandlers,
                $projectionEventHandlers[0]->getAnnotationForClass()->partitionHeaderName ?? null,
                $asynchronousProjectionChannels[$projectionName] ?? null
            );
        }

        $namedEvents = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(NamedEvent::class) as $className) {
            $attribute = $annotationRegistrationService->getAttributeForClass($className, NamedEvent::class);
            $namedEvents[$className] = $attribute->getName();
        }

        return new self($configuredProjections, $namedEvents, $projectionInitHandlers, $projectionResetHandlers, $projectionDeleteHandlers);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $streamSourceBuilders = ExtensionObjectResolver::resolve(StreamSourceBuilder::class, $extensionObjects);

        $projectionNames = array_keys($this->configuredProjections);

        /** @var array<string, string> $projectionNameToStreamSourceReferenceMap */
        $projectionNameToStreamSourceReferenceMap = [];
        foreach ($streamSourceBuilders as $streamSourceBuilder) {
            $reference = Uuid::uuid4()->toString();
            $moduleReferenceSearchService->store($reference, $streamSourceBuilder);
            foreach ($projectionNames as $projectionName) {
                if ($streamSourceBuilder->canHandle($projectionName)) {
                    if (isset($projectionNameToStreamSourceReferenceMap[$projectionName])) {
                        throw ConfigurationException::create(
                            "Projection with name {$projectionName} is already registered for stream source with reference {$projectionNameToStreamSourceReferenceMap[$projectionName]}."
                            . " You can only register one stream source per projection. Please check your configuration."
                        );
                    }
                    $projectionNameToStreamSourceReferenceMap[$projectionName] = $reference;
                    break;
                }
            }
        }

        foreach ($this->configuredProjections as $projectionName => $projectionBuilder) {
            if (!isset($projectionNameToStreamSourceReferenceMap[$projectionName])) {
                throw ConfigurationException::create(
                    "Projection with name {$projectionName} is not registered for any stream source. Please check your configuration."
                );
            }

            $projector = new Definition(EcotoneProjectorExecutor::class, [
                new Reference(MessagingEntrypointWithHeadersPropagation::class), // Headers propagation is required for EventStreamEmitter
                self::inputChannelForExecutionRouter($projectionName),
                $projectionName,
            ]);

            $projectingManager = new Definition(ProjectingManager::class, [
                new Reference(ProjectionStateStorage::class),
                new Reference(LifecycleManager::class),
                $projector,
                new Reference($projectionNameToStreamSourceReferenceMap[$projectionName]),
                $projectionName,
            ]);

            $messagingConfiguration->registerMessageHandler(
                MessageProcessorActivatorBuilder::create()
                    ->chainInterceptedProcessor(
                        MethodInvokerBuilder::create(
                            $projectingManager,
                            InterfaceToCallReference::create(ProjectingManager::class, 'execute'),
                            [
                                $projectionBuilder->partitionHeaderName
                                    ? HeaderBuilder::create('partitionKey', $projectionBuilder->partitionHeaderName)
                                    : ValueBuilder::create('partitionKey', null),
                            ],
                        )
                    )
                    ->withEndpointId($this->endpointIdForProjection($projectionName))
                    ->withInputChannelName($this->inputChannelForProjectingManager($projectionName))
            );

            /**  Router for executing events */
            $routerMap = new BusRoutingMapBuilder();
            foreach ($projectionBuilder->projectionEventHandlers as $eventHandler) {
                $routerMap->addRoutesFromAnnotatedFinding($eventHandler, $interfaceToCallRegistry);
            }
            foreach ($this->namedEvents as $className => $eventName) {
                $routerMap->addObjectAlias($className, $eventName);
            }
            $messagingConfiguration->registerMessageHandler(
                MessageProcessorActivatorBuilder::create()
                    ->withInputChannelName(self::inputChannelForExecutionRouter($projectionName))
                    ->chain(new Definition(RouterProcessor::class, [
                        new Definition(BusRouteSelector::class, [
                            $routerMap->compile(),
                            new Definition(BusRoutingKeyResolver::class, [ProjectionEventHandler::PROJECTION_EVENT_NAME]),
                        ]),
                        new Definition(RouteToChannelResolver::class, [new Reference(ChannelResolver::class)]),
                        false,
                    ]))
            );

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
                $projectionNames,
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
            || $extensionObject instanceof StreamSourceBuilder;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions, ?InterfaceToCallRegistry $interfaceToCallRegistry = null): array
    {
        return [new ProjectingModuleRoutingExtension(self::inputChannelForProjectingManager(...))];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    private static function endpointIdForProjection(string $projectionName): string
    {
        return 'projecting_manager_endpoint:' . $projectionName;
    }

    private static function inputChannelForProjectingManager(string $projectionName): string
    {
        return 'projecting_manager_handler:' . $projectionName;
    }

    private static function inputChannelForExecutionRouter(string $projectionName): string
    {
        return 'projecting_execution_router:' . $projectionName;
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