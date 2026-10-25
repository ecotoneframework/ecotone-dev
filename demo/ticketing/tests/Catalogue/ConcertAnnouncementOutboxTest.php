<?php

declare(strict_types=1);

namespace App\Tests\Catalogue;

use App\Catalogue\Catalogue;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
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
use Throwable;

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
            classesToResolve: [EcotoneConfiguration::class, Venue::class, Concert::class, Catalogue::class, ConcertAnnouncements::class, ...array_map(static fn (object $handler): string => $handler::class, $applicationHandlers)],
            containerOrAvailableServices: [
                new EcotoneConfiguration(),
                new Catalogue($doctrine->getConnection()),
                new ConcertAnnouncements(),
                ...$applicationHandlers,
                'doctrine' => $doctrine,
                'messenger.transport.ticketing_async' => $this->transport(),
            ],
            extensionObjects: [
                ...$this->inMemoryChannels(),
                SymfonyMessengerMessageChannelBuilder::create('ticketing_async'),
                SymfonyConnectionReference::defaultManagerRegistry('default'),
            ],
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
