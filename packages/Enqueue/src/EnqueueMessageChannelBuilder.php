<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\MessageConverter\HeaderMapper;

/**
 * licence Apache-2.0
 */
abstract class EnqueueMessageChannelBuilder implements MessageChannelWithSerializationBuilder
{
    protected EnqueueInboundChannelAdapterBuilder $inboundChannelAdapter;
    protected EnqueueOutboundChannelAdapterBuilder $outboundChannelAdapter;

    public function __construct(EnqueueInboundChannelAdapterBuilder $inboundChannelAdapterBuilder, EnqueueOutboundChannelAdapterBuilder $outboundChannelAdapterBuilder)
    {
        $this->inboundChannelAdapter = $inboundChannelAdapterBuilder;
        $this->outboundChannelAdapter = $outboundChannelAdapterBuilder;

        $this->withHeaderMapping('*');
    }

    public function getInboundChannelAdapter(): EnqueueInboundChannelAdapterBuilder
    {
        return $this->inboundChannelAdapter;
    }

    public function getOutboundChannelAdapter(): EnqueueOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return false;
    }

    public function withHeaderMapping(string $headerMapper): self
    {
        $this->getInboundChannelAdapter()->withHeaderMapper($headerMapper);
        $this->getOutboundChannelAdapter()->withHeaderMapper($headerMapper);

        return $this;
    }

    public function withFinalFailureStrategy(FinalFailureStrategy $finalFailureStrategy): self
    {
        $this->getInboundChannelAdapter()->withFinalFailureStrategy($finalFailureStrategy);

        return $this;
    }

    public function withReceiveTimeout(int $timeoutInMilliseconds): self
    {
        $this->getInboundChannelAdapter()->withReceiveTimeout($timeoutInMilliseconds);

        return $this;
    }

    public function withDefaultTimeToLive(int $timeInMilliseconds): self
    {
        $this->getOutboundChannelAdapter()->withDefaultTimeToLive($timeInMilliseconds);

        return $this;
    }

    public function withDefaultDeliveryDelay(int $timeInMilliseconds): self
    {
        $this->getOutboundChannelAdapter()->withDefaultDeliveryDelay($timeInMilliseconds);

        return $this;
    }

    public function withDefaultConversionMediaType(string $mediaType): self
    {
        $this->getOutboundChannelAdapter()->withDefaultConversionMediaType($mediaType);

        return $this;
    }

    public function withAutoDeclare(bool $autoDeclare): self
    {
        $this->getInboundChannelAdapter()->withDeclareOnStartup($autoDeclare);
        $this->getOutboundChannelAdapter()->withAutoDeclareOnSend($autoDeclare);

        return $this;
    }

    public function getConversionMediaType(): ?MediaType
    {
        return $this->getOutboundChannelAdapter()->getDefaultConversionMediaType();
    }

    public function getMessageChannelName(): string
    {
        return $this->getInboundChannelAdapter()->getMessageChannelName();
    }

    public function getHeaderMapper(): HeaderMapper
    {
        /** Header Mappers are the same for inbound and outbound in case of Message Channel */
        return $this->getOutboundChannelAdapter()->getHeaderMapper();
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $serviceConfiguration = $builder->getServiceConfiguration();
        if (! $this->getConversionMediaType() && $serviceConfiguration->getDefaultSerializationMediaType()) {
            $this->withDefaultConversionMediaType($serviceConfiguration->getDefaultSerializationMediaType());
        }
        return new Definition(EnqueueMessageChannel::class, [
            $this->inboundChannelAdapter->compile($builder),
            $this->outboundChannelAdapter->compile($builder),
        ]);
    }
}
