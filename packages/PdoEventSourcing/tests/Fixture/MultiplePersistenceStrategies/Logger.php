<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Modelling\Attribute\EventHandler;

/**
 * licence Apache-2.0
 */
final class Logger
{
    public const STREAM = 'log';

    #[EventHandler(listenTo: BasketCreated::NAME)]
    public function whenBasketCreated(BasketCreated $event, EventStreamEmitter $emitter): void
    {
        $emitter->linkTo(self::STREAM, [new LogEvent(BasketCreated::NAME)]);
    }

    #[EventHandler(listenTo: OrderCreated::NAME)]
    public function whenOrderCreated(OrderCreated $event, EventStreamEmitter $emitter): void
    {
        $emitter->linkTo(self::STREAM, [new LogEvent(OrderCreated::NAME)]);
    }
}
