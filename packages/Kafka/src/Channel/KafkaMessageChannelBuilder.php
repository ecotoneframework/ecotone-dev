<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Channel;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapterBuilder;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ramsey\Uuid\Uuid;

/**
 * licence Enterprise
 */
final class KafkaMessageChannelBuilder implements MessageChannelBuilder
{
    private function __construct(
        private string                      $channelName,
        public readonly string                      $topicName,
        public readonly string                      $groupId,
    )
    {
    }

    public static function create(
        string $channelName,
        ?string $topicName = null,
        ?string $groupId = null
    ): self
    {
        return new self(
            $channelName,
            $topicName ?? $channelName,
            $groupId ?? $channelName,
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            KafkaMessageChannel::class,
            [
                KafkaInboundChannelAdapterBuilder::create(
                    $this->channelName,
                )->compile($builder),
                KafkaOutboundChannelAdapterBuilder::create(
                    $this->channelName,
                )->compile($builder)
            ]
        );
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