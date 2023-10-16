<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\EventMapper;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
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
    private function __construct(private string $methodName, private array $parameterConverters, private EventSourcingConfiguration $eventSourcingConfiguration, private Reference $eventSourcingConfigurationReference)
    {
        $this->inputMessageChannelName = $eventSourcingConfiguration->getEventStoreReferenceName() . $methodName;
    }

    /**
     * @param ParameterConverterBuilder[] $parameterConverters
     */
    public static function create(string $methodName, array $parameterConverters, EventSourcingConfiguration $eventSourcingConfiguration, Reference $eventSourcingConfigurationReference): static
    {
        return new self($methodName, $parameterConverters, $eventSourcingConfiguration, $eventSourcingConfigurationReference);
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(EcotoneEventStoreProophWrapper::class, $this->methodName);
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        $eventStoreProophWrapper = new Definition(EcotoneEventStoreProophWrapper::class, [
            new Reference(LazyProophEventStore::class),
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(EventMapper::class)
        ], 'prepare');

        return ServiceActivatorBuilder::createWithDefinition($eventStoreProophWrapper, $this->methodName)
            ->withMethodParameterConverters($this->parameterConverters)
            ->withInputChannelName($this->getInputMessageChannelName())
            ->compile($builder);
    }
}
