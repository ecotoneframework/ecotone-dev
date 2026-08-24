<?php

namespace App\Microservices\Publisher;

use App\Microservices\Receiver\MessagingConfiguration as ReceiverMessagingConfiguration;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\DistributedServiceMap;
use Ecotone\Api\ServiceContext;

class MessagingConfiguration
{
    const SERVICE_NAME = "x_service";

    #[ServiceContext]
    public function configure()
    {
        return [
            DistributedServiceMap::initialize()
                ->withCommandMapping(ReceiverMessagingConfiguration::SERVICE_NAME, ReceiverMessagingConfiguration::SERVICE_NAME)
                ->withEventMapping(ReceiverMessagingConfiguration::SERVICE_NAME, ["*"]),
            AmqpBackedMessageChannelBuilder::create(ReceiverMessagingConfiguration::SERVICE_NAME)
        ];
    }
}