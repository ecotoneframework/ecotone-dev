<?php

declare(strict_types=1);

namespace App\Tests\Sales;

use App\Catalogue\CancelConcert;
use App\Catalogue\Catalogue;
use App\Catalogue\ChangeTierPrice;
use App\Catalogue\Concert;
use App\Catalogue\ConcertAnnouncements;
use App\Catalogue\ConcertCancelled;
use App\Catalogue\CreateVenue;
use App\Catalogue\OpenSales;
use App\Catalogue\ScheduleConcert;
use App\Catalogue\Tier;
use App\Catalogue\Venue;
use App\Infrastructure\EcotoneConfiguration;
use App\Kernel;
use App\Sales\CancelledConcertHolds;
use App\Sales\ConfirmSeats;
use App\Sales\CustomerConcertSeats;
use App\Sales\ExpireHold;
use App\Sales\FindSeatHold;
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
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class SeatSalesTest extends KernelTestCase
{
    private const NOW = '2026-10-25 12:00:00';

    private ?array $channels = null;

    private ?Kernel $competitorKernel = null;

    protected function tearDown(): void
    {
        $this->competitorKernel?->shutdown();
        $this->competitorKernel = null;
        parent::tearDown();
    }

    public function test_holding_a_free_seat_holds_it_for_the_configured_hold_length(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $ticketing->discardRecordedMessages();

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);

        self::assertSame(600, self::getContainer()->getParameter('ticketingHoldSeconds'));
        self::assertEquals(
            [new SeatHeld(
                orderId: $orderId,
                concertId: $concertId,
                seatId: $concertId . '/A-1',
                customerId: $customerId,
                heldAt: new DateTimeImmutable(self::NOW),
                expiresAt: new DateTimeImmutable('2026-10-25 12:10:00'),
            )],
            $ticketing->popRecordedEventsOfType(SeatHeld::class),
        );
    }

    public function test_the_concert_id_header_of_the_command_reaches_the_recorded_events(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $ticketing->discardRecordedMessages();

        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());

        self::assertSame([$concertId], array_map(static fn (MessageHeaders $headers): mixed => $headers->get('concertId'), $ticketing->popRecordedEventHeaders()));
    }

    public function test_holding_a_seat_another_order_holds_is_refused_because_someone_was_faster(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $firstOrder = Uuid::v7()->toRfc4122();
        $secondOrder = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $firstOrder, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat A-1 of the concert of The Midnight Owls cannot be held for order {$secondOrder}, because someone was faster: order {$firstOrder} holds it until 2026-10-25T12:10:00+00:00. Pick another seat, or try this one again after that hold expires.");

        $this->hold($ticketing, $secondOrder, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
    }

    public function test_holding_a_sold_seat_is_refused_because_someone_was_faster(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $firstOrder = Uuid::v7()->toRfc4122();
        $firstCustomer = Uuid::v7()->toRfc4122();
        $secondOrder = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $firstOrder, $concertId, $concertId . '/A-1', $firstCustomer);
        $this->confirm($ticketing, $firstOrder, $concertId, $firstCustomer, [$concertId . '/A-1']);

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat A-1 of the concert of The Midnight Owls cannot be held for order {$secondOrder}, because someone was faster: it is sold to order {$firstOrder}. Pick another seat.");

        $this->hold($ticketing, $secondOrder, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
    }

    public function test_holding_a_fifth_seat_of_a_concert_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $customerId = Uuid::v7()->toRfc4122();
        $orderId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2', 'A-3', 'A-4'] as $seatCode) {
            $this->hold($ticketing, $orderId, $concertId, $concertId . '/' . $seatCode, $customerId);
        }

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat A-5 of the concert of The Midnight Owls cannot be held for order {$orderId}, because customer {$customerId} already holds or owns 4 seats of this concert (A-1, A-2, A-3, A-4), and a customer gets at most 4. Release one of those holds first, or buy with another customer.");

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-5', $customerId);
    }

    public function test_a_released_hold_no_longer_counts_towards_the_four_seats_of_a_customer(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $customerId = Uuid::v7()->toRfc4122();
        $orderId = Uuid::v7()->toRfc4122();
        foreach (['A-1', 'A-2', 'A-3', 'A-4'] as $seatCode) {
            $this->hold($ticketing, $orderId, $concertId, $concertId . '/' . $seatCode, $customerId);
        }
        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-4', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $ticketing->discardRecordedMessages();

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-5', $customerId);

        self::assertCount(1, $ticketing->popRecordedEventsOfType(SeatHeld::class));
    }

    public function test_holding_a_seat_of_a_concert_not_on_sale_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->scheduleConcert($ticketing);
        $orderId = Uuid::v7()->toRfc4122();

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat A-1 of the concert of The Midnight Owls cannot be held for order {$orderId}, because the concert is scheduled, not on sale. Seats are held only while a concert is on sale.");

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
    }

    public function test_holding_a_seat_of_an_unknown_concert_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = Uuid::v7()->toRfc4122();
        $orderId = Uuid::v7()->toRfc4122();

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat {$concertId}/A-1 cannot be held for order {$orderId}, because ticketing has no concert {$concertId}. Pick a concert from concert.scheduled announcements, or ask ticketing to publish concert.scheduled again.");

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
    }

    public function test_holding_a_seat_the_concert_does_not_have_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat {$concertId}/Z-99 cannot be held for order {$orderId}, because the concert of The Midnight Owls has no such seat: its seats are A-1 to F-12. Pick a seat id of the form {$concertId}/<seatCode> from the concert's seats.");

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/Z-99', Uuid::v7()->toRfc4122());
    }

    public function test_releasing_a_seat_by_the_order_holding_it_lets_another_order_hold_it(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());

        self::assertEquals(
            [new HoldReleased(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId)],
            $ticketing->popRecordedEventsOfType(HoldReleased::class),
        );
        self::assertCount(1, $ticketing->popRecordedEventsOfType(SeatHeld::class));
    }

    public function test_releasing_a_seat_another_order_holds_is_refused_naming_that_order(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $holdingOrder = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $otherOrder = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $holdingOrder, $concertId, $concertId . '/A-1', $customerId);

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat {$concertId}/A-1 cannot be released for order {$otherOrder} of customer {$customerId} at concert {$concertId}, because order {$holdingOrder} of customer {$customerId} holds it at concert {$concertId}. Release a seat with the order, customer and concert of its hold; only the holder releases it.");

        $ticketing->sendCommand(new ReleaseSeat(orderId: $otherOrder, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
    }

    public function test_releasing_a_seat_for_another_customer_than_the_one_holding_it_is_refused_naming_the_holder(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $holdingCustomer = Uuid::v7()->toRfc4122();
        $otherCustomer = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $holdingCustomer);
        $ticketing->discardRecordedMessages();

        try {
            $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $otherCustomer), metadata: ['concertId' => $concertId]);
            self::fail('Releasing a seat for another customer than the holding one was expected to be refused.');
        } catch (SeatRefusal $refusal) {
            self::assertSame("Seat {$concertId}/A-1 cannot be released for order {$orderId} of customer {$otherCustomer} at concert {$concertId}, because order {$orderId} of customer {$holdingCustomer} holds it at concert {$concertId}. Release a seat with the order, customer and concert of its hold; only the holder releases it.", $refusal->getMessage());
        }
        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldReleased::class));
        self::assertEquals(['orderId' => $orderId, 'expiresAt' => new DateTimeImmutable('2026-10-25 12:10:00')], $ticketing->sendQuery(new FindSeatHold($concertId . '/A-1')));
    }

    public function test_releasing_a_seat_for_another_concert_than_the_one_of_its_hold_is_refused_naming_the_holder(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $otherConcertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        try {
            $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $otherConcertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $otherConcertId]);
            self::fail('Releasing a seat for another concert than the one of its hold was expected to be refused.');
        } catch (SeatRefusal $refusal) {
            self::assertSame("Seat {$concertId}/A-1 cannot be released for order {$orderId} of customer {$customerId} at concert {$otherConcertId}, because order {$orderId} of customer {$customerId} holds it at concert {$concertId}. Release a seat with the order, customer and concert of its hold; only the holder releases it.", $refusal->getMessage());
        }
        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldReleased::class));
        self::assertEquals(['orderId' => $orderId, 'expiresAt' => new DateTimeImmutable('2026-10-25 12:10:00')], $ticketing->sendQuery(new FindSeatHold($concertId . '/A-1')));
    }

    public function test_releasing_a_free_seat_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Seat {$concertId}/A-1 cannot be released for order {$orderId}, because no order holds it. Only the order holding a seat releases it.");

        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: Uuid::v7()->toRfc4122()), metadata: ['concertId' => $concertId]);
    }

    public function test_expiring_the_live_hold_of_an_order_frees_the_seat(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        $ticketing->sendCommand(new ExpireHold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, expiresAt: new DateTimeImmutable('2026-10-25 12:10:00')), metadata: ['concertId' => $concertId]);
        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());

        self::assertEquals(
            [new HoldExpired(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId)],
            $ticketing->popRecordedEventsOfType(HoldExpired::class),
        );
        self::assertCount(1, $ticketing->popRecordedEventsOfType(SeatHeld::class));
    }

    public function test_expiring_a_hold_the_order_released_does_nothing(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $ticketing->discardRecordedMessages();

        $ticketing->sendCommand(new ExpireHold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, expiresAt: new DateTimeImmutable('2026-10-25 12:10:00')), metadata: ['concertId' => $concertId]);

        self::assertSame([], $ticketing->popRecordedEvents());
    }

    public function test_expiring_a_hold_after_another_order_took_the_seat_leaves_the_new_hold(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $nextOrder = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $this->hold($ticketing, $nextOrder, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
        $ticketing->discardRecordedMessages();

        $ticketing->sendCommand(new ExpireHold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, expiresAt: new DateTimeImmutable('2026-10-25 12:10:00')), metadata: ['concertId' => $concertId]);

        self::assertSame([], $ticketing->popRecordedEvents());
        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("because someone was faster: order {$nextOrder} holds it");
        $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
    }

    public function test_a_hold_expires_on_the_asynchronous_channel_only_once_the_hold_length_has_passed(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        $ticketing->advanceTimeBy(Duration::seconds(599));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldExpired::class));

        $ticketing->advanceTimeBy(Duration::seconds(1));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(
            [new HoldExpired(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId)],
            $ticketing->popRecordedEventsOfType(HoldExpired::class),
        );
    }

    public function test_a_hold_released_before_its_expiry_is_not_expired_when_the_hold_length_passes(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $ticketing->discardRecordedMessages();

        $ticketing->advanceTimeBy(Duration::seconds(600));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldExpired::class));
    }

    public function test_the_expiry_of_a_released_hold_does_not_expire_the_order_holding_the_seat_again(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->sendCommand(new ReleaseSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $ticketing->advanceTimeBy(Duration::seconds(300));
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        $ticketing->advanceTimeBy(Duration::seconds(300));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ticketing->popRecordedEventsOfType(HoldExpired::class));
        self::assertEquals(['orderId' => $orderId, 'expiresAt' => new DateTimeImmutable('2026-10-25 12:15:00')], $ticketing->sendQuery(new FindSeatHold($concertId . '/A-1')));
    }

    public function test_confirming_the_live_holds_of_an_order_sells_every_seat_at_its_tier_price(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/C-1', $customerId);
        $ticketing->discardRecordedMessages();

        $this->confirm($ticketing, $orderId, $concertId, $customerId, [$concertId . '/A-1', $concertId . '/C-1']);

        self::assertEquals(
            [
                new SeatSold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId, paymentReference: 'pay-1', tier: Tier::VIP, priceCents: 9500),
                new SeatSold(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/C-1', customerId: $customerId, paymentReference: 'pay-1', tier: Tier::STANDARD, priceCents: 4500),
            ],
            $ticketing->popRecordedEventsOfType(SeatSold::class),
        );
    }

    public function test_confirming_a_seat_the_order_does_not_hold_is_refused_naming_the_seat(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->discardRecordedMessages();

        try {
            $this->confirm($ticketing, $orderId, $concertId, $customerId, [$concertId . '/A-1', $concertId . '/A-2']);
            self::fail('Confirming a seat the order does not hold was expected to be refused.');
        } catch (SeatRefusal $refusal) {
            self::assertSame("Order {$orderId} cannot buy seat {$concertId}/A-2, because it does not hold it. Hold the seat first, then confirm the order again; nothing of the order was sold.", $refusal->getMessage());
        }
        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatSold::class));
    }

    public function test_confirming_a_hold_past_its_expiry_is_refused_naming_when_it_expired(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $ticketing->changeTimeTo(new DateTimeImmutable('2026-10-25 12:10:01'));

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Order {$orderId} cannot buy seat {$concertId}/A-1, because its hold expired at 2026-10-25T12:10:00+00:00. Hold the seat again, then confirm the order again; nothing of the order was sold.");

        $this->confirm($ticketing, $orderId, $concertId, $customerId, [$concertId . '/A-1']);
    }

    public function test_confirming_seats_already_sold_to_the_order_is_refused(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);
        $this->confirm($ticketing, $orderId, $concertId, $customerId, [$concertId . '/A-1']);

        $this->expectException(SeatRefusal::class);
        $this->expectExceptionMessage("Order {$orderId} cannot buy seat {$concertId}/A-1, because it is already sold to order {$orderId}. Confirm only seats that are held; nothing of the order was sold.");

        $this->confirm($ticketing, $orderId, $concertId, $customerId, [$concertId . '/A-1']);
    }

    public function test_a_hold_whose_seat_changes_between_read_and_append_on_every_attempt_surfaces_the_conflict_naming_the_seat_tag_and_versions(): void
    {
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?Connection $competitorConnection = null;

            public ?EventStore $competitorEventStore = null;

            public ?string $concertId = null;

            public int $attempts = 0;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function releaseTheSameSeatMeanwhile(MethodInvocation $invocation): mixed
            {
                $this->attempts++;
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

        try {
            $this->hold($ticketing, Uuid::v7()->toRfc4122(), $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
            self::fail('The hold was expected to conflict on every attempt.');
        } catch (DecisionModelConcurrencyException $conflict) {
            self::assertStringContainsString("Concurrent append conflict on tag seat:{$concertId}/A-1 (expected version ", $conflict->getMessage());
            self::assertStringContainsString(', current version ', $conflict->getMessage());
            self::assertSame('seat', $conflict->conflictingTagName());
            self::assertSame($concertId . '/A-1', $conflict->conflictingTagValue());
        }
        self::assertSame(4, $interferer->attempts);
    }

    public function test_a_hold_that_lost_the_race_to_another_order_is_refused_on_the_retry_because_someone_was_faster(): void
    {
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
                    $this->competitor->sendCommand(new HoldSeat(orderId: $this->fasterOrder, concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: Uuid::v7()->toRfc4122()), metadata: ['concertId' => $this->concertId]);
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;
        $orderId = Uuid::v7()->toRfc4122();

        try {
            $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', Uuid::v7()->toRfc4122());
            self::fail('The hold was expected to be refused on the retry.');
        } catch (SeatRefusal $refusal) {
            self::assertSame("Seat A-1 of the concert of The Midnight Owls cannot be held for order {$orderId}, because someone was faster: order {$interferer->fasterOrder} holds it until 2026-10-25T12:10:00+00:00. Pick another seat, or try this one again after that hold expires.", $refusal->getMessage());
        }
    }

    public function test_a_hold_racing_a_cancel_of_its_concert_can_succeed_and_the_cancelled_concert_releases_it(): void
    {
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            public int $attempts = 0;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: SeatSales::class . '::hold')]
            public function cancelTheConcertOnceAfterTheDecision(MethodInvocation $invocation): mixed
            {
                $decision = $invocation->proceed();
                if (++$this->attempts === 1) {
                    $this->competitor->sendCommand(new CancelConcert($this->concertId));
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
        $ticketing->discardRecordedMessages();

        $this->hold($ticketing, $orderId, $concertId, $concertId . '/A-1', $customerId);

        self::assertSame(1, $interferer->attempts);
        self::assertCount(1, $ticketing->popRecordedEventsOfType(SeatHeld::class));

        $ticketing->publishEvent(new ConcertCancelled($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(
            [new HoldReleased(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $customerId)],
            $ticketing->popRecordedEventsOfType(HoldReleased::class),
        );
    }

    public function test_cancelling_a_concert_releases_its_live_holds_and_leaves_its_sold_seats_sold(): void
    {
        $ticketing = $this->ticketing();
        $concertId = $this->concertOnSale($ticketing);
        $holdingOrder = Uuid::v7()->toRfc4122();
        $holdingCustomer = Uuid::v7()->toRfc4122();
        $buyingOrder = Uuid::v7()->toRfc4122();
        $buyer = Uuid::v7()->toRfc4122();
        $this->hold($ticketing, $holdingOrder, $concertId, $concertId . '/A-1', $holdingCustomer);
        $this->hold($ticketing, $holdingOrder, $concertId, $concertId . '/A-2', $holdingCustomer);
        $ticketing->sendCommand(new ReleaseSeat(orderId: $holdingOrder, concertId: $concertId, seatId: $concertId . '/A-2', customerId: $holdingCustomer), metadata: ['concertId' => $concertId]);
        $this->hold($ticketing, $buyingOrder, $concertId, $concertId . '/A-3', $buyer);
        $this->confirm($ticketing, $buyingOrder, $concertId, $buyer, [$concertId . '/A-3']);
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());
        $ticketing->discardRecordedMessages();

        $ticketing->sendCommand(new CancelConcert($concertId));
        $ticketing->run('ticketing_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(
            [new HoldReleased(orderId: $holdingOrder, concertId: $concertId, seatId: $concertId . '/A-1', customerId: $holdingCustomer)],
            $ticketing->popRecordedEventsOfType(HoldReleased::class),
        );
        self::assertSame([], $ticketing->popRecordedEventsOfType(SeatSold::class));
    }

    public function test_holding_a_seat_while_a_tier_price_changes_conflicts_with_neither(): void
    {
        $competitor = $this->competitor();
        $interferer = new class () {
            public ?FlowTestSupport $competitor = null;

            public ?string $concertId = null;

            public int $attempts = 0;

            #[Around(precedence: Precedence::DEFAULT_PRECEDENCE + 1, pointcut: Concert::class . '::changeTierPrice')]
            public function holdASeatMeanwhile(MethodInvocation $invocation): mixed
            {
                if (++$this->attempts === 1) {
                    $this->competitor->sendCommand(new HoldSeat(orderId: Uuid::v7()->toRfc4122(), concertId: $this->concertId, seatId: $this->concertId . '/A-1', customerId: Uuid::v7()->toRfc4122()), metadata: ['concertId' => $this->concertId]);
                }

                return $invocation->proceed();
            }
        };
        $ticketing = $this->ticketing($interferer);
        $concertId = $this->concertOnSale($ticketing);
        $interferer->competitor = $competitor;
        $interferer->concertId = $concertId;

        $ticketing->sendCommand(new ChangeTierPrice(concertId: $concertId, tier: Tier::VIP, priceCents: 12000));

        self::assertSame(1, $interferer->attempts);
        self::assertSame(12000, $ticketing->sendQueryWithRouting('concert.detail', metadata: ['aggregate.id' => $concertId])['vipPriceCents']);
    }

    private function ticketing(object ...$applicationHandlers): FlowTestSupport
    {
        self::bootKernel();
        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues');

        return $this->bootstrapTicketing($doctrine, self::getContainer()->getParameter('ticketingHoldSeconds'), ...$applicationHandlers);
    }

    private function competitor(): FlowTestSupport
    {
        $this->competitorKernel = new Kernel('test', true);
        $this->competitorKernel->boot();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->competitorKernel->getContainer()->get('doctrine');

        return $this->bootstrapTicketing($doctrine, $this->competitorKernel->getContainer()->getParameter('ticketingHoldSeconds'));
    }

    private function bootstrapTicketing(ManagerRegistry $doctrine, int $holdSeconds, object ...$applicationHandlers): FlowTestSupport
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
                CancelledConcertHolds::class,
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
                new CancelledConcertHolds(),
                ...$applicationHandlers,
                'doctrine' => $doctrine,
            ],
            configurationVariables: ['ticketingHoldSeconds' => $holdSeconds],
            extensionObjects: [...$this->channels(), SymfonyConnectionReference::defaultManagerRegistry('default')],
            pathToRootCatalog: dirname(__DIR__, 2),
            addInMemoryStateStoredRepository: false,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable(self::NOW)),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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

    private function scheduleConcert(FlowTestSupport $ticketing): string
    {
        $venueId = $ticketing->getCommandBus()->send(new CreateVenue(name: 'Riverside Hall', sections: ['Stalls', 'Balcony'], rowsPerSection: 3, seatsPerRow: 12, vipRows: ['A', 'B']));

        return $ticketing->getCommandBus()->send(new ScheduleConcert(
            venueId: $venueId,
            artist: 'The Midnight Owls',
            startsAt: new DateTimeImmutable('2026-11-20 20:00:00'),
            standardPriceCents: 4500,
            vipPriceCents: 9500,
            currency: 'EUR',
        ));
    }

    private function concertOnSale(FlowTestSupport $ticketing): string
    {
        $concertId = $this->scheduleConcert($ticketing);
        $ticketing->sendCommand(new OpenSales($concertId));

        return $concertId;
    }

    private function hold(FlowTestSupport $ticketing, string $orderId, string $concertId, string $seatId, string $customerId): void
    {
        $ticketing->sendCommand(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $seatId, customerId: $customerId), metadata: ['concertId' => $concertId]);
    }

    private function confirm(FlowTestSupport $ticketing, string $orderId, string $concertId, string $customerId, array $seatIds): void
    {
        $ticketing->sendCommand(new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: $seatIds, paymentReference: 'pay-1'), metadata: ['concertId' => $concertId]);
    }
}
