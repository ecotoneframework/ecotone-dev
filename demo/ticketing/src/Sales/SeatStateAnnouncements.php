<?php

declare(strict_types=1);

namespace App\Sales;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\DistributedBus;

final class SeatStateAnnouncements
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_held')]
    public function seatHeld(SeatHeld $event, DistributedBus $distributedBus): void
    {
        $this->announceHold('tickets.seat_held', $event->concertId, $event->seatId, $event->orderId, $event->holdId, $distributedBus);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_released')]
    public function holdReleased(HoldReleased $event, DistributedBus $distributedBus): void
    {
        $this->announceHold('tickets.seat_released', $event->concertId, $event->seatId, $event->orderId, $event->holdId, $distributedBus);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_released_on_expiry')]
    public function holdExpired(HoldExpired $event, DistributedBus $distributedBus): void
    {
        $this->announceHold('tickets.seat_released', $event->concertId, $event->seatId, $event->orderId, $event->holdId, $distributedBus);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_sold')]
    public function seatSold(SeatSold $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'tickets.seat_sold',
            ['concertId' => $event->concertId, 'seatId' => $event->seatId, 'orderId' => $event->orderId],
            metadata: ['concertId' => $event->concertId],
        );
    }

    private function announceHold(string $routingKey, string $concertId, string $seatId, string $orderId, string $holdId, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            $routingKey,
            ['concertId' => $concertId, 'seatId' => $seatId, 'orderId' => $orderId, 'holdId' => $holdId],
            metadata: ['concertId' => $concertId],
        );
    }
}
