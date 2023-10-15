<?php

namespace Ecotone\EventSourcing\Config\InboundChannelAdapter;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;

class ProjectionExecutorBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilder
{
    public function __construct(
        private EventSourcingConfiguration $eventSourcingConfiguration,
        private ProjectionSetupConfiguration $projectionSetupConfiguration,
        private array $projectSetupConfigurations,
        private ProjectionRunningConfiguration $projectionRunningConfiguration,
        private string $methodName
    ) {
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(ProjectionEventHandler::class, $this->methodName);
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        return ServiceActivatorBuilder::createWithDirectReference(
            new ProjectionEventHandler(
                new LazyProophProjectionManager($this->eventSourcingConfiguration, $this->projectSetupConfigurations, $referenceSearchService),
                $this->projectionSetupConfiguration,
                $this->projectionRunningConfiguration,
                $referenceSearchService->get(ConversionService::REFERENCE_NAME)
            ),
            $this->methodName
        )
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->withMethodParameterConverters([ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointWithHeadersPropagation::class)])
            ->build($channelResolver, $referenceSearchService);
    }

    public function compile(ContainerMessagingBuilder $builder): object|null
    {
        $projectionEventHandler =  new Definition(
            ProjectionEventHandler::class,
            [
                new Definition(LazyProophProjectionManager::class, [
                    $this->eventSourcingConfiguration,
                    $this->projectSetupConfigurations,
                    Reference::to(ReferenceSearchService::class),
                ]),
                $this->projectionSetupConfiguration,
                $this->projectionRunningConfiguration,
                Reference::to(ConversionService::REFERENCE_NAME)
            ]);

        return ServiceActivatorBuilder::createWithDefinition($projectionEventHandler, $this->methodName)
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->withMethodParameterConverters([ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointWithHeadersPropagation::class)])
            ->compile($builder);
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [
            $interfaceToCallRegistry->getFor(ProjectionEventHandler::class, $this->methodName),
        ];
    }

    public function getRequiredReferenceNames(): array
    {
        return [];
    }
}
