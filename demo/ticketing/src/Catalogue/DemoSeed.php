<?php

declare(strict_types=1);

namespace App\Catalogue;

use DateTimeImmutable;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\QueryBus;

final class DemoSeed
{
    private const VENUE_NAME = 'Riverside Hall';

    #[ConsoleCommand('demo:seed', 'Seeds the demo catalogue: Riverside Hall and three concerts, created only when missing')]
    public function seed(CommandBus $commandBus, QueryBus $queryBus, EcotoneClockInterface $clock): void
    {
        $venueId = $this->riversideHall($commandBus, $queryBus);
        $seededConcerts = array_column($queryBus->sendWithRouting('concerts.list'), null, 'artist');
        $today = DateTimeImmutable::createFromInterface($clock->now())->setTime(20, 0);

        foreach ([
            ['artist' => 'The Midnight Owls', 'startsAt' => $today->modify('+1 month'), 'onSale' => true],
            ['artist' => 'Aurora Strings', 'startsAt' => $today->modify('+3 months'), 'onSale' => false],
            ['artist' => 'Neon Harbour', 'startsAt' => $today->modify('+14 days'), 'onSale' => true],
        ] as $concert) {
            $seeded = $seededConcerts[$concert['artist']] ?? null;
            $concertId = $seeded['concertId'] ?? $commandBus->send(new ScheduleConcert(
                venueId: $venueId,
                artist: $concert['artist'],
                startsAt: $concert['startsAt'],
                standardPriceCents: 4500,
                vipPriceCents: 9500,
                currency: 'EUR',
            ));
            if ($concert['onSale'] && ($seeded['status'] ?? ConcertStatus::SCHEDULED->value) === ConcertStatus::SCHEDULED->value) {
                $commandBus->send(new OpenSales($concertId));
            }
        }
    }

    private function riversideHall(CommandBus $commandBus, QueryBus $queryBus): string
    {
        foreach ($queryBus->sendWithRouting('venues.list') as $venue) {
            if ($venue['name'] === self::VENUE_NAME) {
                return $venue['venueId'];
            }
        }

        return $commandBus->send(new CreateVenue(name: self::VENUE_NAME, sections: ['Stalls', 'Balcony'], rowsPerSection: 3, seatsPerRow: 12, vipRows: ['A', 'B']));
    }
}
