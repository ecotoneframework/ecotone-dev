<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedMessage\Receiver;

use Ecotone\Amqp\Distribution\AmqpDistributedBusConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * licence Apache-2.0
 */
class TicketServiceMessagingConfiguration
{
    public const SERVICE_NAME = 'ticket_service';

    #[ServiceContext]
    public function configure()
    {
        return [
            AmqpDistributedBusConfiguration::createConsumer(),
            PollingMetadata::create(self::SERVICE_NAME)
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(5000),
        ];
    }
}
