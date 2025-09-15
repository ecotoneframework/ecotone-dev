<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::EVENT_NAME)]
class OrderWasCancelled
{
    public const EVENT_NAME = 'order_was_cancelled';

    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly bool $fail = false
    ) {
    }
}
