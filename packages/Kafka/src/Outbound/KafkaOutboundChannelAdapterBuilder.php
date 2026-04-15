<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\MessageConverter\HeaderMapper;

/**
 * licence Enterprise
 */
class KafkaOutboundChannelAdapterBuilder implements MessageHandlerBuilder
{
    private ?MediaType $defaultConversionMediaType = null;

    private HeaderMapper $headerMapper;

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

    public function withDefaultConversionMediaType(?string $mediaType): self
    {
        $this->defaultConversionMediaType = MediaType::parseMediaType($mediaType);

        return $this;
    }

    public function getDefaultConversionMediaType(): ?MediaType
    {
        return $this->defaultConversionMediaType;
    }

    public function withHeaderMapper(HeaderMapper $headerMapper): self
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        return $this->headerMapper;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $outboundMessageConverter = new Definition(OutboundMessageConverter::class, [
            $this->headerMapper,
            $this->defaultConversionMediaType,
        ]);

        return new Definition(KafkaOutboundChannelAdapter::class, [
            $this->endpointId,
            new Reference(KafkaAdmin::class),
            new Reference(ConversionService::REFERENCE_NAME),
            $outboundMessageConverter,
        ]);
    }

    public function __toString(): string
    {
        return KafkaOutboundChannelAdapter::class;
    }
}
