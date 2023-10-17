<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\MessagePoller;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class PollingConsumerTaskExecutor
 * @package Ecotone\Messaging\Endpoint\PollingConsumer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ChannelPoller implements MessagePoller
{
    public function __construct(private string $pollableChannelName, private PollableChannel $pollableChannel)
    {
    }

    public function poll(PollingMetadata $pollingMetadata): ?Message
    {
        $message = $pollingMetadata->getExecutionTimeLimitInMilliseconds()
            ? $this->pollableChannel->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds())
            : $this->pollableChannel->receive();

        if ($message) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $this->pollableChannelName)
                ->setHeader(MessageHeaders::CONSUMER_POLLING_METADATA, $pollingMetadata)
                ->build();
        }
        return $message;
    }
}
