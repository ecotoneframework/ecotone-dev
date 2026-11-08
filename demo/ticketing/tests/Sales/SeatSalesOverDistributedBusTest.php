<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\Catalogue;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Kernel;
use App\Sales\ConfirmSeats;
use App\Sales\CustomerConcertSeats;
use App\Sales\FindSeatHold;
use App\Sales\HoldExpired;
use App\Sales\HoldExpiry;
use App\Sales\HoldReleased;
use App\Sales\HoldSeat;
use App\Sales\SeatAvailability;
use App\Sales\SeatHeld;
use App\Sales\SeatSales;
use App\Sales\SeatSalesOverDistributedBus;
use App\Sales\SeatSold;
use App\Sales\SeatsSoldToOrder;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
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
                    'holdId' => 'hold-1',
                    'outcome' => 'held',
                    'reason' => null,
                    'reasonCode' => null,
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
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:05:00'));

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
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
        $this->sendHold(storefront: $storefront, orderId: $firstOrder, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendHold(storefront: $storefront, orderId: $secondOrder, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
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
                    'holdId' => 'hold-1',
                    'outcome' => 'refused',
                    'reason' => "Seat A-1 of the concert of The Midnight Owls cannot be held for order {$secondOrder}, because someone was faster: order {$firstOrder} holds it until 2026-10-25T12:10:00+00:00. Pick another seat, or try this one again after that hold expires.",
                    'reasonCode' => 'seat_held',
                    'expiresAt' => null,
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
    }

    public function test_a_hold_racing_holds_of_eleven_other_seats_of_the_same_concert_replies_held_without_a_conflict(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            public int $attempts = 0;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function holdElevenOtherSeatsMeanwhile(MethodInvocation $invocation): mixed
            {
                if (++$this->attempts === 1) {
                    foreach (range(2, 12) as $seatNumber) {
                        $this->competitor->sendCommand(new HoldSeat(orderId: Uuid::v7()->toRfc4122(), concertId: $this->concertId, seatId: $this->concertId . '/A-' . $seatNumber, customerId: Uuid::v7()->toRfc4122(), holdId: 'hold-1'), metadata: ['concertId' => $this->concertId]);
                    }
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;

        $this->sendHold(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('held', $this->repliesReceivedBy($storefront)[0]['reply']['outcome']);
        self::assertSame(1, $interferer->attempts);
    }

    public function test_a_hold_racing_a_tier_price_change_of_its_concert_replies_refused_naming_the_concert_and_a_new_hold_holds_the_seat(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function changeThePriceOnceAfterTheDecision(MethodInvocation $invocation): mixed
            {
                $decision = $invocation->proceed();
                if ($this->competitor !== null) {
                    $competitor = $this->competitor;
                    $this->competitor = null;
                    $competitor->sendCommand(new ChangeTierPrice(concertId: $this->concertId, tier: Tier::VIP, priceCents: 12000));
                }

                return $decision;
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertSame("Seat {$concertId}/A-1 cannot be held for order {$orderId}, because the concert changed while ticketing held the seat: its prices or status were saved after ticketing read them, so the hold could not be kept as decided. Pick the seat again to hold it at the concert's current prices, while the concert is on sale.", $reply['reason']);
        self::assertSame('concert_changed', $reply['reasonCode']);

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-2');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('held', $this->repliesReceivedBy($storefront)[0]['reply']['outcome']);
    }

    public function test_a_confirm_racing_a_tier_price_change_of_its_concert_replies_refused_naming_the_concert_and_sells_nothing(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::confirm')]
            public function changeThePriceOnceAfterTheDecision(MethodInvocation $invocation): mixed
            {
                $decision = $invocation->proceed();
                if ($this->competitor !== null) {
                    $competitor = $this->competitor;
                    $this->competitor = null;
                    $competitor->sendCommand(new ChangeTierPrice(concertId: $this->concertId, tier: Tier::VIP, priceCents: 12000));
                }

                return $decision;
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertSame("Order {$orderId} cannot be confirmed, because the concert changed while ticketing sold its seats: its prices or status were saved after ticketing read them; nothing of the order was sold. Refund payment pay-1; the buyer holds seats again in a new order.", $reply['reason']);
        self::assertSame('concert_changed', $reply['reasonCode']);
        self::assertSame([], $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events);
    }

    public function test_two_holds_racing_for_the_same_seat_reply_one_held_and_one_refused_because_someone_was_faster(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            public ?string $fasterOrder = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function holdTheSameSeatOnceMeanwhile(MethodInvocation $invocation): mixed
            {
                if ($this->fasterOrder === null) {
                    $this->fasterOrder = Uuid::v7()->toRfc4122();
                    $this->competitor->sendCommand(new HoldSeat(orderId: $this->fasterOrder, concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: Uuid::v7()->toRfc4122(), holdId: 'hold-1'), metadata: ['concertId' => $this->concertId]);
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;
        $orderId = Uuid::v7()->toRfc4122();

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertStringContainsString("cannot be held for order {$orderId}, because someone was faster", $reply['reason']);
        self::assertSame('lost_the_race', $reply['reasonCode']);
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
                    new HoldReleased(orderId: Uuid::v7()->toRfc4122(), concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: Uuid::v7()->toRfc4122(), holdId: 'hold-1'),
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

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertSame("Seat {$concertId}/A-1 cannot be held for order {$orderId}, because someone was faster: the seat changed each time ticketing tried to hold it. Pick the seat again, or choose another one.", $reply['reason']);
        self::assertSame('lost_the_race', $reply['reasonCode']);
        self::assertNull($reply['expiresAt']);
    }

    public function test_a_release_sent_over_the_distributed_bus_frees_the_seat_for_another_order(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendRelease(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendHold(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['held'], array_map(static fn (array $received): string => $received['reply']['outcome'], $this->repliesReceivedBy($storefront)));
    }

    public function test_a_release_of_a_seat_the_order_no_longer_holds_is_a_no_op(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $ticketing->discardRecordedMessages();

        $this->sendRelease(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122(), holdId: 'hold-1');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldReleased::class));
        self::assertSame([], $this->repliesReceivedBy($storefront));
    }

    public function test_a_release_sent_over_the_distributed_bus_for_another_customer_than_the_holding_one_leaves_the_seat_held(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->discardRecordedMessages();

        $this->sendRelease(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122(), holdId: 'hold-1');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendHold(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122());
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldReleased::class));
        self::assertSame(['refused'], array_map(static fn (array $received): string => $received['reply']['outcome'], $this->repliesReceivedBy($storefront)));
    }

    public function test_a_hold_of_a_seat_the_order_holds_under_another_hold_id_replies_held_under_the_new_one_and_a_late_release_of_the_old_one_leaves_it_held(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:05:00'));

        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-2');
        $this->sendRelease(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, holdId: 'hold-1');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['holdId' => 'hold-2', 'outcome' => 'held', 'expiresAt' => '2026-10-25T12:15:00+00:00']],
            array_map(static fn (array $received): array => ['holdId' => $received['reply']['holdId'], 'outcome' => $received['reply']['outcome'], 'expiresAt' => $received['reply']['expiresAt']], $this->repliesReceivedBy($storefront)),
        );
        self::assertEquals(['orderId' => $orderId, 'expiresAt' => new DateTimeImmutable('2026-10-25 12:15:00')], $ticketing->sendQuery(new FindSeatHold($concertId . '/A-1')));
    }

    public function test_a_hold_without_a_hold_id_names_the_missing_field_and_the_contract(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);

        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.hold',
            json_encode(['orderId' => 'order-1', 'concertId' => $concertId, 'seatId' => $concertId . '/A-1', 'customerId' => 'customer-1']),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
            replyRoutingKey: 'tickets.hold.result',
        );

        $this->expectExceptionMessage('ticketing received tickets.hold without holdId: the payload carries orderId, concertId, seatId, customerId. Send tickets.hold as a JSON object of orderId, concertId, seatId, customerId, holdId, each a non-empty string.');

        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_confirm_sent_over_the_distributed_bus_replies_sold_with_the_seat_ids(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2'] as $seatCode) {
            $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId);
        }
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1', $concertId . '/A-2']);
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
                    'reasonCode' => null,
                    'seatIds' => [$concertId . '/A-1', $concertId . '/A-2'],
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
        self::assertCount(2, $ticketing->popRecordedEventsOfType(SeatSold::class));
    }

    public function test_each_refused_reply_carries_the_reason_code_of_its_refusal_next_to_the_reason(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $unknownConcertId = Uuid::v7()->toRfc4122();
        $customer = Uuid::v7()->toRfc4122();
        $holdingOrder = Uuid::v7()->toRfc4122();
        $otherCustomer = Uuid::v7()->toRfc4122();
        $otherOrder = Uuid::v7()->toRfc4122();

        $this->sendHold(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $unknownConcertId, seatId: $unknownConcertId . '/A-1', customerId: $customer);
        $this->sendHold(storefront: $storefront, orderId: $holdingOrder, concertId: $concertId, seatId: $concertId . '/Z-99', customerId: $customer);
        foreach (['A-1', 'A-2', 'A-3', 'A-4', 'A-5'] as $seatCode) {
            $this->sendHold(storefront: $storefront, orderId: $holdingOrder, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customer);
        }
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendConfirm(storefront: $storefront, orderId: $holdingOrder, concertId: $concertId, customerId: $customer, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendHold(storefront: $storefront, orderId: $otherOrder, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $otherCustomer);
        $this->sendConfirm(storefront: $storefront, orderId: $otherOrder, concertId: $concertId, customerId: $otherCustomer, seatIds: []);
        $this->sendConfirm(storefront: $storefront, orderId: $otherOrder, concertId: $concertId, customerId: $otherCustomer, seatIds: [$concertId . '/A-6']);
        $this->sendConfirm(storefront: $storefront, orderId: Uuid::v7()->toRfc4122(), concertId: $concertId, customerId: $customer, seatIds: [$concertId . '/A-2']);
        $this->sendConfirm(storefront: $storefront, orderId: $holdingOrder, concertId: $concertId, customerId: $customer, seatIds: [$concertId . '/A-1'], paymentReference: 'pay-2');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [
                ['refused', 'unknown_concert'],
                ['refused', 'unknown_seat'],
                ['held', null],
                ['held', null],
                ['held', null],
                ['held', null],
                ['refused', 'seat_limit_reached'],
                ['sold', null],
                ['refused', 'seat_sold'],
                ['refused', 'no_seats'],
                ['refused', 'seat_not_held'],
                ['refused', 'seat_held'],
                ['refused', 'sold_with_another_payment'],
            ],
            array_map(static fn (array $received): array => [$received['reply']['outcome'], $received['reply']['reasonCode']], $this->repliesReceivedBy($storefront)),
        );
    }

    public function test_a_confirm_of_a_seat_the_order_does_not_hold_replies_refused_with_the_reason(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1', $concertId . '/A-2']);
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
                    'reasonCode' => 'seat_not_held',
                    'seatIds' => [$concertId . '/A-1', $concertId . '/A-2'],
                ],
            ]],
            $this->repliesReceivedBy($storefront),
        );
    }

    public function test_a_repeated_confirmation_of_the_same_paid_order_replies_sold_again_and_sells_nothing_twice(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->discardRecordedMessages();

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['outcome' => 'sold', 'reason' => null, 'reasonCode' => null]],
            array_map(static fn (array $received): array => ['outcome' => $received['reply']['outcome'], 'reason' => $received['reply']['reason'], 'reasonCode' => $received['reply']['reasonCode']], $this->repliesReceivedBy($storefront)),
        );
        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatsSoldToOrder::class));
        self::assertCount(1, $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events);
    }

    public function test_a_confirmation_of_a_sold_order_carrying_another_payment_reference_replies_refused_naming_both_payments(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1'], paymentReference: 'pay-2');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        $reply = $this->repliesReceivedBy($storefront)[0]['reply'];
        self::assertSame('refused', $reply['outcome']);
        self::assertSame("Order {$orderId} cannot buy seat {$concertId}/A-1 with payment pay-2, because it is already sold to this order with payment pay-1. Refund payment pay-2; the seat stays sold with payment pay-1.", $reply['reason']);
        self::assertSame('sold_with_another_payment', $reply['reasonCode']);
        self::assertCount(1, $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events);
    }

    public function test_a_confirm_racing_a_rehold_after_expiry_over_the_distributed_bus_replies_refused_and_the_seat_is_sold_once_to_the_reholding_order(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            public ?string $reholdingOrder = null;

            public ?string $reholdingCustomer = null;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::confirm')]
            public function reholdTheExpiredSeatOnceAfterTheDecision(MethodInvocation $invocation): mixed
            {
                $decision = $invocation->proceed();
                if ($this->reholdingOrder === null) {
                    $this->reholdingOrder = Uuid::v7()->toRfc4122();
                    $this->reholdingCustomer = Uuid::v7()->toRfc4122();
                    $this->competitor->changeTimeTo(new DateTimeImmutable('2026-10-25 12:10:00'));
                    $this->competitor->sendCommand(new HoldSeat(orderId: $this->reholdingOrder, concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: $this->reholdingCustomer, holdId: 'hold-1'), metadata: ['concertId' => $this->concertId]);
                }

                return $decision;
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:09:59'));

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $competitor->sendCommand(new ConfirmSeats(orderId: $interferer->reholdingOrder, concertId: $concertId, customerId: $interferer->reholdingCustomer, seatIds: [$concertId . '/A-1'], paymentReference: 'pay-2'), metadata: ['concertId' => $concertId]);

        self::assertSame(
            [['outcome' => 'refused', 'reason' => "Order {$orderId} cannot be confirmed, because someone was faster: its seats changed while ticketing sold them; nothing of the order was sold. Refund payment pay-1; the buyer holds seats again in a new order.", 'reasonCode' => 'lost_the_race']],
            array_map(static fn (array $received): array => ['outcome' => $received['reply']['outcome'], 'reason' => $received['reply']['reason'], 'reasonCode' => $received['reply']['reasonCode']], $this->repliesReceivedBy($storefront)),
        );
        self::assertSame(
            [$interferer->reholdingOrder],
            array_map(static fn (Event $event): string => $event->getPayload()->orderId, $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events),
        );
    }

    public function test_two_identical_confirmations_handled_by_two_consumers_at_once_both_reply_sold_and_sell_the_seat_once(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public bool $raced = false;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::confirm')]
            public function handleTheSameConfirmationOnTheOtherConsumerMeanwhile(MethodInvocation $invocation): mixed
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->competitor->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $interferer->competitor = $competitor;

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['outcome' => 'sold', 'reason' => null, 'reasonCode' => null], ['outcome' => 'sold', 'reason' => null, 'reasonCode' => null]],
            array_map(static fn (array $received): array => ['outcome' => $received['reply']['outcome'], 'reason' => $received['reply']['reason'], 'reasonCode' => $received['reply']['reasonCode']], $this->repliesReceivedBy($storefront)),
        );
        self::assertCount(1, $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events);
    }

    public function test_two_confirmations_of_one_order_with_different_payments_handled_by_two_consumers_at_once_refuse_the_later_one_naming_both_payments(): void
    {
        $storefront = $this->storefront();
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public bool $raced = false;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::confirm')]
            public function handleTheSameConfirmationOnTheOtherConsumerMeanwhile(MethodInvocation $invocation): mixed
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->competitor->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->sendHold(storefront: $storefront, orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $this->repliesReceivedBy($storefront);
        $interferer->competitor = $competitor;

        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1']);
        $this->sendConfirm(storefront: $storefront, orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1'], paymentReference: 'pay-2');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [['outcome' => 'sold', 'reason' => null, 'reasonCode' => null], ['outcome' => 'refused', 'reason' => "Order {$orderId} cannot buy seat {$concertId}/A-1 with payment pay-1, because it is already sold to this order with payment pay-2. Refund payment pay-1; the seat stays sold with payment pay-2.", 'reasonCode' => 'sold_with_another_payment']],
            array_map(static fn (array $received): array => ['outcome' => $received['reply']['outcome'], 'reason' => $received['reply']['reason'], 'reasonCode' => $received['reply']['reasonCode']], $this->repliesReceivedBy($storefront)),
        );
        self::assertCount(1, $ticketing->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag('seat', $concertId . '/A-1')->ofTypes(SeatSold::class))->events);
    }

    public function test_a_hold_missing_a_field_names_the_missing_field_and_the_contract(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $storefront->getDistributedBus()->sendMessage('ticketing', 'tickets.hold', json_encode(['orderId' => 'order-1', 'seatId' => 'seat-1']), MediaType::APPLICATION_JSON, replyRoutingKey: 'tickets.hold.result');

        $this->expectExceptionMessage('ticketing received tickets.hold without concertId, customerId, holdId: the payload carries orderId, seatId. Send tickets.hold as a JSON object of orderId, concertId, seatId, customerId, holdId, each a non-empty string.');

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

    private function sendHold(FlowTestSupport $storefront, string $orderId, string $concertId, string $seatId, string $customerId, string $holdId = 'hold-1'): void
    {
        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.hold',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'seatId' => $seatId, 'customerId' => $customerId, 'holdId' => $holdId]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
            replyRoutingKey: 'tickets.hold.result',
        );
    }

    private function sendRelease(FlowTestSupport $storefront, string $orderId, string $concertId, string $seatId, string $customerId, string $holdId): void
    {
        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.release',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'seatId' => $seatId, 'customerId' => $customerId, 'holdId' => $holdId]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
        );
    }

    private function sendConfirm(FlowTestSupport $storefront, string $orderId, string $concertId, string $customerId, array $seatIds, string $paymentReference = 'pay-1'): void
    {
        $storefront->getDistributedBus()->sendMessage(
            'ticketing',
            'tickets.confirm',
            json_encode(['orderId' => $orderId, 'concertId' => $concertId, 'customerId' => $customerId, 'seatIds' => $seatIds, 'paymentReference' => $paymentReference]),
            MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId],
            replyRoutingKey: 'tickets.confirm.result',
        );
    }
}
