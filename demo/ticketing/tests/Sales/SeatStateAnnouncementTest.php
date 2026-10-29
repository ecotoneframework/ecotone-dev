<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\CancelConcert;
use App\Catalogue\Catalogue;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Sales\CancelledConcertHolds;
use App\Sales\ConfirmSeats;
use App\Sales\ConfirmSeedOrder;
use App\Sales\CustomerConcertSeats;
use App\Sales\ExpireHold;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\HoldSeat;
use App\Sales\ReleaseSeat;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatRefusal;
use App\Sales\SeatSales;
use App\Sales\SeatSold;
use App\Sales\SeatStateAnnouncements;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class SeatStateAnnouncementTest extends KernelTestCase
{
    private ?array $inMemoryChannels = null;

    private object $storefrontListener;

    public function test_a_held_seat_waits_on_the_messenger_transport_and_reaches_the_storefront_as_tickets_seat_held(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();

        $this->hold($ticketing, $orderId, $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-1');

        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['routingKey' => 'tickets.seat_held', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $orderId, 'holdId' => 'hold-1', 'expiresAt' => '2026-10-25T12:10:00+00:00']]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_hold_another_order_takes_over_after_its_expiry_but_before_the_expiry_runs_is_never_announced_as_released(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $otherOrderId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-1');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->advanceTimeBy(Duration::seconds(601));
        $this->hold($ticketing, $otherOrderId, $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-2');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['routingKey' => 'tickets.seat_held', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $otherOrderId, 'holdId' => 'hold-2', 'expiresAt' => '2026-10-25T12:20:01+00:00']]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_released_seat_reaches_the_storefront_as_tickets_seat_released_with_its_hold(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId, 'hold-1');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1'), metadata: ['concertId' => $concertId]);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['routingKey' => 'tickets.seat_released', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $orderId, 'holdId' => 'hold-1']]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_an_expired_hold_reaches_the_storefront_as_tickets_seat_released_beside_tickets_hold_expired(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId, 'hold-1');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ExpireHold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1', expiresAt: new DateTimeImmutable('2026-10-25 12:10:00')), metadata: ['concertId' => $concertId]);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $received = $this->receivedByStorefront($storefront);
        self::assertContains(
            ['routingKey' => 'tickets.seat_released', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $orderId, 'holdId' => 'hold-1']],
            $received,
        );
        self::assertSame(['tickets.hold_expired', 'tickets.seat_released'], $this->sortedRoutingKeys($received));
    }

    public function test_cancelling_a_concert_releases_its_live_holds_and_reaches_the_storefront_as_tickets_seat_released(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-1');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new CancelConcert($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertContains(
            ['routingKey' => 'tickets.seat_released', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $orderId, 'holdId' => 'hold-1']],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_confirmed_order_reaches_the_storefront_as_tickets_seat_sold_for_each_seat(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'A-1', $customerId, 'hold-1');
        $this->hold($ticketing, $orderId, $concertId, 'C-1', $customerId, 'hold-2');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1', $concertId . '/C-1'], paymentReference: 'pay-1'), metadata: ['concertId' => $concertId]);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [
                ['routingKey' => 'tickets.seat_sold', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'orderId' => $orderId]],
                ['routingKey' => 'tickets.seat_sold', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/C-1', 'orderId' => $orderId]],
            ],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_seed_order_reaches_the_storefront_as_tickets_seat_sold_so_seeded_sales_are_known_there(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, 'B-3', $customerId, 'demo-seed-' . $orderId);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ConfirmSeedOrder(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/B-3'], paymentReference: 'demo-seed-' . $orderId), metadata: ['concertId' => $concertId]);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['routingKey' => 'tickets.seat_sold', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'seatId' => $concertId . '/B-3', 'orderId' => $orderId]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_refused_hold_announces_nothing(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing, $storefront);
        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-1');
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        try {
            $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, 'A-1', Uuid::v7()->toRfc4122(), 'hold-2');
        } catch (SeatRefusal) {
        }
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $this->receivedByStorefront($storefront));
    }

    private function hold(FlowTestSupport $ticketing, string $orderId, string $concertId, string $seatCode, string $customerId, string $holdId): void
    {
        $ticketing->getCommandBus()->send(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId, holdId: $holdId), metadata: ['concertId' => $concertId]);
    }

    private function sortedRoutingKeys(array $received): array
    {
        $routingKeys = array_column($received, 'routingKey');
        sort($routingKeys);

        return $routingKeys;
    }

    private function ticketing(): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, messenger_messages');

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                EcotoneConfiguration::class,
                Venue::class,
                Concert::class,
                Catalogue::class,
                ConcertAnnouncements::class,
                SeatStateAnnouncements::class,
                SeatSales::class,
                HoldExpiry::class,
                CancelledConcertHolds::class,
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
                new SeatStateAnnouncements(),
                new SeatSales(),
                new HoldExpiry(),
                new CancelledConcertHolds(),
                'doctrine' => $doctrine,
                'messenger.transport.ticketing_async' => self::getContainer()->get('messenger.transport.ticketing_async'),
            ],
            extensionObjects: [
                ...$this->inMemoryChannels(),
                SymfonyMessengerMessageChannelBuilder::create('ticketing_async'),
                SymfonyConnectionReference::defaultManagerRegistry('default'),
            ],
            configurationVariables: ['ticketingHoldSeconds' => 600],
            pathToRootCatalog: dirname(__DIR__, 2),
            addInMemoryStateStoredRepository: false,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-25 12:00:00')),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function storefrontListening(): FlowTestSupport
    {
        $storefront = new class () {
            public array $received = [];

            #[Distributed]
            #[EventHandler('tickets.seat_held')]
            public function seatHeld(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.seat_held', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('tickets.seat_released')]
            public function seatReleased(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.seat_released', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('tickets.seat_sold')]
            public function seatSold(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.seat_sold', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('tickets.hold_expired')]
            public function holdExpired(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.hold_expired', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }
        };

        $service = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$storefront::class],
            containerOrAvailableServices: [$storefront],
            extensionObjects: [
                ...$this->inMemoryChannels(),
                ServiceConfiguration::createWithDefaults()->withServiceName('storefront'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticketing', channelName: 'ticketing'),
            ],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->storefrontListener = $storefront;

        return $service;
    }

    private function receivedByStorefront(FlowTestSupport $storefront): array
    {
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
        $received = $this->storefrontListener->received;
        $this->storefrontListener->received = [];

        return $received;
    }

    private function inMemoryChannels(): array
    {
        return $this->inMemoryChannels ??= [
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
    }

    private function concertOnSale(FlowTestSupport $ticketing, FlowTestSupport $storefront): string
    {
        $venueId = $ticketing->getCommandBus()->send(new CreateVenue(name: 'Riverside Hall', sections: ['Stalls', 'Balcony'], rowsPerSection: 3, seatsPerRow: 12, vipRows: ['A', 'B']));
        $concertId = $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $venueId,
            artist: 'The Midnight Owls',
            startsAt: new DateTimeImmutable('2026-11-20 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));
        $ticketing->getCommandBus()->send(new OpenSales($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        return $concertId;
    }
}
