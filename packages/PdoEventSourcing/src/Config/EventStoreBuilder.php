<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\EventMapper;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;

class EventStoreBuilder extends InputOutputMessageHandlerBuilder
{
    private string $methodName;
    private EventSourcingConfiguration $eventSourcingConfiguration;
    private array $parameterConverters;

    private function __construct(string $methodName, array $parameterConverters, EventSourcingConfiguration $eventSourcingConfiguration)
    {
        $this->methodName = $methodName;
        $this->parameterConverters = $parameterConverters;
        $this->eventSourcingConfiguration = $eventSourcingConfiguration;
        $this->inputMessageChannelName = $this->eventSourcingConfiguration->getEventStoreReferenceName() . $this->methodName;
    }

    /**
     * @param ParameterConverterBuilder[] $parameterConverters
     */
    public static function create(string $methodName, array $parameterConverters, EventSourcingConfiguration $eventSourcingConfiguration): static
    {
        return new self($methodName, $parameterConverters, $eventSourcingConfiguration);
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(EcotoneEventStoreProophWrapper::class, $this->methodName);
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        $eventStoreProophWrapper = new Definition(EcotoneEventStoreProophWrapper::class, [
            new Definition(LazyProophEventStore::class, [
                new Reference(EventSourcingConfiguration::class),
                new Reference(ReferenceSearchService::class),
                new Reference(EventMapper::class),
            ]),
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(EventMapper::class)
        ], 'prepare');

        return ServiceActivatorBuilder::createWithDefinition($eventStoreProophWrapper, $this->methodName)
            ->withMethodParameterConverters($this->parameterConverters)
            ->withInputChannelName($this->getInputMessageChannelName())
            ->compile($builder);
    }
}
