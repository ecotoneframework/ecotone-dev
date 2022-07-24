<?php

namespace App\Microservices\Publisher;

use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class MessagingConfiguration
{
    const SERVICE_NAME = "x_service";

    #[ServiceContext]
    public function configure()
    {
        return [
            AmqpDistributedBusConfiguration::createPublisher()
        ];
    }
}