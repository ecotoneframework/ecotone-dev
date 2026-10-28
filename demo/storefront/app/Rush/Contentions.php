<?php

declare(strict_types=1);

namespace App\Rush;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface Contentions
{
    #[DbalWrite('INSERT INTO contentions (contention_id, concert_id, seat_id, buyers_requested, started_at) VALUES (:contentionId, :concertId, :seatId, :buyersRequested, :startedAt)')]
    public function start(string $contentionId, string $concertId, string $seatId, int $buyersRequested, string $startedAt): void;

    #[DbalWrite('INSERT INTO contention_buyers (contention_id, buyer_number, customer_id, order_id, hold_id) VALUES (:contentionId, :buyerNumber, :customerId, :orderId, :holdId)')]
    public function addBuyer(string $contentionId, int $buyerNumber, string $customerId, string $orderId, string $holdId): void;

    #[DbalQuery('SELECT contention_id, concert_id, seat_id, buyers_requested FROM contentions WHERE contention_id = :contentionId', fetchMode: FetchMode::FIRST_ROW)]
    public function find(string $contentionId): array|false;

    #[DbalQuery('SELECT buyer_number FROM contention_buyers WHERE order_id = :orderId', fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW)]
    public function buyerNumberOfOrder(string $orderId): int|false;

    #[DbalQuery(<<<'SQL'
        SELECT b.buyer_number, b.customer_id, b.order_id, b.hold_id, c.name, o.held_seats, o.requested_holds, o.last_refusal
        FROM contention_buyers b
        JOIN customers c ON c.id = b.customer_id
        JOIN orders o ON o.id = b.order_id
        WHERE b.contention_id = :contentionId
        ORDER BY b.buyer_number
        SQL)]
    public function buyersOf(string $contentionId): array;
}
