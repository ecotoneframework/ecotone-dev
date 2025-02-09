<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\ProophEventMapper;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
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
    private function __construct(private string $methodName, private array $parameterConverters, private EventSourcingConfiguration $eventSourcingConfiguration, private Reference $eventStoreReference)
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
        return $interfaceToCallRegistry->getFor(EcotoneEventStoreProophWrapper::class, $this->methodName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $eventStoreProophWrapper = new Definition(EcotoneEventStoreProophWrapper::class, [
            $this->eventStoreReference,
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(ProophEventMapper::class),
        ], 'prepare');

        return ServiceActivatorBuilder::createWithDefinition($eventStoreProophWrapper, $this->methodName)
            ->withMethodParameterConverters($this->parameterConverters)
            ->withInputChannelName($this->getInputMessageChannelName())
            ->compile($builder);
    }
}
