<?php

namespace Test\Ecotone\Amqp\Fixture\SuccessTransaction;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * licence Apache-2.0
 */
class ChannelConfiguration
{
    public const QUEUE_NAME = 'placeOrder';

    #[ServiceContext]
    public function registerCommandChannel(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create(self::QUEUE_NAME)
                ->withReceiveTimeout(1)
                ->withPublisherAcknowledgments(false),
            PollingMetadata::create('placeOrderEndpoint')
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(1000),
            AmqpConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(true)
                ->withTransactionOnCommandBus(true),
            PollableChannelConfiguration::neverRetry(self::QUEUE_NAME)->withCollector(false),
        ];
    }
}
