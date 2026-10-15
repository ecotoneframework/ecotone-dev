<?php

namespace App\Asynchronous;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
                    ->withHandledMessageLimit(1)
                    ->withExecutionAmountLimit(1000);
    }
}