<?php

namespace Ecotone\Messaging\Endpoint\MessagePoller;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

class InvocationPoller implements MessagePoller
{
    public function __construct(private object $serviceToCall, private string $methodName)
    {
    }

    public function poll(PollingMetadata $pollingMetadata): ?Message
    {
        $result = $this->serviceToCall->{$this->methodName}();
        if ($result === null) {
            return null;
        }
        $message = $result instanceof Message
            ? MessageBuilder::fromMessage($result)
            : MessageBuilder::withPayload($result);

        return $message
            ->setHeader(MessageHeaders::CONSUMER_POLLING_METADATA, $pollingMetadata)
            ->build();
    }
}