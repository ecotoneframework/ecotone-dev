<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConfigurationVariable;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\QueryBus;

final class SeatSales
{
    /**
     * @return list<SeatHeld>
     */
    #[CommandHandler]
    public function hold(
        HoldSeat $command,
        SeatAvailability $seat,
        #[Fetch(static function (#[Payload] HoldSeat $command): string {
            return CustomerConcertSeats::scopeOf(customerId: $command->customerId, concertId: $command->concertId);
        })] CustomerConcertSeats $customerSeats,
        QueryBus $queryBus,
        EcotoneClockInterface $clock,
        #[ConfigurationVariable('ticketingHoldSeconds')] int $holdSeconds,
    ): array {
        $now = DateTimeImmutable::createFromInterface($clock->now());
        $concert = ConcertForSale::find($queryBus, $command->concertId);
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
        if ($holdingOrderId === null) {
            throw SeatRefusal::because(sprintf(
                'Seat %s cannot be released for order %s, because %s. Only the order holding a seat releases it.',
                $command->seatId,
                $command->orderId,
                $seat->soldToOrderId() !== null ? sprintf('it is sold to order %s', $seat->soldToOrderId()) : 'no order holds it',
            ));
        }
        if ($holdingOrderId !== $command->orderId || $seat->holdingCustomerId() !== $command->customerId || $seat->holdingConcertId() !== $command->concertId) {
            throw SeatRefusal::because(sprintf(
                'Seat %s cannot be released for order %s of customer %s at concert %s, because order %s of customer %s holds it at concert %s. Release a seat with the order, customer and concert of its hold; only the holder releases it.',
                $command->seatId,
                $command->orderId,
                $command->customerId,
                $command->concertId,
                $holdingOrderId,
                $seat->holdingCustomerId(),
                $seat->holdingConcertId(),
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
        if ($seat->holdingOrderId() !== $command->orderId || $seat->heldUntil() != $command->expiresAt) {
            return [];
        }

        return [new HoldExpired(orderId: $command->orderId, concertId: $command->concertId, seatId: $command->seatId, customerId: $command->customerId)];
    }

    #[DecisionBoundary]
    public static function seatsOfTheConfirmation(ConfirmSeats $command): EventCriteria
    {
        return self::seatsOf($command);
    }

    #[DecisionBoundary]
    public static function seatsOfTheSeedOrder(ConfirmSeedOrder $command): EventCriteria
    {
        return self::seatsOf($command);
    }

    /**
     * @return list<SeatSold|SeatsSoldToOrder>
     */
    #[CommandHandler]
    public function confirm(
        ConfirmSeats $command,
        #[Fetch(static function (#[Payload] ConfirmSeats $command): string {
            return CustomerConcertSeats::scopeOf(customerId: $command->customerId, concertId: $command->concertId);
        })] CustomerConcertSeats $customerSeats,
        QueryBus $queryBus,
        EcotoneClockInterface $clock,
    ): array {
        $sold = $this->sell(order: $command, customerSeats: $customerSeats, queryBus: $queryBus, now: $clock->now());
        if ($sold === []) {
            return [];
        }

        return [
            ...$sold,
            new SeatsSoldToOrder(
                orderId: $command->orderId,
                concertId: $command->concertId,
                customerId: $command->customerId,
                paymentReference: $command->paymentReference,
                seats: array_map(static fn (SeatSold $seat): SoldSeat => new SoldSeat(seatId: $seat->seatId, tier: $seat->tier, priceCents: $seat->priceCents), $sold),
            ),
        ];
    }

    /**
     * @return list<SeatSold>
     */
    #[CommandHandler]
    public function confirmSeedOrder(
        ConfirmSeedOrder $command,
        #[Fetch(static function (#[Payload] ConfirmSeedOrder $command): string {
            return CustomerConcertSeats::scopeOf(customerId: $command->customerId, concertId: $command->concertId);
        })] CustomerConcertSeats $customerSeats,
        QueryBus $queryBus,
        EcotoneClockInterface $clock,
    ): array {
        return $this->sell(order: $command, customerSeats: $customerSeats, queryBus: $queryBus, now: $clock->now());
    }

    /**
     * @return array{orderId: string, expiresAt: DateTimeImmutable}|null
     */
    #[QueryHandler]
    public function holdOf(FindSeatHold $query, SeatAvailability $seat): ?array
    {
        if ($seat->holdingOrderId() === null) {
            return null;
        }

        return ['orderId' => $seat->holdingOrderId(), 'expiresAt' => $seat->heldUntil()];
    }

    /**
     * @return array{holdingOrderId: ?string, soldToOrderId: ?string}
     */
    #[QueryHandler]
    public function ownerOf(FindSeatOwner $query, SeatAvailability $seat): array
    {
        return ['holdingOrderId' => $seat->holdingOrderId(), 'soldToOrderId' => $seat->soldToOrderId()];
    }

    private static function seatsOf(ConfirmSeats|ConfirmSeedOrder $order): EventCriteria
    {
        $criteria = EventCriteria::tag('customerConcert', CustomerConcertSeats::scopeOf(customerId: $order->customerId, concertId: $order->concertId));
        foreach (array_unique($order->seatIds) as $seatId) {
            $criteria = $criteria->or(EventCriteria::tag('seat', $seatId));
        }

        return $criteria;
    }

    /**
     * @return list<SeatSold>
     */
    private function sell(ConfirmSeats|ConfirmSeedOrder $order, CustomerConcertSeats $customerSeats, QueryBus $queryBus, DateTimeInterface $now): array
    {
        $seatIds = array_values(array_unique($order->seatIds));
        if ($seatIds === []) {
            throw SeatRefusal::because(sprintf('Order %s names no seats to buy. Confirm the order with the ids of the seats it holds.', $order->orderId));
        }
        if ($customerSeats->soldEveryOneTo(seatIds: $seatIds, orderId: $order->orderId, paymentReference: $order->paymentReference)) {
            return [];
        }

        $concert = ConcertForSale::find($queryBus, $order->concertId);
        if ($concert === null) {
            throw SeatRefusal::because(sprintf('Order %s cannot buy seats of concert %s, because ticketing has no such concert. Pick a concert from concert.scheduled announcements, or ask ticketing to publish concert.scheduled again.', $order->orderId, $order->concertId));
        }
        if (! $concert->isOnSale()) {
            throw SeatRefusal::because(sprintf('Order %s cannot buy seats of %s, because the concert is %s, not on sale; nothing of the order was sold. Release the order\'s holds and refund the payment %s.', $order->orderId, $concert->title(), $concert->statusInWords(), $order->paymentReference));
        }

        $sold = [];
        foreach ($seatIds as $seatId) {
            $this->assertLiveHoldOfTheOrder(order: $order, customerSeats: $customerSeats, seatId: $seatId, now: $now);
            $this->assertNoOtherOrderTookTheSeat(order: $order, owner: $queryBus->send(new FindSeatOwner($seatId)), seatId: $seatId);
            $tier = $concert->seat($seatId)->tier;
            $sold[] = new SeatSold(
                orderId: $order->orderId,
                concertId: $order->concertId,
                seatId: $seatId,
                customerId: $order->customerId,
                paymentReference: $order->paymentReference,
                tier: $tier,
                priceCents: $concert->priceOf($tier),
            );
        }

        return $sold;
    }

    private function assertLiveHoldOfTheOrder(ConfirmSeats|ConfirmSeedOrder $order, CustomerConcertSeats $customerSeats, string $seatId, DateTimeInterface $now): void
    {
        $orderId = $order->orderId;
        $refusedSeat = sprintf('Order %s cannot buy seat %s', $orderId, $seatId);
        $sale = $customerSeats->saleOf($seatId);
        if ($sale !== null && $sale['orderId'] === $orderId && $sale['paymentReference'] !== $order->paymentReference) {
            throw SeatRefusal::because(sprintf('%s with payment %s, because it is already sold to this order with payment %s. Refund payment %s; the seat stays sold with payment %s.', $refusedSeat, $order->paymentReference, $sale['paymentReference'], $order->paymentReference, $sale['paymentReference']));
        }
        if ($sale !== null) {
            throw SeatRefusal::because(sprintf('%s, because it is already sold to order %s. Confirm only seats that are held; nothing of the order was sold.', $refusedSeat, $sale['orderId']));
        }
        $hold = $customerSeats->holdOf($seatId);
        if ($hold === null) {
            throw SeatRefusal::because(sprintf('%s, because it does not hold it. Hold the seat first, then confirm the order again; nothing of the order was sold.', $refusedSeat));
        }
        if ($hold['orderId'] !== $orderId) {
            throw SeatRefusal::because(sprintf('%s, because order %s holds it, not order %s. Confirm each order with its own seats; nothing of the order was sold.', $refusedSeat, $hold['orderId'], $orderId));
        }
        if ($hold['heldUntil'] <= $now) {
            throw SeatRefusal::because(sprintf('%s, because its hold expired at %s. Hold the seat again, then confirm the order again; nothing of the order was sold.', $refusedSeat, $hold['heldUntil']->format(DateTimeInterface::ATOM)));
        }
    }

    /**
     * @param array{holdingOrderId: ?string, soldToOrderId: ?string} $owner
     */
    private function assertNoOtherOrderTookTheSeat(ConfirmSeats|ConfirmSeedOrder $order, array $owner, string $seatId): void
    {
        $refusedSeat = sprintf('Order %s cannot buy seat %s', $order->orderId, $seatId);
        if ($owner['soldToOrderId'] !== null) {
            throw SeatRefusal::because(sprintf('%s, because it is already sold to order %s. Confirm only seats that are held; nothing of the order was sold.', $refusedSeat, $owner['soldToOrderId']));
        }
        if ($owner['holdingOrderId'] === null) {
            throw SeatRefusal::because(sprintf('%s, because it does not hold it. Hold the seat first, then confirm the order again; nothing of the order was sold.', $refusedSeat));
        }
        if ($owner['holdingOrderId'] !== $order->orderId) {
            throw SeatRefusal::because(sprintf('%s, because order %s holds it, not order %s. Confirm each order with its own seats; nothing of the order was sold.', $refusedSeat, $owner['holdingOrderId'], $order->orderId));
        }
    }
}
