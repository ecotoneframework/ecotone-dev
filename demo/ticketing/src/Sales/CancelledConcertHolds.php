<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\ConcertCancelled;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\Gateway\CommandBus;

final class CancelledConcertHolds
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'release_holds_of_cancelled_concert')]
    public function release(ConcertCancelled $event, EventStore $eventStore, CommandBus $commandBus): void
    {
        $liveHolds = [];
        $seatEvents = $eventStore->loadByCriteria(EventCriteria::tag('concert', $event->concertId)->ofTypes(SeatHeld::class, HoldReleased::class, HoldExpired::class, SeatSold::class));
        foreach ($seatEvents->events as $seatEvent) {
            $payload = $seatEvent->getPayload();
            if ($payload instanceof SeatHeld) {
                $liveHolds[$payload->seatId] = $payload;
            } elseif ($payload instanceof HoldReleased || $payload instanceof HoldExpired || $payload instanceof SeatSold) {
                unset($liveHolds[$payload->seatId]);
            }
        }

        foreach ($liveHolds as $hold) {
            try {
                $commandBus->send(
                    new ReleaseSeat(orderId: $hold->orderId, concertId: $hold->concertId, seatId: $hold->seatId, customerId: $hold->customerId, holdId: $hold->holdId),
                    metadata: ['concertId' => $hold->concertId],
                );
            } catch (SeatRefusal) {
            }
        }
    }
}
