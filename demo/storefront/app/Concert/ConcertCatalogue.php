<?php

declare(strict_types=1);

namespace App\Concert;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface ConcertCatalogue
{
    #[DbalWrite(<<<'SQL'
        INSERT INTO concerts (concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, status, seats)
        VALUES (:concertId, :artist, :venueName, :startsAt, :currency, :standardPriceCents, :vipPriceCents, 'scheduled', CAST(:seats AS json))
        ON CONFLICT (concert_id) DO UPDATE SET artist = EXCLUDED.artist, venue_name = EXCLUDED.venue_name, starts_at = EXCLUDED.starts_at,
            currency = EXCLUDED.currency, standard_price_cents = EXCLUDED.standard_price_cents, vip_price_cents = EXCLUDED.vip_price_cents, seats = EXCLUDED.seats
        SQL)]
    public function schedule(string $concertId, string $artist, string $venueName, string $startsAt, string $currency, int $standardPriceCents, int $vipPriceCents, string $seats): void;

    #[DbalWrite("UPDATE concerts SET status = CASE WHEN status = 'scheduled' THEN 'on_sale' ELSE status END WHERE concert_id = :concertId")]
    public function openSales(string $concertId): int;

    #[DbalWrite("UPDATE concerts SET status = 'cancelled' WHERE concert_id = :concertId")]
    public function cancel(string $concertId): int;

    #[DbalQuery(<<<'SQL'
        SELECT concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, status
        FROM concerts ORDER BY status = 'on_sale' DESC, starts_at, artist
        SQL)]
    public function all(): array;

    #[DbalQuery('SELECT concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, status, seats FROM concerts WHERE concert_id = :concertId', fetchMode: FetchMode::FIRST_ROW)]
    public function find(string $concertId): array|false;
}
