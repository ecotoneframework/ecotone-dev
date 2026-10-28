<?php

declare(strict_types=1);

namespace App\Tests\Catalogue;

use App\Catalogue\Catalogue;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\MarkConcertSoldOut;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Sales\CustomerConcertSeats;
use App\Sales\ExpireHold;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\HoldSeat;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSold;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @internal
 */
final class ConcertAnnouncementOutboxTest extends KernelTestCase
{
    private ?array $inMemoryChannels = null;

    private object $storefrontListener;

    public function test_a_scheduled_concert_waits_on_the_messenger_transport_and_reaches_the_storefront_with_its_seats_after_the_consumer_runs(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();

        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));

        self::assertSame(1, $this->announcementsWaitingOnTheTransport());
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(0, $this->announcementsWaitingOnTheTransport());
        $received = $this->receivedByStorefront($storefront);
        self::assertCount(1, $received);
        self::assertSame('concert.scheduled', $received[0]['routingKey']);
        self::assertSame($concertId, $received[0]['concertIdHeader']);
        $event = $received[0]['event'];
        self::assertSame(['concertId', 'version', 'venueName', 'artist', 'startsAt', 'currency', 'tiers', 'seats'], array_keys($event));
        self::assertSame(1, $event['version']);
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

    public function test_opened_sales_wait_on_the_messenger_transport_and_reach_the_storefront_after_the_consumer_runs(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new OpenSales($concertId));

        self::assertSame(1, $this->announcementsWaitingOnTheTransport());
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(0, $this->announcementsWaitingOnTheTransport());
        self::assertSame(
            [['routingKey' => 'concert.sales_opened', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_changed_tier_price_waits_on_the_messenger_transport_and_reaches_the_storefront_with_both_tier_prices(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ChangeTierPrice(concertId: $concertId, tier: Tier::VIP, priceCents: 12000));

        self::assertSame(1, $this->announcementsWaitingOnTheTransport());
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(0, $this->announcementsWaitingOnTheTransport());
        self::assertSame(
            [['routingKey' => 'concert.prices_changed', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId, 'version' => 2, 'tiers' => ['standard' => 4500, 'vip' => 12000]]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_each_price_change_announces_the_version_of_the_concert_it_produced(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->getCommandBus()->send(new OpenSales($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new ChangeTierPrice(concertId: $concertId, tier: Tier::VIP, priceCents: 12000));
        $ticketing->getCommandBus()->send(new ChangeTierPrice(concertId: $concertId, tier: Tier::STANDARD, priceCents: 5000));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[3, ['standard' => 4500, 'vip' => 12000]], [4, ['standard' => 5000, 'vip' => 12000]]],
            array_map(static fn (array $received): array => [$received['event']['version'], $received['event']['tiers']], $this->receivedByStorefront($storefront)),
        );
    }

    public function test_a_sold_out_concert_waits_on_the_messenger_transport_and_reaches_the_storefront_after_the_consumer_runs(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->getCommandBus()->send(new OpenSales($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);

        $ticketing->getCommandBus()->send(new MarkConcertSoldOut($concertId));

        self::assertSame(1, $this->announcementsWaitingOnTheTransport());
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['routingKey' => 'concert.sold_out', 'concertIdHeader' => $concertId, 'event' => ['concertId' => $concertId]]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_an_expired_hold_waits_on_the_messenger_transport_and_reaches_the_storefront_as_tickets_hold_expired(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->getCommandBus()->send(new OpenSales($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $ticketing->getCommandBus()->send(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1'), metadata: ['concertId' => $concertId]);

        $ticketing->getCommandBus()->send(new ExpireHold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1', expiresAt: new DateTimeImmutable('2026-10-25 12:10:00')), metadata: ['concertId' => $concertId]);

        self::assertSame(1, $this->announcementsWaitingOnTheTransport());
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'routingKey' => 'tickets.hold_expired',
                'concertIdHeader' => $concertId,
                'event' => ['orderId' => $orderId, 'concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'customerId' => $customerId, 'holdId' => 'hold-1'],
            ]],
            $this->receivedByStorefront($storefront),
        );
    }

    public function test_a_command_failing_after_its_announcement_was_queued_rolls_back_the_announcement_with_the_change(): void
    {
        $failingAfterTheAnnouncement = new class () {
            public bool $fail = false;

            public ?int $announcementsWaitingWhenFailing = null;

            public ?MessageCountAwareInterface $transport = null;

            #[Around(precedence: Precedence::COLLECTOR_SENDER_PRECEDENCE - 1, pointcut: CommandBus::class)]
            public function failAfterTheCommand(MethodInvocation $invocation): mixed
            {
                $result = $invocation->proceed();
                if ($this->fail) {
                    $this->announcementsWaitingWhenFailing = $this->transport->getMessageCount();

                    throw new RuntimeException('The application failed after the sales opened.');
                }

                return $result;
            }
        };
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing($failingAfterTheAnnouncement);
        $failingAfterTheAnnouncement->transport = $this->transport();
        $concertId = $this->scheduleConcert($ticketing, $this->createRiversideHall($ticketing));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->receivedByStorefront($storefront);
        $failingAfterTheAnnouncement->fail = true;

        try {
            $ticketing->getCommandBus()->send(new OpenSales($concertId));
            self::fail('Opening the sales was expected to fail.');
        } catch (Throwable $failure) {
            self::assertStringContainsString('The application failed after the sales opened.', $failure->getMessage());
        }

        self::assertSame(1, $failingAfterTheAnnouncement->announcementsWaitingWhenFailing);
        self::assertSame(0, $this->announcementsWaitingOnTheTransport());
        self::assertSame('scheduled', $ticketing->sendQueryWithRouting('concerts.list')[0]['status']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame([], $this->receivedByStorefront($storefront));
    }

    private function ticketing(object ...$applicationHandlers): FlowTestSupport
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
                SeatSales::class,
                HoldExpiry::class,
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
                ...$applicationHandlers,
                'doctrine' => $doctrine,
                'messenger.transport.ticketing_async' => $this->transport(),
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
            #[EventHandler('concert.prices_changed')]
            public function pricesChanged(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'concert.prices_changed', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
            }

            #[Distributed]
            #[EventHandler('concert.sold_out')]
            public function soldOut(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'concert.sold_out', 'concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
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

    private function transport(): MessageCountAwareInterface
    {
        return self::getContainer()->get('messenger.transport.ticketing_async');
    }

    private function announcementsWaitingOnTheTransport(): int
    {
        return $this->transport()->getMessageCount();
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
}
