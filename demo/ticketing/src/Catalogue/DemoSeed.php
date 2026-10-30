<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Sales\ConfirmSeedOrder;
use App\Sales\CustomerConcertSeats;
use App\Sales\HoldSeat;
use App\Sales\SeatRefusal;
use DateTimeImmutable;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\QueryBus;
use Symfony\Component\Uid\Uuid;

final class DemoSeed
{
    private const VENUE_NAME = 'Riverside Hall';

    private const NEARLY_SOLD_OUT_ARTIST = 'Neon Harbour';

    private const SEATS_LEFT_UNSOLD = 3;

    #[ConsoleCommand('demo:seed', 'Seeds the demo catalogue: Riverside Hall and three concerts, created only when missing, with all but three seats of Neon Harbour sold unless --catalogueOnly')]
    public function seed(CommandBus $commandBus, QueryBus $queryBus, EcotoneClockInterface $clock, #[ConsoleParameterOption] bool $catalogueOnly = false): void
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
            if ($concert['artist'] === self::NEARLY_SOLD_OUT_ARTIST && ! $catalogueOnly) {
                $this->sellAllButTheLastSeats(commandBus: $commandBus, queryBus: $queryBus, concertId: $concertId);
            }
        }
    }

    private function sellAllButTheLastSeats(CommandBus $commandBus, QueryBus $queryBus, string $concertId): void
    {
        $sales = $queryBus->sendWithRouting('concert.sales', $concertId);
        if ($sales['seatsSold'] >= $sales['seatCount'] - self::SEATS_LEFT_UNSOLD) {
            return;
        }

        $seatIds = array_column($queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId])['seats'], 'seatId');
        $seatIdsToSell = array_slice($seatIds, 0, count($seatIds) - self::SEATS_LEFT_UNSOLD);
        foreach (array_chunk($seatIdsToSell, CustomerConcertSeats::MAXIMUM_SEATS) as $buyer => $seatIdsOfTheBuyer) {
            $customerId = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), sprintf('https://ecotone.tech/demo/customers/%d', $buyer + 1))->toRfc4122();
            $orderId = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), sprintf('https://ecotone.tech/demo/concerts/%s/orders/%d', $concertId, $buyer + 1))->toRfc4122();
            $heldSeatIds = [];
            foreach ($seatIdsOfTheBuyer as $seatId) {
                try {
                    $commandBus->send(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $seatId, customerId: $customerId, holdId: 'demo-seed-' . $orderId), metadata: ['concertId' => $concertId]);
                    $heldSeatIds[] = $seatId;
                } catch (SeatRefusal) {
                }
            }
            if ($heldSeatIds !== []) {
                $commandBus->send(
                    new ConfirmSeedOrder(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: $heldSeatIds, paymentReference: 'demo-seed-' . $orderId),
                    metadata: ['concertId' => $concertId],
                );
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
