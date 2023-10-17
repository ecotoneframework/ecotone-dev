<?php

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Endpoint\MessagePoller\MessagePoller;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;

class PollToGatewayTaskExecutor implements TaskExecutor
{
    public function __construct(
        private MessagePoller $messagePoller,
        private NonProxyGateway $gateway,
    )
    {
    }

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $message = $this->messagePoller->poll($pollingMetadata);
        if ($message) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader()
                ->setHeader(MessageHeaders::CONSUMER_POLLING_METADATA, $pollingMetadata)
                ->build();
            $this->gateway->execute([$message]);
        }
    }
}