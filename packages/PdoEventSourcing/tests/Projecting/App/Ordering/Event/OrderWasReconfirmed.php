<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
class OrderWasReconfirmed
{
    public const EVENT_NAME = 'order_was_reconfirmed';

    public function __construct(
        public readonly string $orderId
    ) {
    }
}
