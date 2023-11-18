<?php

namespace App\MultiTenancy\RateLimitWithDelay;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class Configuration
{
    const ASYNCHRONOUS_MESSAGES = 'email_campaign';

    #[ServiceContext]
    public function enableRabbitMQ()
    {
        return AmqpBackedMessageChannelBuilder::create(self::ASYNCHRONOUS_MESSAGES);
    }

    #[ServiceContext]
    public function consumerDefinition()
    {
        return PollingMetadata::create(self::ASYNCHRONOUS_MESSAGES)
                    ->setHandledMessageLimit(1)
                    ->setExecutionAmountLimit(1000)
                    ->setStopOnError(true);
    }
}