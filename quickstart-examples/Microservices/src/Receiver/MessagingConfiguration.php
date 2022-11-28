<?php

namespace App\Microservices\Receiver;

use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class MessagingConfiguration
{
    const SERVICE_NAME = "order_service";

    #[ServiceContext]
    public function configure()
    {
        return [
            AmqpDistributedBusConfiguration::createConsumer(),
            PollingMetadata::create(self::SERVICE_NAME)
                ->withTestingSetup()
        ];
    }
}