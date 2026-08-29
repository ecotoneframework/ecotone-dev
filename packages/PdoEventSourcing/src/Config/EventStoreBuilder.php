<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;

/**
 * licence Apache-2.0
 */
class EventStoreBuilder extends InputOutputMessageHandlerBuilder
{
    private function __construct(private string $methodName, private array $parameterConverters, EventSourcingConfiguration $eventSourcingConfiguration, private Reference $eventStoreReference)
    {
        $this->inputMessageChannelName = $eventSourcingConfiguration->getEventStoreReferenceName() . $methodName;
    }

    /**
     * @param ParameterConverterBuilder[] $parameterConverters
     */
    public static function create(string $methodName, array $parameterConverters, EventSourcingConfiguration $eventSourcingConfiguration, Reference $eventStoreReference): static
    {
        return new self($methodName, $parameterConverters, $eventSourcingConfiguration, $eventStoreReference);
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(EventStore::class, $this->methodName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return ServiceActivatorBuilder::create(
            $this->eventStoreReference->getId(),
            new InterfaceToCallReference(EventStore::class, $this->methodName)
        )
            ->withMethodParameterConverters($this->parameterConverters)
            ->withInputChannelName($this->getInputMessageChannelName())
            ->compile($builder);
    }
}
