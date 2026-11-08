<?php

namespace Ecotone\Modelling\MessageHandling\Distribution\Module;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Modelling\Config\Routing\BusRoutingMapBuilder;
use Ecotone\Modelling\MessageHandling\Distribution\DistributedMessageHandler;
use Ecotone\Modelling\MessageHandling\Distribution\DistributionEntrypoint;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DistributedHandlerModule implements AnnotationModule
{
    /**
     * @param list<string> $distributedHandlers
     */
    public function __construct(
        private BusRoutingMapBuilder $commandBusDistributedRoutes,
        private BusRoutingMapBuilder $eventBusDistributedRoutes,
        private array $distributedHandlers,
    ) {
    }

    public static function create(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $distributedHandlers = [];
        $commandBusDistributedRoutes = new BusRoutingMapBuilder();
        foreach ($annotationFinder->findAnnotatedMethods(CommandHandler::class) as $registration) {
            if ($registration->hasAnnotation(Distributed::class)) {
                $commandBusDistributedRoutes->addRoutesFromAnnotatedFinding($registration, $interfaceToCallRegistry);
                $distributedHandlers[] = PulseFeature::handlerOf($registration);
            }
        }

        $eventBusDistributedRoutes = new BusRoutingMapBuilder();
        foreach ($annotationFinder->findAnnotatedMethods(EventHandler::class) as $registration) {
            if ($registration->hasAnnotation(Distributed::class)) {
                $eventBusDistributedRoutes->addRoutesFromAnnotatedFinding($registration, $interfaceToCallRegistry);
                $distributedHandlers[] = PulseFeature::handlerOf($registration);
            }
        }
        return new self(commandBusDistributedRoutes: $commandBusDistributedRoutes, eventBusDistributedRoutes: $eventBusDistributedRoutes, distributedHandlers: $distributedHandlers);
    }

    /**
     * @return array<string>
     */
    public function getDistributedEventHandlerRoutes(): array
    {
        return $this->eventBusDistributedRoutes->getRoutingKeys();
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [$this];
        }

        return [$this, new PulseFeature(
            package: ModulePackageList::CORE_PACKAGE,
            name: 'Distributed Bus',
            enabled: $this->distributedHandlers !== [],
            settings: ['Distributed handlers' => (string) count($this->distributedHandlers)],
            handlers: $this->distributedHandlers,
            hint: $this->distributedHandlers === []
                ? 'No handler of this service is marked #[Distributed], so other services cannot send it commands or events over the Distributed Bus. It can still send to them.'
                : sprintf('Other services can send this service the %d commands and events of the handlers listed here over the Distributed Bus, which routes them by the DistributedServiceMap of each sender. The Topology page shows the whole map.', count($this->distributedHandlers)),
            configuration: "#[Distributed] next to #[CommandHandler('order.place')] or #[EventHandler('order.placed')], and DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'orders', channelName: 'orders') in the sending service",
        )];
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(DistributionEntrypoint::class, DistributionEntrypoint::class, 'distribute', DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE)
                ->withParameterConverters([
                    GatewayPayloadBuilder::create('payload'),
                    GatewayHeadersBuilder::create('metadata'),
                    GatewayHeaderBuilder::create('payloadType', DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE),
                    GatewayHeaderBuilder::create('routingKey', DistributedBusHeader::DISTRIBUTED_ROUTING_KEY),
                    GatewayHeaderBuilder::create('mediaType', MessageHeaders::CONTENT_TYPE),
                ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(DistributionEntrypoint::class, DistributionEntrypoint::class, 'distributeMessage', DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE)
                ->withParameterConverters([
                    GatewayPayloadBuilder::create('payload'),
                    GatewayHeadersBuilder::create('metadata'),
                    GatewayHeaderBuilder::create('mediaType', MessageHeaders::CONTENT_TYPE),
                ])
        );
        $messagingConfiguration->registerServiceDefinition(
            DistributedMessageHandler::class,
            new Definition(DistributedMessageHandler::class, [
                $this->eventBusDistributedRoutes->compile(),
                $this->commandBusDistributedRoutes->compile(),
                $serviceConfiguration->serviceName,
            ])
        );
        $messagingConfiguration->registerMessageHandler(
            MessageProcessorActivatorBuilder::createForMethod(DistributedMessageHandler::class, $interfaceToCallRegistry->getFor(DistributedMessageHandler::class, 'handle'))
                ->withInputChannelName(DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE)
                ->withMethodParameterConverters([
                    PayloadBuilder::create('payload'),
                    AllHeadersBuilder::createWith('metadata'),
                    HeaderBuilder::create('payloadType', DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE),
                    HeaderBuilder::create('routingKey', DistributedBusHeader::DISTRIBUTED_ROUTING_KEY),
                    HeaderBuilder::create('contentType', MessageHeaders::CONTENT_TYPE),
                    HeaderBuilder::createOptional('targetedServiceName', DistributedBusHeader::DISTRIBUTED_TARGET_SERVICE_NAME),
                    HeaderBuilder::createOptional('replyRoutingKey', DistributedBusHeader::DISTRIBUTED_REPLY_ROUTING_KEY),
                    ReferenceBuilder::create('commandBus', CommandBus::class),
                    ReferenceBuilder::create('eventBus', EventBus::class),
                    ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointService::class),
                ])
        );
        $messagingConfiguration->registerMessageHandler(
            MessageProcessorActivatorBuilder::createForMethod(DistributedMessageHandler::class, $interfaceToCallRegistry->getFor(DistributedMessageHandler::class, 'reply'))
                ->withInputChannelName(DistributedMessageHandler::REPLY_CHANNEL)
                ->withMethodParameterConverters([
                    PayloadBuilder::create('result'),
                    AllHeadersBuilder::createWith('metadata'),
                    HeaderBuilder::create('replyRoutingKey', DistributedBusHeader::DISTRIBUTED_REPLY_ROUTING_KEY),
                    ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointService::class),
                ])
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
