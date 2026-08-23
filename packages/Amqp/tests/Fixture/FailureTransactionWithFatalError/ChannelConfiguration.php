<?php

namespace Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\Configuration\AmqpConfiguration;
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
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(5000),
            AmqpConfiguration::createWithDefaults()
                ->withTransactionOnAsynchronousEndpoints(true)
                ->withTransactionOnCommandBus(true),
        ];
    }
}
