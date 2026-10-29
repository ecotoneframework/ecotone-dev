<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\Catalogue;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\ConcertSoldOut;
use App\Catalogue\CreateVenue;
use App\Catalogue\DemoSeed;
use App\Catalogue\MarkConcertSoldOut;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Kernel;
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
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
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

    private ?Kernel $competitorKernel = null;

    protected function tearDown(): void
    {
        $this->competitorKernel?->shutdown();
        $this->competitorKernel = null;
        parent::tearDown();
    }

    public function test_the_seat_map_shows_every_seat_free_until_the_asynchronous_projection_catches_up_with_holds_and_sales(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $holdingOrder = Uuid::v7()->toRfc4122();
        $buyingOrder = Uuid::v7()->toRfc4122();
        $buyer = Uuid::v7()->toRfc4122();
        $this->hold(ticketing: $ticketing, orderId: $holdingOrder, concertId: $concertId, seatCode: 'A-1', customerId: Uuid::v7()->toRfc4122());
        $this->hold(ticketing: $ticketing, orderId: $buyingOrder, concertId: $concertId, seatCode: 'C-2', customerId: $buyer);
        $this->confirm(ticketing: $ticketing, orderId: $buyingOrder, concertId: $concertId, customerId: $buyer, seatCodes: ['C-2']);

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
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-1', customerId: $customerId);
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-2', customerId: $customerId);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1'), metadata: ['concertId' => $concertId]);
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
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-1', customerId: $customerId);
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'C-1', customerId: $customerId);
        $this->confirm(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatCodes: ['A-1', 'C-1']);
        $ticketing->sendCommand(new ChangeTierPrice(concertId: $concertId, tier: Tier::STANDARD, priceCents: 5000));
        $secondOrder = Uuid::v7()->toRfc4122();
        $secondCustomer = Uuid::v7()->toRfc4122();
        $this->hold(ticketing: $ticketing, orderId: $secondOrder, concertId: $concertId, seatCode: 'C-2', customerId: $secondCustomer);
        $this->confirm(ticketing: $ticketing, orderId: $secondOrder, concertId: $concertId, customerId: $secondCustomer, seatCodes: ['C-2']);

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
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-1', customerId: $customerId);
        $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-2', customerId: $customerId);
        $this->confirm(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatCodes: ['A-2']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $seatMap = $ticketing->sendQueryWithRouting('concert.seat_map', $concertId);
        $sales = $ticketing->sendQueryWithRouting('concert.sales', $concertId);

        $ticketing->resetProjection('concert_seat_map');
        $ticketing->resetProjection('concert_sales_ledger');

        self::assertSame($seatMap, $ticketing->sendQueryWithRouting('concert.seat_map', $concertId));
        self::assertSame($sales, $ticketing->sendQueryWithRouting('concert.sales', $concertId));
    }

    public function test_rebuilding_resets_and_replays_each_concert_on_its_own_and_keeps_every_other_concerts_rows(): void
    {
        $ticketing = $this->ticketing();
        $firstConcert = $this->concertOnSale($ticketing);
        $secondConcert = $this->concertOnSale($ticketing);
        foreach ([$firstConcert, $secondConcert] as $concertId) {
            $orderId = Uuid::v7()->toRfc4122();
            $customerId = Uuid::v7()->toRfc4122();
            $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'A-1', customerId: $customerId);
            $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: 'C-1', customerId: $customerId);
            $this->confirm(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatCodes: ['C-1']);
        }
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $seatMaps = [$firstConcert => $ticketing->sendQueryWithRouting('concert.seat_map', $firstConcert), $secondConcert => $ticketing->sendQueryWithRouting('concert.seat_map', $secondConcert)];
        $sales = [$firstConcert => $ticketing->sendQueryWithRouting('concert.sales', $firstConcert), $secondConcert => $ticketing->sendQueryWithRouting('concert.sales', $secondConcert)];

        $ticketing->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'concert_seat_map']);
        $ticketing->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'concert_sales_ledger']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame($seatMaps, [$firstConcert => $ticketing->sendQueryWithRouting('concert.seat_map', $firstConcert), $secondConcert => $ticketing->sendQueryWithRouting('concert.seat_map', $secondConcert)]);
        self::assertSame($sales, [$firstConcert => $ticketing->sendQueryWithRouting('concert.sales', $firstConcert), $secondConcert => $ticketing->sendQueryWithRouting('concert.sales', $secondConcert)]);
        self::assertSame(['free' => 70, 'held' => 1, 'sold' => 1], $this->statusCounts($ticketing, $firstConcert));
        self::assertSame(['free' => 70, 'held' => 1, 'sold' => 1], $this->statusCounts($ticketing, $secondConcert));
    }

    public function test_selling_the_last_seat_of_a_concert_marks_it_sold_out_and_records_concert_sold_out(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, rowsPerSection: 1, seatsPerRow: 2);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2', 'B-1'] as $seatCode) {
            $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: $seatCode, customerId: $customerId);
        }
        $this->confirm(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatCodes: ['A-1', 'A-2', 'B-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('on_sale', $this->statusOf($ticketing, $concertId));

        $lastOrder = Uuid::v7()->toRfc4122();
        $lastCustomer = Uuid::v7()->toRfc4122();
        $this->hold(ticketing: $ticketing, orderId: $lastOrder, concertId: $concertId, seatCode: 'B-2', customerId: $lastCustomer);
        $this->confirm(ticketing: $ticketing, orderId: $lastOrder, concertId: $concertId, customerId: $lastCustomer, seatCodes: ['B-2']);
        $ticketing->discardRecordedMessages();
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('sold_out', $this->statusOf($ticketing, $concertId));
        self::assertEquals([new ConcertSoldOut($concertId)], $ticketing->popRecordedEventsOfType(ConcertSoldOut::class));
    }

    public function test_the_worker_losing_the_race_to_mark_the_concert_sold_out_leaves_it_sold_out_once_with_nothing_dead_lettered(): void
    {
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: Concert::class . '::markSoldOut')]
            public function markItSoldOutOnTheOtherWorkerMeanwhile(MethodInvocation $invocation): mixed
            {
                if ($this->competitor !== null) {
                    $competitor = $this->competitor;
                    $this->competitor = null;
                    $competitor->sendCommand(new MarkConcertSoldOut($this->concertId), metadata: ['concertId' => $this->concertId]);
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $competitor = $this->competitor();
        $ticketing->getGateway(DeadLetterGateway::class)->deleteAll();
        $concertId = $this->concertOnSale($ticketing, rowsPerSection: 1, seatsPerRow: 2);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2', 'B-1', 'B-2'] as $seatCode) {
            $this->hold(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, seatCode: $seatCode, customerId: $customerId);
        }
        $this->confirm(ticketing: $ticketing, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatCodes: ['A-1', 'A-2', 'B-1', 'B-2']);
        $ticketing->discardRecordedMessages();
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame('sold_out', $this->statusOf($ticketing, $concertId));
        self::assertSame([], $ticketing->popRecordedEventsOfType(ConcertSoldOut::class));
        self::assertEquals([new ConcertSoldOut($concertId)], $competitor->popRecordedEventsOfType(ConcertSoldOut::class));
        self::assertSame(0, $ticketing->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_seeding_the_demo_sells_all_but_the_last_three_seats_of_neon_harbour(): void
    {
        $ticketing = $this->ticketing();

        $ticketing->runConsoleCommand('demo:seed', []);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $concertId = $this->concertIdOf($ticketing, 'Neon Harbour');
        $sales = $ticketing->sendQueryWithRouting('concert.sales', $concertId);
        self::assertSame(69, $sales['seatsSold']);
        self::assertSame(['standard' => ['seatsSold' => 45, 'revenueCents' => 202500], 'vip' => ['seatsSold' => 24, 'revenueCents' => 228000]], $sales['tiers']);
        self::assertSame(
            ['F-10', 'F-11', 'F-12'],
            array_column(array_filter($ticketing->sendQueryWithRouting('concert.seat_map', $concertId), static fn (array $seat): bool => $seat['status'] === 'free'), 'seatCode'),
        );
        self::assertSame('on_sale', $this->statusOf($ticketing, $concertId));
        self::assertSame(0, $ticketing->sendQueryWithRouting('concert.sales', $this->concertIdOf($ticketing, 'The Midnight Owls'))['seatsSold']);
    }

    public function test_seeding_only_the_demo_catalogue_schedules_the_concerts_and_sells_no_seat(): void
    {
        $ticketing = $this->ticketing();

        $ticketing->runConsoleCommand('demo:seed', ['catalogueOnly' => true]);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatHeld::class));
        self::assertSame(0, $ticketing->sendQueryWithRouting('concert.sales', $this->concertIdOf($ticketing, 'Neon Harbour'))['seatsSold']);
        self::assertSame('on_sale', $this->statusOf($ticketing, $this->concertIdOf($ticketing, 'Neon Harbour')));
        self::assertSame('on_sale', $this->statusOf($ticketing, $this->concertIdOf($ticketing, 'The Midnight Owls')));
        self::assertSame('scheduled', $this->statusOf($ticketing, $this->concertIdOf($ticketing, 'Aurora Strings')));
    }

    public function test_seeding_the_demo_again_sells_no_further_seat(): void
    {
        $ticketing = $this->ticketing();
        $ticketing->runConsoleCommand('demo:seed', []);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketing->discardRecordedMessages();

        $ticketing->runConsoleCommand('demo:seed', []);

        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatHeld::class));
        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatSold::class));
    }

    public function test_seeding_the_demo_again_before_the_ledger_caught_up_sells_no_further_seat(): void
    {
        $ticketing = $this->ticketing();
        $ticketing->runConsoleCommand('demo:seed', []);
        $ticketing->discardRecordedMessages();

        $ticketing->runConsoleCommand('demo:seed', []);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatSold::class));
        self::assertSame(69, $ticketing->sendQueryWithRouting('concert.sales', $this->concertIdOf($ticketing, 'Neon Harbour'))['seatsSold']);
    }

    private function ticketing(object ...$applicationHandlers): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, concert_seat_map, concert_sales_ledger');

        return $this->bootstrapTicketing($doctrine, ...$applicationHandlers);
    }

    private function competitor(): FlowTestSupport
    {
        $this->competitorKernel = new Kernel('test', true);
        $this->competitorKernel->boot();

        return $this->bootstrapTicketing($this->competitorKernel->getContainer()->get('doctrine'));
    }

    private function bootstrapTicketing(ManagerRegistry $doctrine, object ...$applicationHandlers): FlowTestSupport
    {
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
                DemoSeed::class,
                SeatAvailability::class,
                CustomerConcertSeats::class,
                SeatHeld::class,
                HoldReleased::class,
                HoldExpired::class,
                SeatSold::class,
                ...array_map(static fn (object $handler): string => $handler::class, $applicationHandlers),
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
                new DemoSeed(),
                ...$applicationHandlers,
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
        $ticketing->sendCommand(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId, holdId: 'hold-1'), metadata: ['concertId' => $concertId]);
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

    private function concertIdOf(FlowTestSupport $ticketing, string $artist): string
    {
        return array_column($ticketing->sendQueryWithRouting('concerts.list'), 'concertId', 'artist')[$artist];
    }

    private function statusOf(FlowTestSupport $ticketing, string $concertId): string
    {
        return $ticketing->sendQueryWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId])['status'];
    }
}
