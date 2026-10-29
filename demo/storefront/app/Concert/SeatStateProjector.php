<?php

declare(strict_types=1);

namespace App\Concert;

use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Carbon;

final class SeatStateProjector
{
    #[Distributed]
    #[EventHandler('tickets.seat_held')]
    public function whenSeatHeld(array $event, #[Reference] SeatStates $seatStates): void
    {
        $seatStates->hold(concertId: $event['concertId'], seatId: $event['seatId'], holdId: $event['holdId'], orderId: $event['orderId'], expiresAt: $event['expiresAt']);
    }

    #[Distributed]
    #[EventHandler('tickets.seat_released')]
    public function whenSeatReleased(array $event, #[Reference] SeatStates $seatStates): void
    {
        $seatStates->endHold(concertId: $event['concertId'], seatId: $event['seatId'], holdId: $event['holdId'], orderId: $event['orderId']);
    }

    #[Distributed]
    #[EventHandler('tickets.seat_sold')]
    public function whenSeatSold(array $event, #[Reference] SeatStates $seatStates): void
    {
        $seatStates->sell(concertId: $event['concertId'], seatId: $event['seatId'], orderId: $event['orderId']);
    }

    #[QueryHandler('concert.seat_states')]
    public function seatStates(array $query, #[Reference] SeatStates $seatStates, #[Reference] QueryBus $queryBus): array
    {
        $soldSeatIds = self::sorted($seatStates->soldOf($query['concertId']));

        return [
            'heldSeatIds' => self::sorted(array_diff(
                [...$seatStates->liveHoldsAt(concertId: $query['concertId'], now: Carbon::now()->toAtomString(), excludingOrderId: $query['excludingOrderId'] ?? ''), ...$queryBus->sendWithRouting('concert.seats_claimed_by_orders', $query)],
                $soldSeatIds,
            )),
            'soldSeatIds' => $soldSeatIds,
        ];
    }

    #[QueryHandler('concert.taken_seats')]
    public function takenSeats(array $query, #[Reference] QueryBus $queryBus): array
    {
        $seatStates = $queryBus->sendWithRouting('concert.seat_states', $query);

        return self::sorted([...$seatStates['heldSeatIds'], ...$seatStates['soldSeatIds']]);
    }

    /**
     * @param array<string> $seatIds
     * @return list<string>
     */
    private static function sorted(array $seatIds): array
    {
        $seatIds = array_values(array_unique($seatIds));
        sort($seatIds);

        return $seatIds;
    }
}
