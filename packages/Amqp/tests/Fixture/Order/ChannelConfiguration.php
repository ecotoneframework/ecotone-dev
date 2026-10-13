<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
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
                ->withExecutionTimeLimitInMilliseconds(1000)
                ->withHandledMessageLimit(1)
                ->withErrorChannelName(self::ERROR_CHANNEL),
        ];
    }
}
