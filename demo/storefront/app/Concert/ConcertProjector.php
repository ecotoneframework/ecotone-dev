<?php

declare(strict_types=1);

namespace App\Concert;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;

final class ConcertProjector
{
    private const TIER_ORDER = ['vip', 'standard'];

    #[Distributed]
    #[EventHandler('concert.scheduled')]
    public function whenScheduled(array $event, #[Reference] ConcertCatalogue $catalogue): void
    {
        foreach ($event['seats'] as $seat) {
            if (SeatCode::rowAndNumber($seat['seatCode']) === null) {
                throw new DomainException(sprintf(
                    'concert.scheduled for concert %s names seat code "%s", which is not ROW-NUMBER like "A-1", so the storefront cannot place the seat in its row. Correct the seat code in ticketing and publish concert.scheduled for this concert again, then delete this message from the dbal_dead_letter.',
                    $event['concertId'],
                    $seat['seatCode'],
                ));
            }
        }
        $catalogue->schedule(
            concertId: $event['concertId'],
            version: $event['version'],
            artist: $event['artist'],
            venueName: $event['venueName'],
            startsAt: $event['startsAt'],
            currency: $event['currency'],
            standardPriceCents: $event['tiers']['standard'],
            vipPriceCents: $event['tiers']['vip'],
            seats: json_encode($event['seats'], JSON_THROW_ON_ERROR),
        );
    }

    #[Distributed]
    #[EventHandler('concert.sales_opened')]
    public function whenSalesOpened(array $event, #[Reference] ConcertCatalogue $catalogue): void
    {
        $catalogue->advanceStatusTo(concertId: $event['concertId'], status: 'on_sale');
    }

    #[Distributed]
    #[EventHandler('concert.cancelled')]
    public function whenCancelled(array $event, #[Reference] ConcertCatalogue $catalogue): void
    {
        $catalogue->advanceStatusTo(concertId: $event['concertId'], status: 'cancelled');
    }

    #[Distributed]
    #[EventHandler('concert.prices_changed')]
    public function whenPricesChanged(array $event, #[Reference] ConcertCatalogue $catalogue): void
    {
        $catalogue->changePrices(concertId: $event['concertId'], version: $event['version'], standardPriceCents: $event['tiers']['standard'], vipPriceCents: $event['tiers']['vip']);
    }

    #[Distributed]
    #[EventHandler('concert.sold_out')]
    public function whenSoldOut(array $event, #[Reference] ConcertCatalogue $catalogue): void
    {
        $catalogue->advanceStatusTo(concertId: $event['concertId'], status: 'sold_out');
    }

    #[QueryHandler('concerts.list')]
    public function list(#[Reference] ConcertCatalogue $catalogue): array
    {
        return array_map($this->summary(...), $catalogue->all());
    }

    #[QueryHandler('concert.detail')]
    public function detail(string $concertId, #[Reference] ConcertCatalogue $catalogue): ?array
    {
        $row = $catalogue->find($concertId);
        if ($row === false) {
            return null;
        }

        return [...$this->summary($row), 'sections' => $this->sections(json_decode($row['seats'], true, flags: JSON_THROW_ON_ERROR))];
    }

    private function summary(array $row): array
    {
        return [
            'concertId' => $row['concert_id'],
            'artist' => $row['artist'],
            'venueName' => $row['venue_name'],
            'startsAt' => (new DateTimeImmutable($row['starts_at']))->format(DateTimeInterface::ATOM),
            'currency' => $row['currency'],
            'standardPriceCents' => (int) $row['standard_price_cents'],
            'vipPriceCents' => (int) $row['vip_price_cents'],
            'status' => $row['status'],
        ];
    }

    private function sections(array $seats): array
    {
        usort($seats, fn (array $left, array $right) => [array_search($left['tier'], self::TIER_ORDER, true), ...SeatCode::rowAndNumber($left['seatCode'])]
            <=> [array_search($right['tier'], self::TIER_ORDER, true), ...SeatCode::rowAndNumber($right['seatCode'])]);

        $sections = [];
        foreach ($seats as $seat) {
            $sections[$seat['tier']][SeatCode::rowAndNumber($seat['seatCode'])[0]][] = $seat;
        }

        return array_values(array_map(
            fn (string $tier, array $rows) => ['tier' => $tier, 'rows' => array_map(fn (string $row, array $rowSeats) => ['row' => $row, 'seats' => $rowSeats], array_keys($rows), $rows)],
            array_keys($sections),
            $sections,
        ));
    }
}
