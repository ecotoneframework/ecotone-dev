<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\ReceiverEventHandler;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
final class TicketNotificationConfig
{
    #[ServiceContext]
    public function channel()
    {
        return AmqpBackedMessageChannelBuilder::create('async');
    }
}
