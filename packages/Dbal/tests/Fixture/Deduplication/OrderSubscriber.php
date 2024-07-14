<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class OrderSubscriber
{
    private int $called = 0;

    #[Asynchronous(ChannelConfiguration::CHANNEL_NAME)]
    #[EventHandler(endpointId: 'event1')]
    public function event1(OrderPlaced $event): void
    {
        $this->called += 1;
    }

    #[Asynchronous(ChannelConfiguration::CHANNEL_NAME)]
    #[EventHandler(endpointId: 'event2')]
    public function event2(OrderPlaced $event): void
    {
        $this->called += 1;
    }

    #[QueryHandler('order.getCalled')]
    public function getOrders(): int
    {
        return $this->called;
    }
}
