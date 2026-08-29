<?php

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionFromMultipleStreams;

use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\Projection;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Projection('multiple_stream_projections')]
#[FromStream(Ticket::class)]
#[FromStream(Basket::BASKET_STREAM)]
/**
 * licence Apache-2.0
 */
class MultipleStreamsProjection
{
    private array $actions = [];

    #[EventHandler(BasketWasCreated::EVENT_NAME)]
    public function onBasketWasCreated(BasketWasCreated $event): void
    {
        $this->actions[] = $event;
    }

    #[EventHandler]
    public function onTicketWasRegistered(TicketWasRegistered $event): void
    {
        $this->actions[] = $event;
    }

    #[QueryHandler('action_collector.getCount')]
    public function countHappenedActions(): int
    {
        return count($this->actions);
    }
}
