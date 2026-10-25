<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Gateway\CommandBus;

final class HoldExpiry
{
    #[Asynchronous('ticketing_async')]
    #[Delayed(expression: static function (#[Payload] SeatHeld $event): DateTimeImmutable {
        return $event->expiresAt;
    })]
    #[EventHandler(endpointId: 'expire_hold_when_due')]
    public function whenDue(SeatHeld $event, CommandBus $commandBus): void
    {
        $commandBus->send(
            new ExpireHold(orderId: $event->orderId, concertId: $event->concertId, seatId: $event->seatId, customerId: $event->customerId),
            metadata: ['concertId' => $event->concertId],
        );
    }
}
