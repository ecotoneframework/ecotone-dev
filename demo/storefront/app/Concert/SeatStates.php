<?php

declare(strict_types=1);

namespace App\Concert;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface SeatStates
{
    #[DbalWrite(<<<'SQL'
        INSERT INTO seat_holds (concert_id, seat_id, hold_id, order_id, ended, expires_at) VALUES (:concertId, :seatId, :holdId, :orderId, false, :expiresAt)
        ON CONFLICT (concert_id, seat_id, hold_id) DO NOTHING
        SQL)]
    public function hold(string $concertId, string $seatId, string $holdId, string $orderId, string $expiresAt): void;

    #[DbalWrite(<<<'SQL'
        INSERT INTO seat_holds (concert_id, seat_id, hold_id, order_id, ended) VALUES (:concertId, :seatId, :holdId, :orderId, true)
        ON CONFLICT (concert_id, seat_id, hold_id) DO UPDATE SET ended = true
        SQL)]
    public function endHold(string $concertId, string $seatId, string $holdId, string $orderId): void;

    #[DbalWrite(<<<'SQL'
        INSERT INTO sold_seats (concert_id, seat_id, order_id) VALUES (:concertId, :seatId, :orderId)
        ON CONFLICT (concert_id, seat_id) DO NOTHING
        SQL)]
    public function sell(string $concertId, string $seatId, string $orderId): void;

    /**
     * @return list<string>
     */
    #[DbalQuery(<<<'SQL'
        SELECT DISTINCT seat_id FROM seat_holds
        WHERE concert_id = :concertId AND NOT ended AND expires_at > :now AND order_id <> :excludingOrderId
        SQL, fetchMode: FetchMode::FIRST_COLUMN)]
    public function liveHoldsAt(string $concertId, string $now, string $excludingOrderId): array;

    /**
     * @return list<string>
     */
    #[DbalQuery('SELECT seat_id FROM sold_seats WHERE concert_id = :concertId', fetchMode: FetchMode::FIRST_COLUMN)]
    public function soldOf(string $concertId): array;
}
