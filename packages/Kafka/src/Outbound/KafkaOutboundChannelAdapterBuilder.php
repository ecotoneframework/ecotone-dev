<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;

/**
 * licence Enterprise
 */
class KafkaOutboundChannelAdapterBuilder implements MessageHandlerBuilder
{
    private function __construct(
        private string $endpointId,
        private string $inputChannelName = ''
    ) {

    }

    public static function create(string $endpointId): self
    {
        return new self(endpointId:  $endpointId);
    }

    public function withInputChannelName(string $inputChannelName)
    {
        $this->inputChannelName = $inputChannelName;

        return $this;
    }

    public function getEndpointId(): ?string
    {
        return $this->endpointId;
    }

    public function withEndpointId(string $endpointId)
    {
        $this->endpointId = $endpointId;

        return $this;
    }

    public function getInputMessageChannelName(): string
    {
        return $this->inputChannelName;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(KafkaOutboundChannelAdapter::class, [
            $this->endpointId,
            new Reference(KafkaAdmin::class),
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }

    public function __toString(): string
    {
        return KafkaOutboundChannelAdapter::class;
    }
}
