<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

class PollingMetadataConverterBuilder implements ParameterConverterBuilder
{
    public function __construct(private string $parameterName)
    {
    }

    public function compile(ContainerMessagingBuilder $builder, InterfaceToCall $interfaceToCall, InterfaceParameter $interfaceParameter): Reference
    {
        if (!$builder->has(PollingMetadataConverter::class)) {
            $builder->register(PollingMetadataConverter::class, new Definition(PollingMetadataConverter::class, [Reference::to(PollingConsumerContext::class)]));
        }
        return new Reference(PollingMetadataConverter::class);
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }
}