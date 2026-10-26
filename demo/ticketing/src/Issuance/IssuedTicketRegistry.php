<?php

declare(strict_types=1);

namespace App\Issuance;

use App\Catalogue\Tier;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\QueryHandler;
use Symfony\Component\Uid\Uuid;

final class IssuedTicketRegistry
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function register(IssuedTickets $issuedTickets, DateTimeInterface $issuedAt): void
    {
        foreach ($issuedTickets->tickets as $ticket) {
            $this->connection->executeStatement(
                'INSERT INTO issued_tickets (ticket_number, order_id, concert_id, seat_id, tier, entry_code, issued_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (order_id, seat_id) DO NOTHING',
                [$ticket->ticketNumber, $issuedTickets->orderId, $issuedTickets->concertId, $ticket->seatId, $ticket->tier->value, $ticket->entryCode, $issuedAt->format(DateTimeInterface::ATOM)],
            );
        }
    }

    /**
     * @return list<Ticket>
     */
    public function ticketsOfOrder(string $orderId): array
    {
        return array_map(
            static fn (array $row): Ticket => new Ticket(ticketNumber: $row['ticket_number'], seatId: $row['seat_id'], tier: Tier::from($row['tier']), entryCode: $row['entry_code'], vipLoungePass: false),
            $this->rowsOfOrder($orderId),
        );
    }

    /**
     * @return array{ticketNumber: string, orderId: string, concertId: string, seatId: string, seatCode: string, tier: string, entryCode: string, issuedAt: string}|null
     */
    #[QueryHandler('ticket.detail')]
    public function detail(string $ticketNumber): ?array
    {
        if (! Uuid::isValid($ticketNumber)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM issued_tickets WHERE ticket_number = ?', [$ticketNumber]);

        return $row === false ? null : self::detailOf($row);
    }

    /**
     * @return list<array{ticketNumber: string, orderId: string, concertId: string, seatId: string, seatCode: string, tier: string, entryCode: string, issuedAt: string}>
     */
    #[QueryHandler('tickets.of_order')]
    public function ofOrder(string $orderId): array
    {
        return array_map(self::detailOf(...), $this->rowsOfOrder($orderId));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsOfOrder(string $orderId): array
    {
        if (! Uuid::isValid($orderId)) {
            return [];
        }

        return $this->connection->fetchAllAssociative('SELECT * FROM issued_tickets WHERE order_id = ? ORDER BY ticket_number', [$orderId]);
    }

    /**
     * @param array<string, string> $row
     * @return array{ticketNumber: string, orderId: string, concertId: string, seatId: string, seatCode: string, tier: string, entryCode: string, issuedAt: string}
     */
    private static function detailOf(array $row): array
    {
        return [
            'ticketNumber' => $row['ticket_number'],
            'orderId' => $row['order_id'],
            'concertId' => $row['concert_id'],
            'seatId' => $row['seat_id'],
            'seatCode' => substr($row['seat_id'], strrpos($row['seat_id'], '/') + 1),
            'tier' => $row['tier'],
            'entryCode' => $row['entry_code'],
            'issuedAt' => (new DateTimeImmutable($row['issued_at']))->format(DateTimeInterface::ATOM),
        ];
    }
}
