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

final class KafkaMessageChannelBuilder implements MessageChannelBuilder
{
    private function __construct(
        private string                      $channelName,
        private string                      $topicName,
        private string                      $groupId,
        private KafkaConsumerConfiguration  $consumerConfiguration,
        private KafkaPublisherConfiguration $publisherConfiguration,
    )
    {
    }

    /**
     * @param ?string $topicName If not provided, channel name will be used as topic name
     * @param ?string $groupId if not provided, channel name will be used as group id
     */
    public static function create(string $channelName, ?string $topicName = null, ?string $groupId = null, string $brokerConfigurationReference = KafkaBrokerConfiguration::class): self
    {
        return new self(
            $channelName,
            $topicName ?? $channelName,
            $groupId ?? $channelName,
            KafkaConsumerConfiguration::createWithDefaults($channelName, brokerConfigurationReference: $brokerConfigurationReference),
            KafkaPublisherConfiguration::createWithDefaults(brokerConfigurationReference: $brokerConfigurationReference)
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            KafkaMessageChannel::class,
            [
                KafkaInboundChannelAdapterBuilder::create(
                    $this->channelName,
                    [$this->topicName],
                    $this->channelName,
                    $this->groupId
                )->compile($builder),
                KafkaOutboundChannelAdapterBuilder::create(
                    $this->publisherConfiguration,
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