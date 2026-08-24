<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

/**
 * licence Apache-2.0
 */
class ChannelConfiguration
{
    public const ERROR_CHANNEL = 'errorChannel';
    public const QUEUE_NAME = 'orders';

    #[ServiceContext]
    public function registerAsyncChannel(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create(self::QUEUE_NAME)
                ->withReceiveTimeout(100),
            PollingMetadata::create(self::QUEUE_NAME)
                ->setExecutionTimeLimitInMilliseconds(1000)
                ->setHandledMessageLimit(1)
                ->setErrorChannelName(self::ERROR_CHANNEL),
        ];
    }
}
