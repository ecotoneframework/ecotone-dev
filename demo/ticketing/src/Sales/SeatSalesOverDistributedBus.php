<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeInterface;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use InvalidArgumentException;

final class SeatSalesOverDistributedBus
{
    private const SEAT_FIELDS = ['orderId', 'concertId', 'seatId', 'customerId'];

    /**
     * @return array{orderId: string, concertId: string, seatId: string, customerId: string, outcome: string, reason: ?string, expiresAt: ?string}
     */
    #[Distributed]
    #[CommandHandler('tickets.hold')]
    public function hold(array $payload, CommandBus $commandBus, QueryBus $queryBus): array
    {
        $seat = self::seatFields('tickets.hold', $payload);
        $reply = [...$seat, 'outcome' => 'refused', 'reason' => null, 'expiresAt' => null];
        try {
            $commandBus->send(new HoldSeat(...$seat), metadata: ['concertId' => $seat['concertId']]);
        } catch (SeatRefusal $refusal) {
            return [...$reply, 'reason' => $refusal->getMessage()];
        } catch (DecisionModelConcurrencyException) {
            return [...$reply, 'reason' => sprintf('Seat %s cannot be held for order %s, because someone was faster: the seat changed each time ticketing tried to hold it. Pick the seat again, or choose another one.', $seat['seatId'], $seat['orderId'])];
        }
        $hold = $queryBus->send(new FindSeatHold($seat['seatId']));

        return [...$reply, 'outcome' => 'held', 'expiresAt' => $hold['expiresAt']->format(DateTimeInterface::ATOM)];
    }

    #[Distributed]
    #[CommandHandler('tickets.release')]
    public function release(array $payload, CommandBus $commandBus): void
    {
        $seat = self::seatFields('tickets.release', $payload);
        try {
            $commandBus->send(new ReleaseSeat(...$seat), metadata: ['concertId' => $seat['concertId']]);
        } catch (SeatRefusal) {
        }
    }

    /**
     * @return array{orderId: string, concertId: string, customerId: string, outcome: string, reason: ?string, seatIds: list<string>}
     */
    #[Distributed]
    #[CommandHandler('tickets.confirm')]
    public function confirm(array $payload, CommandBus $commandBus): array
    {
        $order = self::orderFields($payload);
        $reply = [
            'orderId' => $order['orderId'],
            'concertId' => $order['concertId'],
            'customerId' => $order['customerId'],
            'outcome' => 'sold',
            'reason' => null,
            'seatIds' => $order['seatIds'],
        ];
        try {
            $commandBus->send(new ConfirmSeats(...$order), metadata: ['concertId' => $order['concertId']]);
        } catch (SeatRefusal $refusal) {
            return [...$reply, 'outcome' => 'refused', 'reason' => $refusal->getMessage()];
        } catch (DecisionModelConcurrencyException) {
            return [...$reply, 'outcome' => 'refused', 'reason' => sprintf('Order %s cannot be confirmed, because someone was faster: its seats changed each time ticketing tried to sell them; nothing of the order was sold. Confirm the order again.', $order['orderId'])];
        }

        return $reply;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{orderId: string, concertId: string, seatId: string, customerId: string}
     */
    private static function seatFields(string $routingKey, array $payload): array
    {
        self::assertStringFields(routingKey: $routingKey, payload: $payload, fields: self::SEAT_FIELDS, contract: sprintf('Send %s as a JSON object of %s, each a non-empty string.', $routingKey, implode(', ', self::SEAT_FIELDS)));

        return ['orderId' => $payload['orderId'], 'concertId' => $payload['concertId'], 'seatId' => $payload['seatId'], 'customerId' => $payload['customerId']];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{orderId: string, concertId: string, customerId: string, seatIds: list<string>, paymentReference: string}
     */
    private static function orderFields(array $payload): array
    {
        $contract = 'Send tickets.confirm as a JSON object of orderId, concertId, customerId and paymentReference, each a non-empty string, and seatIds, a list of seat id strings.';
        self::assertStringFields(routingKey: 'tickets.confirm', payload: $payload, fields: ['orderId', 'concertId', 'customerId', 'paymentReference'], contract: $contract);
        $seatIds = $payload['seatIds'] ?? null;
        if (! is_array($seatIds) || ! array_is_list($seatIds) || array_filter($seatIds, static fn (mixed $seatId): bool => ! is_string($seatId) || $seatId === '') !== []) {
            throw new InvalidArgumentException(sprintf('ticketing received tickets.confirm for order %s whose seatIds is %s, not a list of seat id strings. %s', $payload['orderId'], json_encode($seatIds), $contract));
        }

        return ['orderId' => $payload['orderId'], 'concertId' => $payload['concertId'], 'customerId' => $payload['customerId'], 'seatIds' => $seatIds, 'paymentReference' => $payload['paymentReference']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private static function assertStringFields(string $routingKey, array $payload, array $fields, string $contract): void
    {
        $missing = array_values(array_filter($fields, static fn (string $field): bool => ! is_string($payload[$field] ?? null) || $payload[$field] === ''));
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('ticketing received %s without %s: the payload carries %s. %s', $routingKey, implode(', ', $missing), implode(', ', array_keys($payload)) ?: 'nothing', $contract));
        }
    }
}
