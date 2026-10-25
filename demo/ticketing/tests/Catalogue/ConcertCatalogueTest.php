<?php

declare(strict_types=1);

namespace App\Tests\Catalogue;

use App\Catalogue\CancelConcert;
use App\Catalogue\Catalogue;
use App\Catalogue\CatalogueRefusal;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\DemoSeed;
use App\Catalogue\MarkConcertSoldOut;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Sales\ConcertSalesLedger;
use App\Sales\CustomerConcertSeats;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSold;
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
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
final class ConcertCatalogueTest extends KernelTestCase
{
    private const NOW = '2026-10-25 12:00:00';

    private ?array $channels = null;

    private object $storefrontListener;

    public function test_creating_a_venue_lists_it_with_its_seat_count(): void
    {
        $ticketing = $this->ticketing();

        $venueId = $this->createRiversideHall($ticketing);

        self::assertSame(
            [['venueId' => $venueId, 'name' => 'Riverside Hall', 'seatCount' => 72]],
            $ticketing->sendQueryWithRouting('venues.list'),
        );
    }

    public function test_a_venue_with_a_vip_row_outside_its_layout_is_refused(): void
    {
        $ticketing = $this->ticketing();

        $this->expectException(CatalogueRefusal::class);
        $this->expectExceptionMessage('Venue "Riverside Hall" names VIP row "Z", but its rows are A, B, C, D, E, F. Name only rows of the layout as VIP rows.');

        $ticketing->getCommandBus()->send(new CreateVenue(name: 'Riverside Hall', sections: ['Stalls', 'Balcony'], rowsPerSection: 3, seatsPerRow: 12, vipRows: ['Z']));
    }

    public function test_scheduling_a_concert_lists_it_as_scheduled_with_its_venue_name(): void
    {
        $ticketing = $this->ticketing();
        $venueId = $this->createRiversideHall($ticketing);

        $concertId = $this->scheduleConcert($ticketing, $venueId);

        self::assertSame(
            [[
                'concertId' => $concertId,
                'artist' => 'The Midnight Owls',
                'venueName' => 'Riverside Hall',
                'startsAt' => '2026-11-20T20:00:00+00:00',
                'status' => 'scheduled',
                'standardPriceCents' => 4500,
                'vipPriceCents' => 9500,
                'currency' => 'EUR',
            ]],
            $ticketing->sendQueryWithRouting('concerts.list'),
        );
    }

    public function test_the_concert_detail_lists_every_seat_of_the_venue_with_its_tier(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));

        $detail = $ticketing->sendQueryWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId]);

        self::assertSame('The Midnight Owls', $detail['artist']);
        self::assertSame('Riverside Hall', $detail['venueName']);
        self::assertCount(72, $detail['seats']);
        self::assertSame(['seatId' => $concertId . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'], $detail['seats'][0]);
        self::assertSame(['seatId' => $concertId . '/B-12', 'seatCode' => 'B-12', 'tier' => 'vip'], $detail['seats'][23]);
        self::assertSame(['seatId' => $concertId . '/C-1', 'seatCode' => 'C-1', 'tier' => 'standard'], $detail['seats'][24]);
        self::assertSame(['seatId' => $concertId . '/F-12', 'seatCode' => 'F-12', 'tier' => 'standard'], $detail['seats'][71]);
    }

    public function test_scheduling_a_concert_in_the_past_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $venueId = $this->createRiversideHall($ticketing);

        $this->expectException(CatalogueRefusal::class);
        $this->expectExceptionMessage('The concert of The Midnight Owls cannot be scheduled for 2026-10-24T20:00:00+00:00, because that is in the past: it is now 2026-10-25T12:00:00+00:00. Pick a start time after now.');

        $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $venueId,
            artist: 'The Midnight Owls',
            startsAt: new DateTimeImmutable('2026-10-24 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));
    }

    public function test_opening_sales_puts_the_concert_on_sale(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));

        $ticketing->sendCommand(new OpenSales($concertId));

        self::assertSame('on_sale', $this->statusOf($ticketing, $concertId));
    }

    public function test_opening_sales_twice_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->sendCommand(new OpenSales($concertId));

        $this->expectException(CatalogueRefusal::class);
        $this->expectExceptionMessage("Sales of the concert of The Midnight Owls ({$concertId}) cannot open, because they are already open. A concert opens its sales once; there is nothing left to do.");

        $ticketing->sendCommand(new OpenSales($concertId));
    }

    public function test_changing_a_tier_price_shows_the_new_price_in_the_concert_list(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));

        $ticketing->sendCommand(new ChangeTierPrice(concertId: $concertId, tier: Tier::VIP, priceCents: 12000));

        $concert = $ticketing->sendQueryWithRouting('concerts.list')[0];
        self::assertSame(4500, $concert['standardPriceCents']);
        self::assertSame(12000, $concert['vipPriceCents']);
    }

    public function test_changing_a_tier_price_of_a_cancelled_concert_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->sendCommand(new CancelConcert($concertId));

        $this->expectException(CatalogueRefusal::class);
        $this->expectExceptionMessage("The standard price of the concert of The Midnight Owls ({$concertId}) cannot change, because the concert is cancelled. Prices change only while a concert is scheduled or on sale.");

        $ticketing->sendCommand(new ChangeTierPrice(concertId: $concertId, tier: Tier::STANDARD, priceCents: 5000));
    }

    public function test_cancelling_a_concert_marks_it_cancelled(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->sendCommand(new OpenSales($concertId));

        $ticketing->sendCommand(new CancelConcert($concertId));

        self::assertSame('cancelled', $this->statusOf($ticketing, $concertId));
    }

    public function test_marking_a_concert_on_sale_sold_out_marks_it_sold_out(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->sendCommand(new OpenSales($concertId));

        $ticketing->sendCommand(new MarkConcertSoldOut($concertId));

        self::assertSame('sold_out', $this->statusOf($ticketing, $concertId));
    }

    public function test_cancelling_a_sold_out_concert_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->sendCommand(new OpenSales($concertId));
        $ticketing->sendCommand(new MarkConcertSoldOut($concertId));

        $this->expectException(CatalogueRefusal::class);
        $this->expectExceptionMessage("The concert of The Midnight Owls ({$concertId}) cannot be cancelled, because it is sold out and every seat has a ticket holder. Only a scheduled or on-sale concert can be cancelled.");

        $ticketing->sendCommand(new CancelConcert($concertId));
    }

    public function test_scheduling_a_concert_announces_it_to_the_storefront_after_the_asynchronous_hop(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));

        self::assertSame([], $this->receivedByStorefront($storefront));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $received = $this->receivedByStorefront($storefront);
        self::assertCount(1, $received);
        self::assertSame('concert.scheduled', $received[0]['routingKey']);
        self::assertSame($concertId, $received[0]['concertIdHeader']);
        $event = $received[0]['event'];
        self::assertSame(['concertId', 'venueName', 'artist', 'startsAt', 'currency', 'tiers', 'seats'], array_keys($event));
        self::assertSame($concertId, $event['concertId']);
        self::assertSame('Riverside Hall', $event['venueName']);
        self::assertSame('The Midnight Owls', $event['artist']);
        self::assertSame('2026-11-20T20:00:00+00:00', $event['startsAt']);
        self::assertSame('EUR', $event['currency']);
        self::assertSame(['standard' => 4500, 'vip' => 9500], $event['tiers']);
        self::assertCount(72, $event['seats']);
        self::assertSame(['seatId' => $concertId . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'], $event['seats'][0]);
        self::assertSame(['seatId' => $concertId . '/F-12', 'seatCode' => 'F-12', 'tier' => 'standard'], $event['seats'][71]);
    }

    public function test_opening_sales_announces_it_to_the_storefront_after_the_asynchronous_hop(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->sendCommand(new OpenSales($concertId));

        self::assertSame([], $this->receivedByStorefront($storefront));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame(
            [['routingKey' => 'concert.sales_opened', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_cancelling_a_concert_announces_it_to_the_storefront_after_the_asynchronous_hop(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->sendCommand(new CancelConcert($concertId));

        self::assertSame([], $this->receivedByStorefront($storefront));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame(
            [['routingKey' => 'concert.cancelled', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_seeding_the_demo_creates_riverside_hall_with_two_concerts_on_sale_and_one_scheduled(): void
    {
        $ticketing = $this->ticketing();

        $ticketing->runConsoleCommand('demo:seed', []);

        self::assertSame(['Riverside Hall'], array_column($ticketing->sendQueryWithRouting('venues.list'), 'name'));
        self::assertSame([72], array_column($ticketing->sendQueryWithRouting('venues.list'), 'seatCount'));
        self::assertSame(
            [
                ['artist' => 'Neon Harbour', 'startsAt' => '2026-11-08T20:00:00+00:00', 'status' => 'on_sale'],
                ['artist' => 'The Midnight Owls', 'startsAt' => '2026-11-25T20:00:00+00:00', 'status' => 'on_sale'],
                ['artist' => 'Aurora Strings', 'startsAt' => '2027-01-25T20:00:00+00:00', 'status' => 'scheduled'],
            ],
            array_map(
                static fn (array $concert): array => ['artist' => $concert['artist'], 'startsAt' => $concert['startsAt'], 'status' => $concert['status']],
                $ticketing->sendQueryWithRouting('concerts.list'),
            ),
        );
    }

    public function test_seeding_the_demo_again_leaves_the_catalogue_as_it_is(): void
    {
        $ticketing = $this->ticketing();
        $ticketing->runConsoleCommand('demo:seed', []);
        $venues = $ticketing->sendQueryWithRouting('venues.list');
        $concerts = $ticketing->sendQueryWithRouting('concerts.list');

        $ticketing->runConsoleCommand('demo:seed', []);

        self::assertSame($venues, $ticketing->sendQueryWithRouting('venues.list'));
        self::assertSame($concerts, $ticketing->sendQueryWithRouting('concerts.list'));
    }

    public function test_seeding_the_demo_again_opens_the_sales_a_previous_seed_left_unopened(): void
    {
        $ticketing = $this->ticketing();
        $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $this->createRiversideHall($ticketing),
            artist: 'Neon Harbour',
            startsAt: new DateTimeImmutable('2026-11-08 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));

        $ticketing->runConsoleCommand('demo:seed', []);

        self::assertSame(
            ['Neon Harbour' => 'on_sale', 'The Midnight Owls' => 'on_sale', 'Aurora Strings' => 'scheduled'],
            array_column($ticketing->sendQueryWithRouting('concerts.list'), 'status', 'artist'),
        );
    }

    private function ticketing(): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, concert_sales_ledger');

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                EcotoneConfiguration::class,
                Venue::class,
                Concert::class,
                Catalogue::class,
                ConcertAnnouncements::class,
                DemoSeed::class,
                SeatSales::class,
                HoldExpiry::class,
                ConcertSalesLedger::class,
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
                new DemoSeed(),
                new SeatSales(),
                new HoldExpiry(),
                new ConcertSalesLedger($doctrine->getConnection()),
                'doctrine' => $doctrine,
            ],
            configurationVariables: ['ticketingHoldSeconds' => 600],
            extensionObjects: [...$this->channels(), SymfonyConnectionReference::defaultManagerRegistry('default')],
            pathToRootCatalog: dirname(__DIR__, 2),
            addInMemoryStateStoredRepository: false,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable(self::NOW)),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function storefrontListening(): FlowTestSupport
    {
        $storefront = new class () {
            public array $received = [];

            #[Distributed]
            #[EventHandler('concert.scheduled')]
            public function scheduled(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'concert.scheduled', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('concert.sales_opened')]
            public function salesOpened(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'concert.sales_opened', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('concert.cancelled')]
            public function cancelled(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'concert.cancelled', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }
        };

        $service = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$storefront::class],
            containerOrAvailableServices: [$storefront],
            extensionObjects: [
                ...$this->channels(),
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

    private function channels(): array
    {
        return $this->channels ??= [
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ticketing_async'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
    }

    private function createRiversideHall(FlowTestSupport $ticketing): string
    {
        return $ticketing->getCommandBus()->send(new CreateVenue(name: 'Riverside Hall', sections: ['Stalls', 'Balcony'], rowsPerSection: 3, seatsPerRow: 12, vipRows: ['A', 'B']));
    }

    private function scheduleConcert(FlowTestSupport $ticketing, string $venueId): string
    {
        return $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $venueId,
            artist: 'The Midnight Owls',
            startsAt: new DateTimeImmutable('2026-11-20 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));
    }

    private function statusOf(FlowTestSupport $ticketing, string $concertId): string
    {
        return $ticketing->sendQueryWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId])['status'];
    }
}
