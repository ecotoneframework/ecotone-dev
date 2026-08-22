<?php

namespace App\Microservices\Publisher;

use App\Microservices\Receiver\MessagingConfiguration as ReceiverMessagingConfiguration;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;
use Ecotone\Messaging\Attribute\ServiceContext;

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