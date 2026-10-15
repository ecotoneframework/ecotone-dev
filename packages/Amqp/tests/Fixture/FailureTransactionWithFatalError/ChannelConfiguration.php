<?php

namespace Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
                ->withPublisherConfirms(false),
            PollingMetadata::create(self::QUEUE_NAME)
                ->withHandledMessageLimit(1)
                ->withExecutionTimeLimitInMilliseconds(5000),
            AmqpConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(true)
                ->withTransactionOnCommandBus(true),
        ];
    }
}
