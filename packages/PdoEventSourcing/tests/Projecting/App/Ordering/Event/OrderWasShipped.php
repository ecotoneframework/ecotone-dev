<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
class OrderWasShipped
{
    public const EVENT_NAME = 'order_was_shipped';

    public function __construct(
        public readonly string $orderId,
        public readonly bool $fail = false
    ) {
    }
}
