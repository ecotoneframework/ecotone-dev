<?php

namespace Test\Ecotone\EventSourcing\Fixture\CustomEventStream;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;

#[Projection('custom_event_stream_projection', fromStreams: Basket::BASKET_STREAM)]
/**
 * licence Apache-2.0
 */
class CustomEventStreamProjection
{
    private array $actions = [];

    #[EventHandler(BasketWasCreated::EVENT_NAME)]
    public function onBasketWasCreated(BasketWasCreated $event): void
    {
        $this->actions[] = $event;
    }

    #[QueryHandler('action_collector.getCount')]
    public function countHappenedActions(): int
    {
        return count($this->actions);
    }
}
