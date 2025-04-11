<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\AnnotationFinder\AnnotatedDefinition;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Config\MessageHandlerRoutingModule;

#[ModuleAnnotation]
class ProjectingModule implements AnnotationModule
{
    /**
     * @param string[] $projectionClassNames
     * @param AnnotatedDefinition[] $projectionEventHandlers
     */
    public function __construct(
        private array $projectionClassNames,
        private array $projectionEventHandlers,
        private AsynchronousModule $asynchronousModule
    )
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $projectionClassNames = $annotationRegistrationService->findAnnotatedClasses(Projection::class);
        $projectionEventHandlers = $annotationRegistrationService->findCombined(Projection::class, EventHandler::class);

        return new self(
            $projectionClassNames,
            $projectionEventHandlers,
            AsynchronousModule::create($annotationRegistrationService, $interfaceToCallRegistry),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        /** @var array<string, array<string, string>> $eventToChannelMapping first key is projection name - key is event name - value is channel name */
        $eventToChannelMapping = [];

        foreach ($this->projectionEventHandlers as $projectionEventHandler) {
            /** @var Projection $projectionAttribute */
            $projectionAttribute = $projectionEventHandler->getAnnotationForClass();
            /** @var EventHandler $handlerAttribute */
            $handlerAttribute = $projectionEventHandler->getAnnotationForMethod();

            $eventName = MessageHandlerRoutingModule::getRoutingInputMessageChannelForEventHandler($projectionEventHandler, $interfaceToCallRegistry);
            $eventHandlerTriggeringInputChannel = MessageHandlerRoutingModule::getExecutionMessageHandlerChannel($projectionEventHandler);
            $eventHandlerSynchronousInputChannel = $serviceConfiguration->isModulePackageEnabled(ModulePackageList::ASYNCHRONOUS_PACKAGE) ? $this->asynchronousModule->getSynchronousChannelFor($eventHandlerTriggeringInputChannel, $handlerAttribute->getEndpointId()) : $eventHandlerTriggeringInputChannel;

            $eventToChannelMapping[$projectionAttribute->getName()][$eventName] = $eventHandlerSynchronousInputChannel;
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
}