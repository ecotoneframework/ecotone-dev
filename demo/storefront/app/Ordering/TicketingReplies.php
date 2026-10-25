<?php

declare(strict_types=1);

namespace App\Ordering;

use DomainException;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;

final class TicketingReplies
{
    #[InternalHandler('tickets.hold.result')]
    public function whenHoldAnswered(array $result, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(match ($result['outcome']) {
            'held' => new MarkSeatHeld(orderId: $result['orderId'], seatId: $result['seatId'], expiresAt: (string) $result['expiresAt']),
            'refused' => new RecordSeatRefusal(orderId: $result['orderId'], seatId: $result['seatId'], reason: (string) $result['reason']),
            default => throw new DomainException(sprintf(
                'tickets.hold.result for seat %s of order %s has outcome "%s", but the contract has only "held" and "refused", so the storefront cannot tell whether the seat is the customer\'s. Correct the reply in ticketing and replay this message from the dead letters in Ecotone Pulse.',
                $result['seatId'],
                $result['orderId'],
                $result['outcome'],
            )),
        });
    }

    #[InternalHandler('tickets.confirm.result')]
    public function whenConfirmationAnswered(array $result, #[Reference] CommandBus $commandBus): void
    {
        match ($result['outcome']) {
            'sold' => null,
            'refused' => $commandBus->send(new FailOrder(orderId: $result['orderId'], reason: sprintf('Ticketing refused to confirm the seats: %s. The payment is refunded.', $result['reason']))),
            default => throw new DomainException(sprintf(
                'tickets.confirm.result for order %s has outcome "%s", but the contract has only "sold" and "refused", so the storefront cannot tell whether the seats are sold. Correct the reply in ticketing and replay this message from the dead letters in Ecotone Pulse.',
                $result['orderId'],
                $result['outcome'],
            )),
        };
    }

    #[Distributed]
    #[EventHandler('tickets.hold_expired')]
    public function whenHoldExpired(array $event, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new DropExpiredSeat(orderId: $event['orderId'], seatId: $event['seatId']));
    }

    #[Distributed]
    #[EventHandler('tickets.issued')]
    public function whenTicketsIssued(array $event, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new MarkTicketsIssued(orderId: $event['orderId'], tickets: $event['tickets']));
    }
}
