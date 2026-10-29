<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeInterface;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\DistributedBus;

final class SeatStateAnnouncements
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_held')]
    public function seatHeld(SeatHeld $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'tickets.seat_held',
            ['concertId' => $event->concertId, 'seatId' => $event->seatId, 'orderId' => $event->orderId, 'holdId' => $event->holdId, 'expiresAt' => $event->expiresAt->format(DateTimeInterface::ATOM)],
            metadata: ['concertId' => $event->concertId],
        );
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_released')]
    public function holdReleased(HoldReleased $event, DistributedBus $distributedBus): void
    {
        $this->announceRelease($event->concertId, $event->seatId, $event->orderId, $event->holdId, $distributedBus);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_seat_released_on_expiry')]
    public function holdExpired(HoldExpired $event, DistributedBus $distributedBus): void
    {
        $this->announceRelease($event->concertId, $event->seatId, $event->orderId, $event->holdId, $distributedBus);
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

    private function announceRelease(string $concertId, string $seatId, string $orderId, string $holdId, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'tickets.seat_released',
            ['concertId' => $concertId, 'seatId' => $seatId, 'orderId' => $orderId, 'holdId' => $holdId],
            metadata: ['concertId' => $concertId],
        );
    }
}
