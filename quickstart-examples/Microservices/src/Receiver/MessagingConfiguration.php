<?php

namespace App\Microservices\Receiver;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

class MessagingConfiguration
{
    const SERVICE_NAME = "order_service";

    #[ServiceContext]
    public function configure()
    {
        return [
            AmqpBackedMessageChannelBuilder::create(self::SERVICE_NAME),
            PollingMetadata::create(self::SERVICE_NAME)
                ->withTestingSetup()
        ];
    }
}