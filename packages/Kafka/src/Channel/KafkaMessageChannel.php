<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Channel;

use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapter;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapter;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;

/**
 * licence Enterprise
 */
final class KafkaMessageChannel implements PollableChannel
{
    public function __construct(
        private KafkaInboundChannelAdapter $inboundChannelAdapter,
        private KafkaOutboundChannelAdapter $outboundChannelAdapter,
    ) {

    }

    public function send(Message $message): void
    {
        $this->outboundChannelAdapter->handle($message);
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout($pollingMetadata);
    }

    public function onConsumerStop(): void
    {
        $this->inboundChannelAdapter->onConsumerStop();
    }

    public function receive(): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout(PollingMetadata::create(
            $this->inboundChannelAdapter->channelName
        )->setFixedRateInMilliseconds(KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT));
    }
}
