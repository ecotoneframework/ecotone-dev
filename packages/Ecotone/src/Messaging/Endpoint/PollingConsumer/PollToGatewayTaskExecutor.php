<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePoller;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;

class PollToGatewayTaskExecutor implements TaskExecutor
{
    public function __construct(
        private MessagePoller $messagePoller,
        private NonProxyGateway $gateway,
        private MessagingEntrypoint $messagingEntrypoint
    ) {
    }

    public function execute(PollingMetadata $pollingMetadata): void
    {
        try {
            $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::ENABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);

            $message = $this->messagePoller->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds());
            if ($message) {
                $message = MessageBuilder::fromMessage($message)
                    ->setHeader(MessageHeaders::CONSUMER_POLLING_METADATA, $pollingMetadata)
                    ->build();
                $this->gateway->execute([$message]);
            }
        } finally {
            $this->messagingEntrypoint->send([], MessageHeadersPropagatorInterceptor::DISABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT);
        }
    }
}
