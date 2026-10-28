<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Sales\HoldExpired;
use DateTimeInterface;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\DistributedBus;

final class ConcertAnnouncements
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_concert_scheduled')]
    public function concertScheduled(ConcertScheduled $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'concert.scheduled',
            [
                'concertId' => $event->concertId,
                'venueName' => $event->venueName,
                'artist' => $event->artist,
                'startsAt' => $event->startsAt->format(DateTimeInterface::ATOM),
                'currency' => $event->currency,
                'tiers' => [Tier::STANDARD->value => $event->standardPriceCents, Tier::VIP->value => $event->vipPriceCents],
                'seats' => array_map(static fn (Seat $seat): array => ['seatId' => $seat->seatId, 'seatCode' => $seat->seatCode, 'tier' => $seat->tier->value], $event->seats),
            ],
            metadata: ['concertId' => $event->concertId],
        );
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_sales_opened')]
    public function salesOpened(SalesOpened $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent('concert.sales_opened', ['concertId' => $event->concertId], metadata: ['concertId' => $event->concertId]);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_concert_cancelled')]
    public function concertCancelled(ConcertCancelled $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent('concert.cancelled', ['concertId' => $event->concertId], metadata: ['concertId' => $event->concertId]);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_prices_changed')]
    public function pricesChanged(TierPriceChanged $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'concert.prices_changed',
            ['concertId' => $event->concertId, 'tiers' => [Tier::STANDARD->value => $event->standardPriceCents, Tier::VIP->value => $event->vipPriceCents]],
            metadata: ['concertId' => $event->concertId],
        );
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_concert_sold_out')]
    public function concertSoldOut(ConcertSoldOut $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent('concert.sold_out', ['concertId' => $event->concertId], metadata: ['concertId' => $event->concertId]);
    }

    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'announce_hold_expired')]
    public function holdExpired(HoldExpired $event, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'tickets.hold_expired',
            ['orderId' => $event->orderId, 'concertId' => $event->concertId, 'seatId' => $event->seatId, 'customerId' => $event->customerId, 'holdId' => $event->holdId],
            metadata: ['concertId' => $event->concertId],
        );
    }
}
