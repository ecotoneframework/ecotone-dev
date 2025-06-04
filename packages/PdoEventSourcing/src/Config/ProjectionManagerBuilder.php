<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionSetupConfiguration;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;

/**
 * licence Apache-2.0
 */
class ProjectionManagerBuilder extends InputOutputMessageHandlerBuilder
{
    /**
     * @param ParameterConverterBuilder[] $parameterConverters
     */
    private function __construct(
        private string $methodName,
        private array $parameterConverters,
        private EventSourcingConfiguration $eventSourcingConfiguration,
    ) {
    }

    /**
     * @param ParameterConverterBuilder[] $parameterConverters
     * @param ProjectionSetupConfiguration[] $projectionSetupConfigurations
     */
    public static function create(
        string $methodName,
        array $parameterConverters,
        EventSourcingConfiguration $eventSourcingConfiguration,
    ): static {
        return new self($methodName, $parameterConverters, $eventSourcingConfiguration);
    }

    public function getInputMessageChannelName(): string
    {
        return $this->getProjectionManagerActionChannel($this->eventSourcingConfiguration->getProjectManagerReferenceName(), $this->methodName);
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(LazyProophProjectionManager::class, $this->methodName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return ServiceActivatorBuilder::create(LazyProophProjectionManager::class, new InterfaceToCallReference(LazyProophProjectionManager::class, $this->methodName))
            ->withMethodParameterConverters($this->parameterConverters)
            ->withInputChannelName($this->getInputMessageChannelName())
            ->compile($builder);
    }

    public static function getProjectionManagerActionChannel(string $projectionManagerReference, string $methodName): string
    {
        return $projectionManagerReference . $methodName;
    }
}
