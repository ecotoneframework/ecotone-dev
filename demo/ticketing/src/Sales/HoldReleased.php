<?php

declare(strict_types=1);

namespace App\Sales;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ticketing.hold_released')]
final readonly class HoldReleased
{
    public function __construct(
        public string $orderId,
        #[EventTag('concert')]
        public string $concertId,
        #[EventTag('seat')]
        public string $seatId,
        #[EventTag('customer')]
        public string $customerId,
    ) {
    }
}
