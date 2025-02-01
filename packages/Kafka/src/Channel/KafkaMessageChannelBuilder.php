<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Channel;

use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapterBuilder;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;

/**
 * licence Enterprise
 */
final class KafkaMessageChannelBuilder implements MessageChannelWithSerializationBuilder
{
    private KafkaInboundChannelAdapterBuilder $inboundChannelAdapterBuilder;
    private KafkaOutboundChannelAdapterBuilder $outboundChannelAdapterBuilder;
    private string $headerMapper;
    /**
     * This is not passed to outboundChannelAdapter, as it's used in Kafka Module to declare Producer
     */
    private ?MediaType $conversionMediaType = null;

    private function __construct(
        private string         $channelName,
        public readonly string $topicName,
        public readonly string $groupId,
        int             $receiveTimeoutInMilliseconds = KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT,
    ) {
        $this->inboundChannelAdapterBuilder = KafkaInboundChannelAdapterBuilder::create($channelName)
            ->withReceiveTimeout($receiveTimeoutInMilliseconds);
        $this->outboundChannelAdapterBuilder = KafkaOutboundChannelAdapterBuilder::create($channelName);

        $this->headerMapper = '*';
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            KafkaMessageChannel::class,
            [
                $this->inboundChannelAdapterBuilder
                    ->compile($builder),
                $this->outboundChannelAdapterBuilder
                    ->compile($builder),
            ]
        );
    }

    public static function create(
        string  $channelName,
        ?string $topicName = null,
        ?string $groupId = null
    ): self {
        return new self(
            $channelName,
            $topicName ?? $channelName,
            $groupId ?? $channelName,
        );
    }

    public function getConversionMediaType(): ?MediaType
    {
        return $this->conversionMediaType;
    }

    public function getHeaderMapper(): HeaderMapper
    {
        $headerMapper = explode(',', $this->headerMapper);

        return DefaultHeaderMapper::createWith($headerMapper, $headerMapper);
    }

    public function withHeaderMapping(string $headerMapper): self
    {
        $this->headerMapper = $headerMapper;

        return $this;
    }

    /**
     * How long it should try to receive message
     *
     * @param int $timeoutInMilliseconds
     * @return static
     */
    public function withReceiveTimeout(int $timeoutInMilliseconds): self
    {
        $this->inboundChannelAdapterBuilder->withReceiveTimeout($timeoutInMilliseconds);

        return $this;
    }

    public function withDefaultConversionMediaType(string $mediaType): self
    {
        $this->conversionMediaType = MediaType::parseMediaType($mediaType);

        return $this;
    }


    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }
}
