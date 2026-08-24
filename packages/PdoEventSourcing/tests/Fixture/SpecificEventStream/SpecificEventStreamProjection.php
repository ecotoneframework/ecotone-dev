<?php

namespace Test\Ecotone\EventSourcing\Fixture\SpecificEventStream;

use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use Ecotone\EventSourcing\Attribute\Projection;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;

#[Projection('specific_event_stream_projection', fromStreams: Basket::BASKET_STREAM . '-1000')]
/**
 * licence Apache-2.0
 */
class SpecificEventStreamProjection
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
