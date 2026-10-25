<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Concert;
use App\Catalogue\MarkConcertSoldOut;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\Gateway\CommandBus;

final class SoldOutWatch
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'mark_concert_sold_out_when_last_seat_sold')]
    public function whenSeatSold(
        SeatSold $event,
        #[Fetch(static function (#[Payload] SeatSold $event): string {
            return $event->concertId;
        })] Concert $concert,
        EventStore $eventStore,
        CommandBus $commandBus,
    ): void {
        if (! $concert->isOnSale()) {
            return;
        }
        $seatsSold = count($eventStore->loadByCriteria(EventCriteria::tag('concert', $event->concertId)->ofTypes(SeatSold::class))->events);
        if ($seatsSold < $concert->seatCount()) {
            return;
        }

        $commandBus->send(new MarkConcertSoldOut($event->concertId), metadata: ['concertId' => $event->concertId]);
    }
}
