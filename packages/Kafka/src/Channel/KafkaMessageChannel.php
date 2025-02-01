<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Channel;

use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapter;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapter;
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

    public function receiveWithTimeout(int $timeoutInMilliseconds = 0): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout($timeoutInMilliseconds);
    }

    public function receive(): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout(KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT);
    }
}
