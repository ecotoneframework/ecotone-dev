<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageHandler;

final class UninterruptibleServiceActivator implements MessageHandlerBuilderWithParameterConverters
{
    private function __construct(private ServiceActivatorBuilder $serviceActivatorBuilder)
    {
    }

    public static function create(DefinedObject $objectToInvokeOnReferenceName, string $methodName): self
    {
        return new self(ServiceActivatorBuilder::createWithDirectReference($objectToInvokeOnReferenceName, $methodName));
    }

    /**
     * @param string $inputChannelName
     *
     * @return static
     */
    public function withInputChannelName(string $inputChannelName)
    {
        $this->serviceActivatorBuilder = $this->serviceActivatorBuilder->withInputChannelName($inputChannelName);

        return $this;
    }

    public function withOutputMessageChannel(string $outputChannelName)
    {
        $this->serviceActivatorBuilder = $this->serviceActivatorBuilder->withOutputMessageChannel($outputChannelName);

        return $this;
    }

    /**
     * @return string|null
     */
    public function getEndpointId(): ?string
    {
        return $this->serviceActivatorBuilder->getEndpointId();
    }

    /**
     * @param string $endpointId
     *
     * @return static
     */
    public function withEndpointId(string $endpointId)
    {
        $this->serviceActivatorBuilder = $this->serviceActivatorBuilder->withEndpointId($endpointId);

        return $this;
    }

    /**
     * @return string
     */
    public function getInputMessageChannelName(): string
    {
        return $this->serviceActivatorBuilder->getInputMessageChannelName();
    }

    /**
     * @param array|ParameterConverterBuilder[] $methodParameterConverterBuilders
     * @return static
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders)
    {
        $this->serviceActivatorBuilder = $this->serviceActivatorBuilder->withMethodParameterConverters($methodParameterConverterBuilders);

        return $this;
    }

    /**
     * @return ParameterConverterBuilder[]
     */
    public function getParameterConverters(): array
    {
        return $this->serviceActivatorBuilder->getParameterConverters();
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return $this->serviceActivatorBuilder->compile($builder);
    }

    public function __toString(): string
    {
        return (string)$this->serviceActivatorBuilder;
    }
}
