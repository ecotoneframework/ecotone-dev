<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\ReceiverEventHandler;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final class TicketNotificationConfig
{
    #[ServiceContext]
    public function channel()
    {
        return AmqpBackedMessageChannelBuilder::create('async');
    }
}
