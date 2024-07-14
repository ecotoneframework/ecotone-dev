<?php

namespace Ecotone\EventSourcing\Config\InboundChannelAdapter;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;

/**
 * licence Apache-2.0
 */
class ProjectionExecutorBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilder
{
    public function __construct(
        private ProjectionSetupConfiguration $projectionSetupConfiguration,
        private array $projectSetupConfigurations,
        private string $methodName
    ) {
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(ProjectionEventHandler::class, $this->methodName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $projectionEventHandler =  new Definition(
            ProjectionEventHandler::class,
            [
                new Definition(LazyProophProjectionManager::class, [
                    Reference::to(EventSourcingConfiguration::class),
                    $this->projectSetupConfigurations,
                    Reference::to(ReferenceSearchService::class),
                    Reference::to(LazyProophEventStore::class),
                ]),
                $this->projectionSetupConfiguration,
                Reference::to(ConversionService::REFERENCE_NAME),
            ]
        );

        return ServiceActivatorBuilder::createWithDefinition($projectionEventHandler, $this->methodName)
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->withMethodParameterConverters([ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointWithHeadersPropagation::class)])
            ->compile($builder);
    }
}
