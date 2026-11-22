<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer\MessagePoller;

use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessagePoller;

/**
 * licence Apache-2.0
 */
final class ReceivedMessage implements MessagePoller
{
    public function __construct(private Message $message)
    {
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->message;
    }

    public function onConsumerStop(): void
    {
    }
}
