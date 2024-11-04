<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\ReceiverEventHandler;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

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
