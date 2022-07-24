<?php

namespace App\Asynchronous;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

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