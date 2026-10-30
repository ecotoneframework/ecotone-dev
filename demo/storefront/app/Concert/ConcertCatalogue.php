<?php

declare(strict_types=1);

namespace App\Concert;

use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\FetchMode;

interface ConcertCatalogue
{
    #[DbalWrite(<<<'SQL'
        INSERT INTO concerts (concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, prices_version, status, seats)
        VALUES (:concertId, :artist, :venueName, :startsAt, :currency, :standardPriceCents, :vipPriceCents, :version, 'scheduled', CAST(:seats AS json))
        ON CONFLICT (concert_id) DO UPDATE SET artist = EXCLUDED.artist, venue_name = EXCLUDED.venue_name, starts_at = EXCLUDED.starts_at,
            currency = EXCLUDED.currency, seats = EXCLUDED.seats,
            standard_price_cents = CASE WHEN EXCLUDED.prices_version >= concerts.prices_version THEN EXCLUDED.standard_price_cents ELSE concerts.standard_price_cents END,
            vip_price_cents = CASE WHEN EXCLUDED.prices_version >= concerts.prices_version THEN EXCLUDED.vip_price_cents ELSE concerts.vip_price_cents END,
            prices_version = GREATEST(EXCLUDED.prices_version, concerts.prices_version)
        SQL)]
    public function schedule(string $concertId, int $version, string $artist, string $venueName, string $startsAt, string $currency, int $standardPriceCents, int $vipPriceCents, string $seats): void;

    #[DbalWrite(<<<'SQL'
        INSERT INTO concerts (concert_id, status) VALUES (:concertId, :status)
        ON CONFLICT (concert_id) DO UPDATE SET status = CASE
            WHEN array_position(ARRAY['scheduled', 'on_sale', 'sold_out', 'cancelled']::varchar[], EXCLUDED.status) > array_position(ARRAY['scheduled', 'on_sale', 'sold_out', 'cancelled']::varchar[], concerts.status)
            THEN EXCLUDED.status ELSE concerts.status END
        SQL)]
    public function advanceStatusTo(string $concertId, string $status): void;

    #[DbalWrite(<<<'SQL'
        INSERT INTO concerts (concert_id, status, standard_price_cents, vip_price_cents, prices_version) VALUES (:concertId, 'scheduled', :standardPriceCents, :vipPriceCents, :version)
        ON CONFLICT (concert_id) DO UPDATE SET
            standard_price_cents = CASE WHEN EXCLUDED.prices_version >= concerts.prices_version THEN EXCLUDED.standard_price_cents ELSE concerts.standard_price_cents END,
            vip_price_cents = CASE WHEN EXCLUDED.prices_version >= concerts.prices_version THEN EXCLUDED.vip_price_cents ELSE concerts.vip_price_cents END,
            prices_version = GREATEST(EXCLUDED.prices_version, concerts.prices_version)
        SQL)]
    public function changePrices(string $concertId, int $version, int $standardPriceCents, int $vipPriceCents): void;

    #[DbalQuery(<<<'SQL'
        SELECT concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, status
        FROM concerts WHERE seats IS NOT NULL ORDER BY status = 'on_sale' DESC, starts_at, artist
        SQL)]
    public function all(): array;

    #[DbalQuery(<<<'SQL'
        SELECT concert_id, artist, venue_name, starts_at, currency, standard_price_cents, vip_price_cents, status, seats
        FROM concerts WHERE concert_id = :concertId AND seats IS NOT NULL
        SQL, fetchMode: FetchMode::FIRST_ROW)]
    public function find(string $concertId): array|false;
}
