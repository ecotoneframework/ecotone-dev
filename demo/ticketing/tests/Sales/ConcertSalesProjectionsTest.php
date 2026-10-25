<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\Catalogue;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\ConcertSoldOut;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Sales\ConcertSalesLedger;
use App\Sales\ConcertSeatMap;
use App\Sales\ConfirmSeats;
use App\Sales\CustomerConcertSeats;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\HoldSeat;
use App\Sales\ReleaseSeat;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSold;
use App\Sales\SoldOutWatch;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class ConcertSalesProjectionsTest extends KernelTestCase
{
    private const NOW = '2026-10-25 12:00:00';

    public function test_the_seat_map_shows_every_seat_free_until_the_asynchronous_projection_catches_up_with_holds_and_sales(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $holdingOrder = Uuid::v7()->toRfc4122();
        $buyingOrder = Uuid::v7()->toRfc4122();
        $buyer = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $holdingOrder, $concertId, 'A-1', Uuid::v7()->toRfc4122());
        $this->hold($ticketing, $buyingOrder, $concertId, 'C-2', $buyer);
        $this->confirm($ticketing, $buyingOrder, $concertId, $buyer, ['C-2']);

        self::assertSame(['free' => 72], $this->statusCounts($ticketing, $concertId));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $seatMap = array_column($ticketing->sendQueryWithRouting('concert.seat_map', $concertId), null, 'seatCode');
        self::assertCount(72, $seatMap);
        self::assertSame(
            ['seatId' => $concertId . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip', 'status' => 'held', 'orderId' => $holdingOrder, 'expiresAt' => '2026-10-25T12:10:00+00:00'],
            $seatMap['A-1'],
        );
        self::assertSame(
            ['seatId' => $concertId . '/C-2', 'seatCode' => 'C-2', 'tier' => 'standard', 'status' => 'sold', 'orderId' => $buyingOrder, 'expiresAt' => null],
            $seatMap['C-2'],
        );
        self::assertSame(
            ['seatId' => $concertId . '/A-2', 'seatCode' => 'A-2', 'tier' => 'vip', 'status' => 'free', 'orderId' => null, 'expiresAt' => null],
            $seatMap['A-2'],
        );
        self::assertSame(['free' => 70, 'held' => 1, 'sold' => 1], $this->statusCounts($ticketing, $concertId));
    }

    public function test_a_released_and_an_expired_hold_show_their_seats_free_again_on_the_seat_map(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId);
        $this->hold($ticketing, $orderId, $concertId, 'A-2', $customerId);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:10:00'));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['free' => 72], $this->statusCounts($ticketing, $concertId));
    }

    public function test_the_sales_ledger_counts_seats_sold_and_revenue_per_tier_at_the_price_they_were_sold_for(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId);
        $this->hold($ticketing, $orderId, $concertId, 'C-1', $customerId);
        $this->confirm($ticketing, $orderId, $concertId, $customerId, ['A-1', 'C-1']);
        $ticketing->sendCommand(new ChangeTierPrice(concertId: $concertId, tier: Tier::STANDARD, priceCents: 5000));
        $secondOrder = Uuid::v7()->toRfc4122();
        $secondCustomer = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $secondOrder, $concertId, 'C-2', $secondCustomer);
        $this->confirm($ticketing, $secondOrder, $concertId, $secondCustomer, ['C-2']);

        self::assertSame(
            ['concertId' => $concertId, 'seatCount' => 72, 'seatsSold' => 0, 'revenueCents' => 0, 'tiers' => ['standard' => ['seatsSold' => 0, 'revenueCents' => 0], 'vip' => ['seatsSold' => 0, 'revenueCents' => 0]]],
            $ticketing->sendQueryWithRouting('concert.sales', $concertId),
        );

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            ['concertId' => $concertId, 'seatCount' => 72, 'seatsSold' => 3, 'revenueCents' => 19000, 'tiers' => ['standard' => ['seatsSold' => 2, 'revenueCents' => 9500], 'vip' => ['seatsSold' => 1, 'revenueCents' => 9500]]],
            $ticketing->sendQueryWithRouting('concert.sales', $concertId),
        );
    }

    public function test_resetting_both_projections_rebuilds_them_from_the_event_stream(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId);
        $this->hold($ticketing, $orderId, $concertId, 'A-2', $customerId);
        $this->confirm($ticketing, $orderId, $concertId, $customerId, ['A-2']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $seatMap = $ticketing->sendQueryWithRouting('concert.seat_map', $concertId);
        $sales = $ticketing->sendQueryWithRouting('concert.sales', $concertId);

        $ticketing->resetProjection('concert_seat_map');
        $ticketing->resetProjection('concert_sales_ledger');

        self::assertSame($seatMap, $ticketing->sendQueryWithRouting('concert.seat_map', $concertId));
        self::assertSame($sales, $ticketing->sendQueryWithRouting('concert.sales', $concertId));
    }

    public function test_selling_the_last_seat_of_a_concert_marks_it_sold_out_and_records_concert_sold_out(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, rowsPerSection: 1, seatsPerRow: 2);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2', 'B-1'] as $seatCode) {
            $this->hold($ticketing, $orderId, $concertId, $seatCode, $customerId);
        }
        $this->confirm($ticketing, $orderId, $concertId, $customerId, ['A-1', 'A-2', 'B-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('on_sale', $this->statusOf($ticketing, $concertId));

        $lastOrder = Uuid::v7()->toRfc4122();
        $lastCustomer = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $lastOrder, $concertId, 'B-2', $lastCustomer);
        $this->confirm($ticketing, $lastOrder, $concertId, $lastCustomer, ['B-2']);
        $ticketing->discardRecordedMessages();
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('sold_out', $this->statusOf($ticketing, $concertId));
        self::assertEquals([new ConcertSoldOut($concertId)], $ticketing->popRecordedEventsOfType(ConcertSoldOut::class));
    }

    private function ticketing(): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, concert_seat_map, concert_sales_ledger');

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                EcotoneConfiguration::class,
                Venue::class,
                Concert::class,
                Catalogue::class,
                ConcertAnnouncements::class,
                SeatSales::class,
                HoldExpiry::class,
                ConcertSeatMap::class,
                ConcertSalesLedger::class,
                SoldOutWatch::class,
                SeatAvailability::class,
                CustomerConcertSeats::class,
                SeatHeld::class,
                HoldReleased::class,
                HoldExpired::class,
                SeatSold::class,
            ],
            containerOrAvailableServices: [
                new EcotoneConfiguration(),
                new Catalogue($doctrine->getConnection()),
                new ConcertAnnouncements(),
                new SeatSales(),
                new HoldExpiry(),
                new ConcertSeatMap($doctrine->getConnection()),
                new ConcertSalesLedger($doctrine->getConnection()),
                new SoldOutWatch(),
                'doctrine' => $doctrine,
            ],
            configurationVariables: ['ticketingHoldSeconds' => 600],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
                SimpleMessageChannelBuilder::createQueueChannel('storefront'),
                SimpleMessageChannelBuilder::createQueueChannel('ticketing_async'),
                SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
                SymfonyConnectionReference::defaultManagerRegistry('default'),
            ],
            pathToRootCatalog: dirname(__DIR__, 2),
            addInMemoryStateStoredRepository: false,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable(self::NOW)),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function concertOnSale(FlowTestSupport $ticketing, int $rowsPerSection = 3, int $seatsPerRow = 12): string
    {
        $venueId = $ticketing->getCommandBus()->send(new CreateVenue(name: 'Riverside Hall', sections: ['Stalls', 'Balcony'], rowsPerSection: $rowsPerSection, seatsPerRow: $seatsPerRow, vipRows: ['A', 'B']));
        $concertId = $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $venueId,
            artist: 'The Midnight Owls',
            startsAt: new DateTimeImmutable('2026-11-20 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));
        $ticketing->sendCommand(new OpenSales($concertId));

        return $concertId;
    }

    private function hold(FlowTestSupport $ticketing, string $orderId, string $concertId, string $seatCode, string $customerId): void
    {
        $ticketing->sendCommand(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId), metadata: ['concertId' => $concertId]);
    }

    private function confirm(FlowTestSupport $ticketing, string $orderId, string $concertId, string $customerId, array $seatCodes): void
    {
        $ticketing->sendCommand(
            new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: array_map(static fn (string $seatCode): string => $concertId . '/' . $seatCode, $seatCodes), paymentReference: 'pay-' . $orderId),
            metadata: ['concertId' => $concertId],
        );
    }

    private function statusCounts(FlowTestSupport $ticketing, string $concertId): array
    {
        $counts = array_count_values(array_column($ticketing->sendQueryWithRouting('concert.seat_map', $concertId), 'status'));
        ksort($counts);

        return $counts;
    }

    private function statusOf(FlowTestSupport $ticketing, string $concertId): string
    {
        return $ticketing->sendQueryWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId])['status'];
    }
}
