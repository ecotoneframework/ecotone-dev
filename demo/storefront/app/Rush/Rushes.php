<?php

declare(strict_types=1);

namespace App\Rush;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface Rushes
{
    #[DbalWrite(<<<'SQL'
        INSERT INTO rushes (rush_id, concert_id, buyers_requested, seats_per_buyer, declined_share, outage_share, bounce_share, started_at)
        VALUES (:rushId, :concertId, :buyersRequested, :seatsPerBuyer, :declinedShare, :outageShare, :bounceShare, :startedAt)
        ON CONFLICT (rush_id) DO NOTHING
        SQL)]
    public function start(string $rushId, string $concertId, int $buyersRequested, int $seatsPerBuyer, int $declinedShare, int $outageShare, int $bounceShare, string $startedAt): int;

    #[DbalQuery('SELECT rush_id, concert_id, buyers_requested, seats_per_buyer, declined_share, outage_share, bounce_share FROM rushes WHERE rush_id = :rushId', fetchMode: FetchMode::FIRST_ROW)]
    public function find(string $rushId): array|false;

    #[DbalWrite(<<<'SQL'
        INSERT INTO rush_buyers (rush_id, buyer_number, customer_id, order_id, concert_id, role, stage, pending_seat_id, refused_seat_ids, last_check)
        VALUES (:rushId, :buyerNumber, :customerId, :orderId, :concertId, :role, 'picking', NULL, '[]', 0)
        SQL)]
    public function addBuyer(string $rushId, int $buyerNumber, string $customerId, string $orderId, string $concertId, string $role): void;

    #[DbalQuery('SELECT buyer_number, customer_id, order_id, role, stage, pending_seat_id, refused_seat_ids, last_check FROM rush_buyers WHERE rush_id = :rushId AND buyer_number = :buyerNumber', fetchMode: FetchMode::FIRST_ROW)]
    public function buyer(string $rushId, int $buyerNumber): array|false;

    #[DbalWrite(<<<'SQL'
        UPDATE rush_buyers SET stage = :stage, pending_seat_id = NULLIF(:pendingSeatId, ''), refused_seat_ids = CAST(:refusedSeatIds AS json), last_check = :lastCheck
        WHERE rush_id = :rushId AND buyer_number = :buyerNumber
        SQL)]
    public function recordCheck(string $rushId, int $buyerNumber, string $stage, string $pendingSeatId, string $refusedSeatIds, int $lastCheck): void;

    #[DbalQuery("SELECT pending_seat_id FROM rush_buyers WHERE concert_id = :concertId AND stage = 'picking' AND pending_seat_id IS NOT NULL", fetchMode: FetchMode::FIRST_COLUMN)]
    public function pendingSeatIdsOf(string $concertId): array;

    #[DbalWrite(<<<'SQL'
        UPDATE rushes SET finished_at = :finishedAt
        WHERE rush_id = :rushId AND finished_at IS NULL
            AND buyers_requested = (SELECT count(*) FROM rush_buyers WHERE rush_id = :rushId AND stage <> 'picking')
        SQL)]
    public function finishWhenEveryBuyerIsDone(string $rushId, string $finishedAt): void;

    #[DbalQuery(<<<'SQL'
        SELECT r.rush_id, r.concert_id, r.buyers_requested, r.seats_per_buyer, r.started_at, r.finished_at,
            count(b.buyer_number) AS created,
            count(*) FILTER (WHERE b.stage = 'paying') AS held,
            count(*) FILTER (WHERE b.stage = 'paying' AND o.status = 'paying') AS paying,
            count(*) FILTER (WHERE o.status IN ('paid', 'tickets_issued')) AS paid,
            count(*) FILTER (WHERE o.status = 'failed' AND b.stage <> 'gave_up') AS failed,
            count(*) FILTER (WHERE b.stage = 'gave_up') AS gave_up
        FROM rushes r
        LEFT JOIN rush_buyers b ON b.rush_id = r.rush_id
        LEFT JOIN orders o ON o.id = b.order_id
        WHERE r.rush_id = :rushId
        GROUP BY r.rush_id
        SQL, fetchMode: FetchMode::FIRST_ROW)]
    public function progressOf(string $rushId): array|false;

    #[DbalQuery('SELECT buyer_number, customer_id, order_id, stage FROM rush_buyers WHERE rush_id = :rushId ORDER BY buyer_number')]
    public function buyersOf(string $rushId): array;
}
