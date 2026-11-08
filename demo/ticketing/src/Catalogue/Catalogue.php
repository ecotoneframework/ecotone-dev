<?php

declare(strict_types=1);

namespace App\Catalogue;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\QueryHandler;

final class Catalogue
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{venueId: string, name: string, seatCount: int}>
     */
    #[QueryHandler('venues.list')]
    public function venues(): array
    {
        return array_map(
            static fn (array $venue): array => ['venueId' => $venue['venue_id'], 'name' => $venue['name'], 'seatCount' => (int) $venue['seat_count']],
            $this->connection->fetchAllAssociative('SELECT venue_id, name, json_array_length(rows) * seats_per_row AS seat_count FROM venues ORDER BY name, venue_id'),
        );
    }

    /**
     * @return list<array{concertId: string, artist: string, venueName: string, startsAt: string, status: string, standardPriceCents: int, vipPriceCents: int, currency: string}>
     */
    #[QueryHandler('concerts.list')]
    public function concerts(): array
    {
        return array_map(
            static fn (array $concert): array => [
                'concertId' => $concert['concert_id'],
                'artist' => $concert['artist'],
                'venueName' => $concert['venue_name'],
                'startsAt' => (new DateTimeImmutable($concert['starts_at']))->format(DateTimeInterface::ATOM),
                'status' => $concert['status'],
                'standardPriceCents' => (int) $concert['standard_price_cents'],
                'vipPriceCents' => (int) $concert['vip_price_cents'],
                'currency' => $concert['currency'],
            ],
            $this->connection->fetchAllAssociative('SELECT concert_id, artist, venue_name, starts_at, status, standard_price_cents, vip_price_cents, currency FROM concerts ORDER BY starts_at, concert_id'),
        );
    }
}
