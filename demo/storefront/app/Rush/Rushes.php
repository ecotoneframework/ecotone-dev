<?php

declare(strict_types=1);

namespace App\Rush;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface Rushes
{
    #[DbalWrite(<<<'SQL'
        INSERT INTO rushes (rush_id, concert_id, buyers_requested, seats_per_buyer, declined_share, outage_share, bounce_share, buyer_deadline_seconds, started_at, deadline_at)
        VALUES (:rushId, :concertId, :buyersRequested, :seatsPerBuyer, :declinedShare, :outageShare, :bounceShare, :buyerDeadlineSeconds, :startedAt, :deadlineAt)
        ON CONFLICT (rush_id) DO NOTHING
        SQL)]
    public function start(string $rushId, string $concertId, int $buyersRequested, int $seatsPerBuyer, int $declinedShare, int $outageShare, int $bounceShare, int $buyerDeadlineSeconds, string $startedAt, string $deadlineAt): int;

    #[DbalWrite('UPDATE rushes SET last_tick = :tick WHERE rush_id = :rushId AND last_tick = :tick - 1 AND finished_at IS NULL')]
    public function claimTick(string $rushId, int $tick): int;

    #[DbalQuery('SELECT rush_id, concert_id, buyers_requested, seats_per_buyer, declined_share, outage_share, bounce_share, buyer_deadline_seconds, started_at, deadline_at FROM rushes WHERE rush_id = :rushId', fetchMode: FetchMode::FIRST_ROW)]
    public function find(string $rushId): array|false;

    #[DbalWrite(<<<'SQL'
        INSERT INTO rush_buyers (rush_id, buyer_number, customer_id, order_id, concert_id, role, stage, pending_seat_id, refused_seat_ids, joined_at)
        VALUES (:rushId, :buyerNumber, :customerId, :orderId, :concertId, :role, 'picking', NULL, '[]', :joinedAt)
        SQL)]
    public function addBuyer(string $rushId, int $buyerNumber, string $customerId, string $orderId, string $concertId, string $role, string $joinedAt): void;

    #[DbalQuery('SELECT count(*) FROM rush_buyers WHERE rush_id = :rushId', fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW)]
    public function buyersJoinedOf(string $rushId): int;

    #[DbalQuery(<<<'SQL'
        SELECT b.buyer_number, b.order_id, b.role, b.pending_seat_id, b.refused_seat_ids, b.asked_at, o.status, o.held_seats, o.last_refusal
        FROM rush_buyers b
        JOIN orders o ON o.id = b.order_id
        WHERE b.rush_id = :rushId AND b.stage = 'picking'
        ORDER BY b.asked_at NULLS LAST, b.buyer_number
        SQL)]
    public function buyersPickingOf(string $rushId): array;

    #[DbalWrite(<<<'SQL'
        UPDATE rush_buyers SET stage = :stage, pending_seat_id = NULLIF(:pendingSeatId, ''), refused_seat_ids = CAST(:refusedSeatIds AS json),
            asked_at = COALESCE(asked_at, CASE WHEN :pendingSeatId <> '' THEN CAST(:recordedAt AS timestamptz) END)
        WHERE rush_id = :rushId AND buyer_number = :buyerNumber
        SQL)]
    public function recordProgress(string $rushId, int $buyerNumber, string $stage, string $pendingSeatId, string $refusedSeatIds, string $recordedAt): void;

    #[DbalQuery("SELECT pending_seat_id FROM rush_buyers WHERE concert_id = :concertId AND stage = 'picking' AND pending_seat_id IS NOT NULL", fetchMode: FetchMode::FIRST_COLUMN)]
    public function pendingSeatIdsOf(string $concertId): array;

    #[DbalWrite('UPDATE rushes SET finished_at = :finishedAt WHERE rush_id = :rushId AND finished_at IS NULL')]
    public function finish(string $rushId, string $finishedAt): void;

    #[DbalQuery(<<<'SQL'
        SELECT r.rush_id, r.concert_id, r.buyers_requested, r.seats_per_buyer, r.started_at, r.finished_at,
            count(b.buyer_number) AS created,
            count(*) FILTER (WHERE o.status = 'seats_held' AND b.stage <> 'gave_up') AS held,
            count(*) FILTER (WHERE o.status = 'paying') AS paying,
            count(*) FILTER (WHERE o.status = 'paid') AS paid,
            count(*) FILTER (WHERE o.status = 'tickets_issued') AS issued,
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
