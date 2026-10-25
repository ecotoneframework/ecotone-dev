<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\Catalogue;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Kernel;
use App\Sales\CustomerConcertSeats;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSalesOverDistributedBus;
use App\Sales\SeatSold;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class SeatSalesOverDistributedBusTest extends KernelTestCase
{
    private const NOW = '2026-10-25 12:00:00';

    private ?array $channels = null;

    private object $storefrontListener;

    private ?Kernel $competitorKernel = null;

    protected function tearDown(): void
    {
        $this->competitorKernel?->shutdown();
        $this->competitorKernel = null;
        parent::tearDown();
    }

    public function test_a_hold_sent_over_the_distributed_bus_replies_held_with_its_expiry_on_the_reply_routing_key(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();

        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'routingKey' => 'tickets.hold.result',
                'concertIdHeader' => $concertId,
                'reply' => [
                    'orderId' => $orderId,
                    'concertId' => $concertId,
                    'seatId' => $concertId . '/A-1',
                    'customerId' => $customerId,
                    'outcome' => 'held',
                    'reason' => null,
                    'expiresAt' => '2026-10-25T12:10:00+00:00',
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
    }

    public function test_holding_a_seat_the_order_already_holds_replies_held_with_the_original_expiry(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:05:00'));

        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $replies = $this->repliesReceivedBy($storefront);
        self::assertSame('held', $replies[0]['reply']['outcome']);
        self::assertSame('2026-10-25T12:10:00+00:00', $replies[0]['reply']['expiresAt']);
    }

    public function test_a_hold_of_a_seat_another_order_holds_replies_refused_with_the_reason(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $firstOrder = Uuid::v7()->toRfc4122();
        $secondOrder = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold($storefront, $firstOrder, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendHold($storefront, $secondOrder, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'routingKey' => 'tickets.hold.result',
                'concertIdHeader' => $concertId,
                'reply' => [
                    'orderId' => $secondOrder,
                    'concertId' => $concertId,
                    'seatId' => $concertId . '/A-1',
                    'customerId' => $customerId,
                    'outcome' => 'refused',
                    'reason' => "Seat A-1 of the concert of The Midnight Owls cannot be held for order {$secondOrder}, because someone was faster: order {$firstOrder} holds it until 2026-10-25T12:10:00+00:00. Pick another seat, or try this one again after that hold expires.",
                    'expiresAt' => null,
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
    }

    public function test_a_hold_whose_seat_changes_on_every_attempt_replies_refused_because_someone_was_faster(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?Connection $competitorConnection = null;

            public ?EventStore $competitorEventStore = null;

            public ?string $concertId = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function releaseTheSameSeatMeanwhile(MethodInvocation $invocation): mixed
            {
                $this->competitorConnection->transactional(fn () => $this->competitorEventStore->appendTo('ecotone_event_stream', [
                    new HoldReleased(orderId: Uuid::v7()->toRfc4122(), concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: Uuid::v7()->toRfc4122()),
                ]));

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitorConnection = $this->competitorKernel->getContainer()->get('doctrine')->getConnection();
        $interferer->competitorEventStore = $competitor->getGateway(EventStore::class);
        $interferer->concertId = $concertId;
        $orderId = Uuid::v7()->toRfc4122();

        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertSame("Seat {$concertId}/A-1 cannot be held for order {$orderId}, because someone was faster: the seat changed each time ticketing tried to hold it. Pick the seat again, or choose another one.", $reply['reason']);
        self::assertNull($reply['expiresAt']);
    }

    public function test_a_release_sent_over_the_distributed_bus_frees_the_seat_for_another_order(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.release',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'customerId' => $customerId]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
        );
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendHold($storefront, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['held'], array_map(static fn (array $received): string => $received['reply']['outcome'], $this->repliesReceivedBy($storefront)));
    }

    public function test_a_release_of_a_seat_the_order_no_longer_holds_is_a_no_op(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $ticketing->discardRecordedMessages();

        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.release',
            json_encode(['orderId' => Uuid::v7()->toRfc4122(), 'concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'customerId' => Uuid::v7()->toRfc4122()]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
        );
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldReleased::class));
        self::assertSame([], $this->repliesReceivedBy($storefront));
    }

    public function test_a_confirm_sent_over_the_distributed_bus_replies_sold_with_the_seat_ids(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2'] as $seatCode) {
            $this->sendHold($storefront, $orderId, $concertId, $concertId . '/' . $seatCode, $customerId);
        }
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendConfirm($storefront, $orderId, $concertId, $customerId, [$concertId . '/A-1', $concertId . '/A-2']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'routingKey' => 'tickets.confirm.result',
                'concertIdHeader' => $concertId,
                'reply' => [
                    'orderId' => $orderId,
                    'concertId' => $concertId,
                    'customerId' => $customerId,
                    'outcome' => 'sold',
                    'reason' => null,
                    'seatIds' => [$concertId . '/A-1', $concertId . '/A-2'],
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
        self::assertCount(2, $ticketing->popRecordedEventsOfType(SeatSold::class));
    }

    public function test_a_confirm_of_a_seat_the_order_does_not_hold_replies_refused_with_the_reason(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold($storefront, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendConfirm($storefront, $orderId, $concertId, $customerId, [$concertId . '/A-1', $concertId . '/A-2']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'routingKey' => 'tickets.confirm.result',
                'concertIdHeader' => $concertId,
                'reply' => [
                    'orderId' => $orderId,
                    'concertId' => $concertId,
                    'customerId' => $customerId,
                    'outcome' => 'refused',
                    'reason' => "Order {$orderId} cannot buy seat {$concertId}/A-2, because it does not hold it. Hold the seat first, then confirm the order again; nothing of the order was sold.",
                    'seatIds' => [$concertId . '/A-1', $concertId . '/A-2'],
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
    }

    public function test_a_hold_missing_a_field_names_the_missing_field_and_the_contract(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $storefront->getDistributedBus()->sendMessage('ticketing', 'tickets.hold', json_encode(['orderId' => 'order-1', 'seatId' => 'seat-1']), MediaType::APPLICATION_JSON, replyRoutingKey: 'tickets.hold.result');

        $this->expectExceptionMessage('ticketing received tickets.hold without concertId, customerId: the payload carries orderId, seatId. Send tickets.hold as a JSON object of orderId, concertId, seatId, customerId, each a non-empty string.');

        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
    }

    private function ticketing(object ...$applicationHandlers): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues');

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
                SeatSalesOverDistributedBus::class,
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
                new SeatSalesOverDistributedBus(),
                ...$applicationHandlers,
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

    private function storefront(): FlowTestSupport
    {
        $storefront = new class () {
            public array $received = [];

            #[InternalHandler('tickets.hold.result')]
            public function holdResult(array $reply, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.hold.result', 'concertIdHeader' => $headers['concertId'] ?? null, 'reply' => $reply];
            }

            #[InternalHandler('tickets.confirm.result')]
            public function confirmResult(array $reply, #[Headers] array $headers): void
            {
                $this->received[] = ['routingKey' => 'tickets.confirm.result', 'concertIdHeader' => $headers['concertId'] ?? null, 'reply' => $reply];
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

    private function repliesReceivedBy(FlowTestSupport $storefront): array
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

    private function sendHold(FlowTestSupport $storefront, string $orderId, string $concertId, string $seatId, string $customerId): void
    {
        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.hold',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'seatId' => $seatId, 'customerId' => $customerId]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
            replyRoutingKey: 'tickets.hold.result',
        );
    }

    private function sendConfirm(FlowTestSupport $storefront, string $orderId, string $concertId, string $customerId, array $seatIds): void
    {
        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.confirm',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'customerId' => $customerId, 'seatIds' => $seatIds, 'paymentReference' => 'pay-1']),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
            replyRoutingKey: 'tickets.confirm.result',
        );
    }
}
