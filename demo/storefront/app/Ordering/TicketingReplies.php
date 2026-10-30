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
            'held' => new MarkSeatHeld(orderId: $result['orderId'], seatId: $result['seatId'], holdId: $result['holdId'], expiresAt: (string) $result['expiresAt']),
            'refused' => new RecordSeatRefusal(orderId: $result['orderId'], seatId: $result['seatId'], holdId: $result['holdId'], reason: (string) $result['reason'], reasonCode: self::reasonCodeOf($result)),
            default => throw new DomainException(sprintf(
                'tickets.hold.result for seat %s of order %s has outcome "%s", but the contract has only "held" and "refused", so the storefront cannot tell whether the seat is the customer\'s. Correct the reply in ticketing and replay this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:replay <messageId>.',
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
            'sold' => $commandBus->send(new MarkSeatsSold(orderId: $result['orderId'])),
            'refused' => $commandBus->send(new RecordConfirmationRefusal(orderId: $result['orderId'], reason: sprintf('Ticketing refused to confirm the seats: %s. A refund of the payment is queued.', rtrim((string) $result['reason'], '.')))),
            default => throw new DomainException(sprintf(
                'tickets.confirm.result for order %s has outcome "%s", but the contract has only "sold" and "refused", so the storefront cannot tell whether the seats are sold. Correct the reply in ticketing and replay this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:replay <messageId>.',
                $result['orderId'],
                $result['outcome'],
            )),
        };
    }

    #[Distributed]
    #[EventHandler('tickets.hold_expired')]
    public function whenHoldExpired(array $event, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new DropExpiredSeat(orderId: $event['orderId'], seatId: $event['seatId'], holdId: $event['holdId']));
    }

    #[Distributed]
    #[EventHandler('tickets.issued')]
    public function whenTicketsIssued(array $event, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new MarkTicketsIssued(orderId: $event['orderId'], tickets: $event['tickets']));
    }

    private static function reasonCodeOf(array $result): string
    {
        if (! is_string($result['reasonCode'] ?? null) || $result['reasonCode'] === '') {
            throw new DomainException(sprintf(
                'tickets.hold.result for seat %s of order %s is refused without a reasonCode, so the storefront cannot tell why ticketing refused the seat. Send the reasonCode of the refusal next to its reason, one of the codes the service contract in demo/README.md lists, and replay this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:replay <messageId>.',
                $result['seatId'],
                $result['orderId'],
            ));
        }

        return $result['reasonCode'];
    }
}
