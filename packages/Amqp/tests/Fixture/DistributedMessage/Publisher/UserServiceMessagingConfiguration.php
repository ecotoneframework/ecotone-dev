<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedMessage\Publisher;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver\TicketServiceMessagingConfiguration;

/**
 * licence Apache-2.0
 */
class UserServiceMessagingConfiguration
{
    #[ServiceContext]
    public function registerPublisher()
    {
        return [
            DistributedServiceMap::initialize()
                ->withCommandMapping(TicketServiceMessagingConfiguration::SERVICE_NAME, TicketServiceMessagingConfiguration::SERVICE_NAME),
            AmqpBackedMessageChannelBuilder::create(TicketServiceMessagingConfiguration::SERVICE_NAME),
        ];
    }
}
