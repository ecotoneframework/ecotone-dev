<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Publisher;

use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
class UserServiceMessagingConfiguration
{
    #[ServiceContext]
    public function registerPublisher()
    {
        return AmqpDistributedBusConfiguration::createPublisher();
    }
}
