<?php

namespace App\Asynchronous;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\PollingMetadata;

class Configuration
{
    #[ServiceContext]
    public function enableRabbitMQ()
    {
        return AmqpBackedMessageChannelBuilder::create(NotificationService::ASYNCHRONOUS_MESSAGES);
    }

    #[ServiceContext]
    public function consumerDefinition()
    {
        return PollingMetadata::create(NotificationService::ASYNCHRONOUS_MESSAGES)
                    ->setHandledMessageLimit(1)
                    ->setExecutionAmountLimit(1000);
    }
}