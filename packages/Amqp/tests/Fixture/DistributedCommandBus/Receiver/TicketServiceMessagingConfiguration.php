<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
            AmqpBackedMessageChannelBuilder::create(self::SERVICE_NAME),
            PollingMetadata::create(self::SERVICE_NAME)
                ->setHandledMessageLimit(5)
                ->setExecutionTimeLimitInMilliseconds(5000),
        ];
    }
}
