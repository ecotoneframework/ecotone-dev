<?php

declare(strict_types=1);

namespace App\Tests\Issuance;

use App\Catalogue\Catalogue;
use App\Catalogue\Concert;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Issuance\IssuedTicketRegistry;
use App\Issuance\IssueTickets;
use App\Issuance\TicketIssuance;
use App\Issuance\TicketIssuanceProcess;
use App\Issuance\TicketIssuanceStart;
use App\Sales\ConfirmSeats;
use App\Sales\CustomerConcertSeats;
use App\Sales\HoldExpired;
use App\Sales\HoldReleased;
use App\Sales\HoldSeat;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSold;
use App\Sales\SeatsSoldToOrder;
use App\Sales\SoldSeat;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class TicketIssuanceTest extends KernelTestCase
{
    private ?array $inMemoryChannels = null;

    private object $storefrontListener;

    private string $ticketFilesDirectory;

    protected function setUp(): void
    {
        $this->ticketFilesDirectory = sys_get_temp_dir() . '/ticketing-tickets-' . Uuid::v7()->toRfc4122();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ticketFilesDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->ticketFilesDirectory)) {
            rmdir($this->ticketFilesDirectory);
        }
        parent::tearDown();
    }

    public function test_confirmed_seats_reach_the_storefront_as_tickets_issued_with_a_ticket_number_and_entry_code_per_seat(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();

        $this->buy($ticketing, $orderId, $concertId, $customerId, ['C-1', 'C-2']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $received = $this->receivedByStorefront($storefront);
        self::assertCount(1, $received);
        self::assertSame($concertId, $received[0]['concertIdHeader']);
        $event = $received[0]['event'];
        self::assertSame(['orderId', 'concertId', 'customerId', 'tickets'], array_keys($event));
        self::assertSame($orderId, $event['orderId']);
        self::assertSame($concertId, $event['concertId']);
        self::assertSame($customerId, $event['customerId']);
        self::assertSame([$concertId . '/C-1', $concertId . '/C-2'], array_column($event['tickets'], 'seatId'));
        self::assertSame(['standard', 'standard'], array_column($event['tickets'], 'tier'));
        foreach ($event['tickets'] as $ticket) {
            self::assertSame(['ticketNumber', 'seatId', 'tier', 'entryCode'], array_keys($ticket));
            self::assertTrue(Uuid::isValid($ticket['ticketNumber']));
            self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $ticket['entryCode']);
        }
        self::assertNotSame($event['tickets'][0]['ticketNumber'], $event['tickets'][1]['ticketNumber']);
        self::assertNotSame($event['tickets'][0]['entryCode'], $event['tickets'][1]['entryCode']);
    }

    public function test_the_slip_of_an_order_without_vip_seats_is_decided_first_then_allocates_renders_registers_and_announces_carrying_the_concert_id_header(): void
    {
        $recorder = $this->stepRecorder();
        $this->storefrontListening();
        $ticketing = $this->ticketing($recorder);
        $concertId = $this->concertOnSale($ticketing);

        $this->buy($ticketing, Uuid::v7()->toRfc4122(), $concertId, Uuid::v7()->toRfc4122(), ['C-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['slipFor', 'allocateNumbers', 'renderTickets', 'registerEntryCodes', 'announceIssuedTickets'], array_column($recorder->steps, 'step'));
        self::assertSame([$concertId, $concertId, $concertId, $concertId, $concertId], array_column($recorder->steps, 'concertIdHeader'));
    }

    public function test_the_slip_of_an_order_holding_a_vip_seat_grants_vip_lounge_passes_after_allocating_numbers(): void
    {
        $recorder = $this->stepRecorder();
        $this->storefrontListening();
        $ticketing = $this->ticketing($recorder);
        $concertId = $this->concertOnSale($ticketing);

        $this->buy($ticketing, Uuid::v7()->toRfc4122(), $concertId, Uuid::v7()->toRfc4122(), ['A-1', 'C-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['slipFor', 'allocateNumbers', 'grantVipLoungePasses', 'renderTickets', 'registerEntryCodes', 'announceIssuedTickets'], array_column($recorder->steps, 'step'));
    }

    public function test_tickets_are_rendered_as_text_files_on_the_messenger_transport_after_their_numbers_are_allocated(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $this->buy($ticketing, $orderId, $concertId, Uuid::v7()->toRfc4122(), ['A-1', 'C-1']);
        self::assertSame(1, $this->messagesWaitingOnTheTransport());

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(1, $this->messagesWaitingOnTheTransport());
        self::assertSame([], glob($this->ticketFilesDirectory . '/*') ?: []);
        self::assertSame([], $this->receivedByStorefront($storefront));

        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertSame(0, $this->messagesWaitingOnTheTransport());
        $tickets = array_column($this->receivedByStorefront($storefront)[0]['event']['tickets'], null, 'seatId');
        $vipTicket = file_get_contents($this->ticketFilesDirectory . '/' . $tickets[$concertId . '/A-1']['ticketNumber'] . '.txt');
        self::assertStringContainsString('The Midnight Owls', $vipTicket);
        self::assertStringContainsString('Seat: A-1', $vipTicket);
        self::assertStringContainsString('Tier: vip', $vipTicket);
        self::assertStringContainsString('Entry code: ' . $tickets[$concertId . '/A-1']['entryCode'], $vipTicket);
        self::assertStringContainsString('VIP lounge pass: included', $vipTicket);
        $standardTicket = file_get_contents($this->ticketFilesDirectory . '/' . $tickets[$concertId . '/C-1']['ticketNumber'] . '.txt');
        self::assertStringContainsString('Seat: C-1', $standardTicket);
        self::assertStringContainsString('Tier: standard', $standardTicket);
        self::assertStringNotContainsString('VIP lounge pass', $standardTicket);
    }

    public function test_issuing_an_issued_order_again_announces_the_same_tickets_instead_of_a_second_set(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->buy($ticketing, $orderId, $concertId, $customerId, ['A-1', 'C-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $firstAnnouncement = $this->receivedByStorefront($storefront)[0]['event'];

        $ticketing->getGateway(TicketIssuanceProcess::class)->issue(
            new IssueTickets(
                orderId: $orderId,
                concertId: $concertId,
                customerId: $customerId,
                seats: [new SoldSeat(seatId: $concertId . '/A-1', tier: Tier::VIP, priceCents: 9500), new SoldSeat(seatId: $concertId . '/C-1', tier: Tier::STANDARD, priceCents: 4500)],
            ),
            $concertId,
        );
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([$firstAnnouncement], array_column($this->receivedByStorefront($storefront), 'event'));
        self::assertSame(array_column($firstAnnouncement['tickets'], 'ticketNumber'), array_column($ticketing->sendQueryWithRouting('tickets.of_order', $orderId), 'ticketNumber'));
    }

    public function test_the_ticket_detail_query_returns_the_ticket_with_its_seat_tier_entry_code_and_issue_time(): void
    {
        $storefront = $this->storefrontListening();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $this->buy($ticketing, $orderId, $concertId, Uuid::v7()->toRfc4122(), ['A-1']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticket = $this->receivedByStorefront($storefront)[0]['event']['tickets'][0];

        self::assertSame(
            [
                'ticketNumber' => $ticket['ticketNumber'],
                'orderId' => $orderId,
                'concertId' => $concertId,
                'seatId' => $concertId . '/A-1',
                'seatCode' => 'A-1',
                'tier' => 'vip',
                'entryCode' => $ticket['entryCode'],
                'issuedAt' => '2026-10-25T12:00:00+00:00',
            ],
            $ticketing->sendQueryWithRouting('ticket.detail', $ticket['ticketNumber']),
        );
        self::assertNull($ticketing->sendQueryWithRouting('ticket.detail', Uuid::v7()->toRfc4122()));
    }

    public function test_a_render_failing_for_a_seat_dead_letters_the_slip_and_a_replay_after_unsetting_the_variable_finishes_it_exactly_once(): void
    {
        $storefront = $this->storefrontListening();
        $failingTicketing = $this->ticketing(renderFailsForSeat: 'C-1');
        $failingTicketing->getGateway(DeadLetterGateway::class)->deleteAll();
        $concertId = $this->concertOnSale($failingTicketing);
        $orderId = Uuid::v7()->toRfc4122();
        $this->buy($failingTicketing, $orderId, $concertId, Uuid::v7()->toRfc4122(), ['A-1', 'C-1']);

        $failingTicketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame([], $this->receivedByStorefront($storefront));
        self::assertSame([], $failingTicketing->sendQueryWithRouting('tickets.of_order', $orderId));
        $deadLetters = $failingTicketing->getGateway(DeadLetterGateway::class)->list(10, 0);
        self::assertCount(1, $deadLetters);
        self::assertStringContainsString(
            'Ticket of seat C-1 cannot be rendered, because TICKETING_RENDER_FAILS_FOR_SEAT is set to C-1 to simulate a failing render. Unset TICKETING_RENDER_FAILS_FOR_SEAT and recreate the container (docker compose up -d ticketing-worker), because a restart keeps the old environment, then replay this message from the dead letters in Ecotone Pulse or with bin/console ecotone:deadletter:replay ' . $deadLetters[0]->getMessageId() . '.',
            $deadLetters[0]->getMessage(),
        );

        $ticketing = $this->bootstrapTicketing(renderFailsForSeat: '');
        $ticketing->getGateway(DeadLetterGateway::class)->replayAll();
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        $received = $this->receivedByStorefront($storefront);
        self::assertCount(1, $received);
        self::assertSame([$concertId . '/A-1', $concertId . '/C-1'], array_column($received[0]['event']['tickets'], 'seatId'));
        self::assertSame(array_column($received[0]['event']['tickets'], 'ticketNumber'), array_column($ticketing->sendQueryWithRouting('tickets.of_order', $orderId), 'ticketNumber'));
        self::assertSame(0, $ticketing->getGateway(DeadLetterGateway::class)->count());
        self::assertSame(0, $this->messagesWaitingOnTheTransport());
    }

    private function ticketing(?object $recorder = null, string $renderFailsForSeat = ''): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, messenger_messages, issued_tickets');

        return $this->bootstrapTicketing($renderFailsForSeat, $recorder);
    }

    private function bootstrapTicketing(string $renderFailsForSeat, ?object $recorder = null): FlowTestSupport
    {
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $applicationHandlers = $recorder === null ? [] : [$recorder];

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [
                EcotoneConfiguration::class,
                Venue::class,
                Concert::class,
                Catalogue::class,
                SeatSales::class,
                SeatAvailability::class,
                CustomerConcertSeats::class,
                SeatHeld::class,
                HoldReleased::class,
                HoldExpired::class,
                SeatSold::class,
                SeatsSoldToOrder::class,
                TicketIssuanceStart::class,
                TicketIssuanceProcess::class,
                TicketIssuance::class,
                IssuedTicketRegistry::class,
                ...array_map(static fn (object $handler): string => $handler::class, $applicationHandlers),
            ],
            containerOrAvailableServices: [
                new EcotoneConfiguration(),
                new Catalogue($doctrine->getConnection()),
                new SeatSales(),
                new TicketIssuanceStart(),
                new TicketIssuance(new IssuedTicketRegistry($doctrine->getConnection())),
                new IssuedTicketRegistry($doctrine->getConnection()),
                ...$applicationHandlers,
                'doctrine' => $doctrine,
                'messenger.transport.ticketing_async' => $this->transport(),
            ],
            configurationVariables: [
                'ticketingHoldSeconds' => 600,
                'ticketFilesDirectory' => $this->ticketFilesDirectory,
                'ticketingRenderFailsForSeat' => $renderFailsForSeat,
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

    private function stepRecorder(): object
    {
        return new class () {
            public array $steps = [];

            #[Around(pointcut: TicketIssuance::class)]
            public function record(MethodInvocation $invocation, #[Headers] array $headers): mixed
            {
                $this->steps[] = ['step' => $invocation->getMethodName(), 'concertIdHeader' => $headers['concertId'] ?? null];

                return $invocation->proceed();
            }
        };
    }

    private function storefrontListening(): FlowTestSupport
    {
        $storefront = new class () {
            public array $received = [];

            #[Distributed]
            #[EventHandler('tickets.issued')]
            public function ticketsIssued(array $event, #[Headers] array $headers): void
            {
                $this->received[] = ['concertIdHeader' => $headers['concertId'] ?? null, 'event' => $event];
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

    private function messagesWaitingOnTheTransport(): int
    {
        return $this->transport()->getMessageCount();
    }

    private function concertOnSale(FlowTestSupport $ticketing): string
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
        $ticketing->sendCommand(new OpenSales($concertId));

        return $concertId;
    }

    /**
     * @param list<string> $seatCodes
     */
    private function buy(FlowTestSupport $ticketing, string $orderId, string $concertId, string $customerId, array $seatCodes): void
    {
        foreach ($seatCodes as $seatCode) {
            $ticketing->sendCommand(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId), metadata: ['concertId' => $concertId]);
        }
        $ticketing->sendCommand(
            new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: array_map(static fn (string $seatCode): string => $concertId . '/' . $seatCode, $seatCodes), paymentReference: 'pay-1'),
            metadata: ['concertId' => $concertId],
        );
    }
}
