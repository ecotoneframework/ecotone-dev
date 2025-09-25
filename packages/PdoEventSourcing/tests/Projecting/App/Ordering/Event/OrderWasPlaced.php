<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
class OrderWasPlaced
{
    public const EVENT_NAME = 'order_was_placed';

    public function __construct(
        public readonly string $orderId,
        public readonly string $product,
        public readonly int $quantity,
        public readonly bool $fail = false
    ) {
    }
}
