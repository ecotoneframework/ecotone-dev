<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Concert;
use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConfigurationVariable;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Gateway\EcotoneClockInterface;

final class SeatSales
{
    /**
     * @return list<SeatHeld>
     */
    #[CommandHandler]
    public function hold(
        HoldSeat $command,
        SeatAvailability $seat,
        CustomerConcertSeats $customerSeats,
        #[Fetch(static function (#[Payload] HoldSeat $command): string {
            return $command->concertId;
        })] ?Concert $concert,
        EcotoneClockInterface $clock,
        #[ConfigurationVariable('ticketingHoldSeconds')] int $holdSeconds,
    ): array {
        $now = DateTimeImmutable::createFromInterface($clock->now());
        if ($concert === null) {
            throw SeatRefusal::because(sprintf('Seat %s cannot be held for order %s, because ticketing has no concert %s. Pick a concert from concert.scheduled announcements, or ask ticketing to publish concert.scheduled again.', $command->seatId, $command->orderId, $command->concertId));
        }
        $concertSeat = $concert->seat($command->seatId);
        if ($concertSeat === null) {
            throw SeatRefusal::because(sprintf('Seat %s cannot be held for order %s, because %s has no such seat: its seats are %s. Pick a seat id of the form %s/<seatCode> from the concert\'s seats.', $command->seatId, $command->orderId, $concert->title(), $concert->seatRange(), $command->concertId));
        }
        $refusedSeat = sprintf('Seat %s of %s cannot be held for order %s', $concertSeat->seatCode, $concert->title(), $command->orderId);
        if (! $concert->isOnSale()) {
            throw SeatRefusal::because(sprintf('%s, because the concert is %s, not on sale. Seats are held only while a concert is on sale.', $refusedSeat, $concert->statusInWords()));
        }
        if ($seat->soldToOrderId() !== null) {
            throw SeatRefusal::because(sprintf('%s, because someone was faster: it is sold to order %s. Pick another seat.', $refusedSeat, $seat->soldToOrderId()));
        }
        $holdingOrderId = $seat->liveHoldingOrderIdAt($now);
        if ($holdingOrderId === $command->orderId) {
            return [];
        }
        if ($holdingOrderId !== null) {
            throw SeatRefusal::because(sprintf('%s, because someone was faster: order %s holds it until %s. Pick another seat, or try this one again after that hold expires.', $refusedSeat, $holdingOrderId, $seat->heldUntil()->format(DateTimeInterface::ATOM)));
        }
        $takenSeatIds = $customerSeats->seatIdsTakenAt($now);
        if (count($takenSeatIds) >= CustomerConcertSeats::MAXIMUM_SEATS) {
            throw SeatRefusal::because(sprintf(
                '%s, because customer %s already holds or owns %d seats of this concert (%s), and a customer gets at most %d. Release one of those holds first, or buy with another customer.',
                $refusedSeat,
                $command->customerId,
                count($takenSeatIds),
                implode(', ', array_map(static fn (string $seatId): string => $concert->seat($seatId)?->seatCode ?? $seatId, $takenSeatIds)),
                CustomerConcertSeats::MAXIMUM_SEATS,
            ));
        }

        return [new SeatHeld(
            orderId: $command->orderId,
            concertId: $command->concertId,
            seatId: $command->seatId,
            customerId: $command->customerId,
            heldAt: $now,
            expiresAt: $now->modify(sprintf('+%d seconds', $holdSeconds)),
        )];
    }

    /**
     * @return list<HoldReleased>
     */
    #[CommandHandler]
    public function release(ReleaseSeat $command, SeatAvailability $seat): array
    {
        $holdingOrderId = $seat->holdingOrderId();
        if ($holdingOrderId !== $command->orderId) {
            throw SeatRefusal::because(sprintf(
                'Seat %s cannot be released for order %s, because %s. Only the order holding a seat releases it.',
                $command->seatId,
                $command->orderId,
                match (true) {
                    $holdingOrderId !== null => sprintf('order %s holds it, not order %s', $holdingOrderId, $command->orderId),
                    $seat->soldToOrderId() !== null => sprintf('it is sold to order %s', $seat->soldToOrderId()),
                    default => 'no order holds it',
                },
            ));
        }

        return [new HoldReleased(orderId: $command->orderId, concertId: $command->concertId, seatId: $command->seatId, customerId: $command->customerId)];
    }

    /**
     * @return list<HoldExpired>
     */
    #[CommandHandler]
    public function expire(ExpireHold $command, SeatAvailability $seat): array
    {
        if ($seat->holdingOrderId() !== $command->orderId) {
            return [];
        }

        return [new HoldExpired(orderId: $command->orderId, concertId: $command->concertId, seatId: $command->seatId, customerId: $command->customerId)];
    }

    /**
     * @return list<SeatSold>
     */
    #[CommandHandler]
    public function confirm(
        ConfirmSeats $command,
        CustomerConcertSeats $customerSeats,
        #[Fetch(static function (#[Payload] ConfirmSeats $command): string {
            return $command->concertId;
        })] ?Concert $concert,
        EcotoneClockInterface $clock,
    ): array {
        $now = $clock->now();
        if ($concert === null) {
            throw SeatRefusal::because(sprintf('Order %s cannot buy seats of concert %s, because ticketing has no such concert. Pick a concert from concert.scheduled announcements, or ask ticketing to publish concert.scheduled again.', $command->orderId, $command->concertId));
        }
        if (! $concert->isOnSale()) {
            throw SeatRefusal::because(sprintf('Order %s cannot buy seats of %s, because the concert is %s, not on sale; nothing of the order was sold. Release the order\'s holds and refund the payment %s.', $command->orderId, $concert->title(), $concert->statusInWords(), $command->paymentReference));
        }
        $seatIds = array_values(array_unique($command->seatIds));
        if ($seatIds === []) {
            throw SeatRefusal::because(sprintf('Order %s names no seats to buy. Confirm the order with the ids of the seats it holds.', $command->orderId));
        }

        $sold = [];
        foreach ($seatIds as $seatId) {
            $this->assertLiveHoldOfTheOrder($command, $customerSeats, $seatId, $now);
            $tier = $concert->seat($seatId)->tier;
            $sold[] = new SeatSold(
                orderId: $command->orderId,
                concertId: $command->concertId,
                seatId: $seatId,
                customerId: $command->customerId,
                paymentReference: $command->paymentReference,
                tier: $tier,
                priceCents: $concert->priceOf($tier),
            );
        }

        return $sold;
    }

    private function assertLiveHoldOfTheOrder(ConfirmSeats $command, CustomerConcertSeats $customerSeats, string $seatId, DateTimeInterface $now): void
    {
        $refusedSeat = sprintf('Order %s cannot buy seat %s', $command->orderId, $seatId);
        $soldToOrderId = $customerSeats->soldToOrderId($seatId);
        if ($soldToOrderId !== null) {
            throw SeatRefusal::because(sprintf('%s, because it is already sold to order %s. Confirm only seats that are held; nothing of the order was sold.', $refusedSeat, $soldToOrderId));
        }
        $hold = $customerSeats->holdOf($seatId);
        if ($hold === null) {
            throw SeatRefusal::because(sprintf('%s, because it does not hold it. Hold the seat first, then confirm the order again; nothing of the order was sold.', $refusedSeat));
        }
        if ($hold['orderId'] !== $command->orderId) {
            throw SeatRefusal::because(sprintf('%s, because order %s holds it, not order %s. Confirm each order with its own seats; nothing of the order was sold.', $refusedSeat, $hold['orderId'], $command->orderId));
        }
        if ($hold['heldUntil'] <= $now) {
            throw SeatRefusal::because(sprintf('%s, because its hold expired at %s. Hold the seat again, then confirm the order again; nothing of the order was sold.', $refusedSeat, $hold['heldUntil']->format(DateTimeInterface::ATOM)));
        }
    }
}
