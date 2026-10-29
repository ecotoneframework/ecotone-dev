<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Customer\RegisterCustomer;
use App\Ordering\BeginPayment;
use App\Ordering\DropExpiredSeat;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\MarkSeatHeld;
use App\Ordering\MarkTicketsIssued;
use App\Ordering\RecordSeatRefusal;
use App\Ordering\ReleaseSeat;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use Closure;
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * @internal
 */
final class OrderLifecycleTest extends TestCase
{
    private const CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const OTHER_CONCERT_ID = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e41';
    private const EXPIRES_AT = '2026-10-25T12:02:00+00:00';
    private const LATER_EXPIRES_AT = '2026-10-25T12:04:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('orders')->delete();
        DB::table('customers')->delete();
    }

    public function test_a_registered_customer_is_listed_with_name_and_email(): void
    {
        $customerId = $this->commandBus()->send(new RegisterCustomer(name: 'Ada Lovelace', email: 'ada@example.com'));

        self::assertSame(
            [['customerId' => $customerId, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']],
            $this->queryBus()->sendWithRouting('customers.list'),
        );
    }

    public function test_a_registered_customer_is_found_by_id(): void
    {
        $customerId = $this->registerAda();
        $this->commandBus()->send(new RegisterCustomer(name: 'Grace Hopper', email: 'grace@example.com'));

        self::assertSame(
            ['customerId' => $customerId, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            $this->queryBus()->sendWithRouting('customer.detail', $customerId),
        );
    }

    public function test_a_customer_id_never_registered_finds_no_customer(): void
    {
        self::assertNull($this->queryBus()->sendWithRouting('customer.detail', '0199f3a8-6f2a-7c1e-9a51-000000000000'));
    }

    public function test_a_value_that_is_not_a_customer_id_finds_no_customer(): void
    {
        self::assertNull($this->queryBus()->sendWithRouting('customer.detail', 'not-a-customer-id'));
    }

    public function test_registering_an_email_already_registered_is_refused(): void
    {
        $customerId = $this->registerAda();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Customer %s is already registered with email ada@example.com, so RegisterCustomer cannot register Ada Byron with it: an email belongs to one customer. Use customer %s, register with another email, or run php artisan demo:seed, which registers only the demo customers not registered yet.', $customerId, $customerId));

        $this->commandBus()->send(new RegisterCustomer(name: 'Ada Byron', email: 'ada@example.com'));
    }

    public function test_a_started_order_is_listed_among_the_customer_orders(): void
    {
        $customerId = $this->registerAda();

        $orderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $orderId);
        self::assertSame(
            [['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'status' => 'started', 'seatIds' => []]],
            $this->queryBus()->sendWithRouting('customer.orders', $customerId),
        );
    }

    public function test_starting_a_second_open_order_for_the_same_concert_is_refused(): void
    {
        $customerId = $this->registerAda();
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Customer %s already has open order %s for concert %s, so StartOrder cannot start another: a customer has one open order per concert. Hold more seats on order %s, or pay for it or release its seats before starting a new one.', $customerId, $orderId, self::CONCERT_ID, $orderId));

        $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));
    }

    #[DataProvider('statusesThatCloseAnOrder')]
    public function test_a_customer_whose_order_is_no_longer_open_can_start_another_for_the_same_concert(string $status): void
    {
        $orderId = $this->orderIn($status);
        $customerId = $this->orderDetail($orderId)['customerId'];

        $nextOrderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        self::assertNotSame($orderId, $nextOrderId);
    }

    public static function statusesThatCloseAnOrder(): iterable
    {
        yield 'paying' => ['paying'];
        yield 'paid' => ['paid'];
        yield 'tickets_issued' => ['tickets_issued'];
        yield 'failed' => ['failed'];
    }

    public function test_a_customer_can_have_open_orders_for_different_concerts(): void
    {
        $customerId = $this->registerAda();
        $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::OTHER_CONCERT_ID));

        self::assertCount(2, $this->queryBus()->sendWithRouting('customer.orders', $customerId));
    }

    public function test_a_held_seat_moves_the_order_to_seats_held_with_the_hold_expiry(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $order = $this->orderDetail($orderId);
        self::assertSame('seats_held', $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
    }

    public function test_seats_held_later_are_added_to_the_seats_already_held(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-1', expiresAt: self::LATER_EXPIRES_AT));

        self::assertSame(
            [['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT], ['seatId' => self::CONCERT_ID . '/A-2', 'expiresAt' => self::LATER_EXPIRES_AT]],
            $this->orderDetail($orderId)['heldSeats'],
        );
    }

    public function test_a_held_reply_repeated_for_a_held_seat_keeps_one_hold_with_its_first_expiry(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::LATER_EXPIRES_AT));

        self::assertSame([['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT]], $this->orderDetail($orderId)['heldSeats']);
    }

    public function test_the_hold_expiry_is_kept_in_utc(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: '2026-10-25T14:02:00+02:00'));

        self::assertSame(self::EXPIRES_AT, $this->orderDetail($orderId)['heldSeats'][0]['expiresAt']);
    }

    public function test_holding_a_seat_with_an_expiry_that_is_not_a_date_is_refused(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('MarkSeatHeld for seat %s/A-1 of order %s carries expiresAt "soon", which is not an ISO-8601 date, so the storefront cannot count down the hold. Ticketing sends expiresAt as ISO-8601, like "2026-10-25T12:02:00+00:00": correct the tickets.hold.result reply and replay it from the dbal_dead_letter.', self::CONCERT_ID, $orderId));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: 'soon'));
    }

    public function test_holding_a_seat_with_no_expiry_is_refused(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('MarkSeatHeld for seat %s/A-1 of order %s carries expiresAt "", which is not an ISO-8601 date', self::CONCERT_ID, $orderId));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: ''));
    }

    public function test_holding_a_seat_of_another_concert_is_refused(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Seat %s/A-1 belongs to concert %s, but order %s is for concert %s, so MarkSeatHeld cannot add it. Hold a seat of concert %s, or start an order for concert %s with StartOrder.', self::OTHER_CONCERT_ID, self::OTHER_CONCERT_ID, $orderId, self::CONCERT_ID, self::CONCERT_ID, self::OTHER_CONCERT_ID));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::OTHER_CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
    }

    public function test_a_held_seat_clears_the_refusal_of_the_same_seat(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused'));
        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused', reason: 'someone was faster: seat A-1'));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        self::assertNull($this->orderDetail($orderId)['lastRefusal']);
    }

    public function test_a_refused_seat_is_the_last_refusal_of_the_order(): void
    {
        $orderId = $this->orderWithHeldSeats();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-refused'));

        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-refused', reason: 'someone was faster: seat A-2'));

        $order = $this->orderDetail($orderId);
        self::assertSame(['seatId' => self::CONCERT_ID . '/A-2', 'reason' => 'someone was faster: seat A-2'], $order['lastRefusal']);
        self::assertSame('seats_held', $order['status']);
    }

    public function test_a_later_refusal_replaces_the_last_refusal(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused'));
        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-refused', reason: 'someone was faster: seat A-1'));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-3', holdId: 'hold-refused'));

        $this->commandBus()->send(new RecordSeatRefusal(orderId: $orderId, seatId: self::CONCERT_ID . '/A-3', holdId: 'hold-refused', reason: 'a customer holds or owns at most 4 seats per concert'));

        self::assertSame(['seatId' => self::CONCERT_ID . '/A-3', 'reason' => 'a customer holds or owns at most 4 seats per concert'], $this->orderDetail($orderId)['lastRefusal']);
    }

    public function test_releasing_a_held_seat_removes_it_from_the_order(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        $this->commandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1'));

        $order = $this->orderDetail($orderId);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-2', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
        self::assertSame('seats_held', $order['status']);
    }

    public function test_releasing_the_last_held_seat_moves_the_order_back_to_started(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->commandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1'));

        $order = $this->orderDetail($orderId);
        self::assertSame([], $order['heldSeats']);
        self::assertSame('started', $order['status']);
    }

    public function test_releasing_a_seat_the_order_does_not_hold_is_refused(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s does not hold seat %s/A-2, so ReleaseSeat cannot release it. The order holds A-1: release one of those, or leave the seat alone.', $orderId, self::CONCERT_ID));

        $this->commandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2'));
    }

    public function test_an_expired_hold_drops_the_seat_from_the_order(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->commandBus()->send(new DropExpiredSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $order = $this->orderDetail($orderId);
        self::assertSame([], $order['heldSeats']);
        self::assertSame('started', $order['status']);
    }

    public function test_an_expired_hold_of_a_seat_the_order_no_longer_holds_changes_nothing(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->commandBus()->send(new DropExpiredSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-2', holdId: 'hold-1'));

        $order = $this->orderDetail($orderId);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
        self::assertSame('seats_held', $order['status']);
    }

    #[DataProvider('statusesThatKeepAnExpiredSeat')]
    public function test_an_expired_hold_after_checkout_began_keeps_the_seat_for_ticketing_to_decide(string $status): void
    {
        $orderId = $this->orderIn($status);

        $this->commandBus()->send(new DropExpiredSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        $order = $this->orderDetail($orderId);
        self::assertSame($status, $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-1', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
    }

    public static function statusesThatKeepAnExpiredSeat(): iterable
    {
        yield 'paying' => ['paying'];
        yield 'paid' => ['paid'];
        yield 'tickets_issued' => ['tickets_issued'];
    }

    public function test_an_expired_hold_of_a_failed_order_changes_nothing(): void
    {
        $orderId = $this->orderWithHeldSeats();
        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'Payment declined'));

        $this->commandBus()->send(new DropExpiredSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));

        self::assertSame('failed', $this->orderDetail($orderId)['status']);
    }

    public function test_beginning_payment_moves_an_order_with_held_seats_to_paying(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        self::assertSame('paying', $this->orderDetail($orderId)['status']);
    }

    public function test_marking_a_paying_order_paid_records_the_payment_reference(): void
    {
        $orderId = $this->payingOrder();

        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

        $order = $this->orderDetail($orderId);
        self::assertSame('paid', $order['status']);
        self::assertSame('pay_123', $order['paymentReference']);
    }

    public function test_issued_tickets_move_a_paid_order_to_tickets_issued_with_their_entry_codes(): void
    {
        $orderId = $this->paidOrder();

        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $order = $this->orderDetail($orderId);
        self::assertSame('tickets_issued', $order['status']);
        self::assertSame($this->tickets(), $order['tickets']);
    }

    public function test_failing_an_order_records_the_failure_reason(): void
    {
        $orderId = $this->payingOrder();

        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'Payment declined'));

        $order = $this->orderDetail($orderId);
        self::assertSame('failed', $order['status']);
        self::assertSame('Payment declined', $order['failureReason']);
    }

    public function test_the_order_detail_names_customer_concert_and_payment_state(): void
    {
        $customerId = $this->registerAda();
        $orderId = $this->commandBus()->send(new StartOrder(customerId: $customerId, concertId: self::CONCERT_ID));

        self::assertSame(
            [
                'orderId' => $orderId,
                'customerId' => $customerId,
                'concertId' => self::CONCERT_ID,
                'status' => 'started',
                'heldSeats' => [],
                'lastRefusal' => null,
                'askingSeatIds' => [],
                'paymentReference' => null,
                'failureReason' => null,
                'tickets' => [],
                'confirmationEmailBounces' => false,
            ],
            $this->orderDetail($orderId),
        );
    }

    #[DataProvider('malformedSeatIds')]
    public function test_holding_a_seat_id_that_is_not_concert_and_seat_code_is_refused_and_leaves_the_order_started(string $seatId): void
    {
        $orderId = $this->startOrder();

        try {
            $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: $seatId, holdId: 'hold-1'));
            $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: $seatId, holdId: 'hold-1', expiresAt: self::EXPIRES_AT));
            self::fail('MarkSeatHeld was accepted');
        } catch (DomainException $exception) {
            self::assertSame(sprintf('Seat id "%s" is not CONCERT-ID/ROW-NUMBER, so its seat code cannot be read. Send the concert id, a slash and the seat code concert.scheduled published for the seat, like "{concert id}/A-1".', $seatId), $exception->getMessage());
        }
        self::assertSame('started', $this->orderDetail($orderId)['status']);
    }

    public static function malformedSeatIds(): iterable
    {
        yield 'no slash' => ['A-1'];
        yield 'seat code that is not ROW-NUMBER' => [self::CONCERT_ID . '/A1'];
        yield 'empty seat code' => [self::CONCERT_ID . '/'];
        yield 'seat code with a trailing newline' => [self::CONCERT_ID . "/A-1\n"];
    }

    public function test_payment_cannot_begin_on_an_order_with_no_held_seats(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s is started, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.', $orderId));

        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));
    }

    public function test_tickets_cannot_be_issued_for_an_unpaid_order(): void
    {
        $orderId = $this->payingOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s is paying, not paid, so its tickets cannot be issued. Pay for it first: send MarkPaid with the payment reference.', $orderId));

        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));
    }

    public function test_an_order_with_issued_tickets_cannot_fail(): void
    {
        $orderId = $this->paidOrder();
        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s already has its tickets issued, so FailOrder cannot be applied to it: an order with issued tickets is final. Start a new order with StartOrder to buy more tickets.', $orderId));

        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'Too late'));
    }

    public function test_a_command_out_of_order_names_the_statuses_that_accept_it(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s is seats_held, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.', $orderId));

        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));
    }

    #[DataProvider('refusedCommands')]
    public function test_a_command_its_status_does_not_accept_is_refused_and_leaves_the_order_unchanged(string $status, Closure $command, string $expectedMessage): void
    {
        $orderId = $this->orderIn($status);

        try {
            $this->commandBus()->send($command($orderId));
            self::fail();
        } catch (DomainException $refusal) {
            self::assertSame(sprintf($expectedMessage, $orderId), $refusal->getMessage());
        }
        self::assertSame($status, $this->orderDetail($orderId)['status']);
    }

    public static function refusedCommands(): iterable
    {
        $releaseSeat = fn (string $orderId) => new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1');
        $beginPayment = fn (string $orderId) => new BeginPayment(orderId: $orderId, confirmationEmailBounces: false);
        $markPaid = fn (string $orderId) => new MarkPaid(orderId: $orderId, paymentReference: 'pay_456');
        $markTicketsIssued = fn (string $orderId) => new MarkTicketsIssued(orderId: $orderId, tickets: [['ticketNumber' => 'T-1', 'seatId' => self::CONCERT_ID . '/A-1', 'tier' => 'vip', 'entryCode' => 'ENTRY-1']]);
        $failOrder = fn (string $orderId) => new FailOrder(orderId: $orderId, reason: 'Too late');

        yield 'BeginPayment on a started order' => ['started', $beginPayment, 'Order %s is started, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a started order' => ['started', $markPaid, 'Order %s is started, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a started order' => ['started', $markTicketsIssued, 'Order %s is started, not paid, so its tickets cannot be issued. Pay for it first: send MarkSeatHeld, then BeginPayment, then MarkPaid with the payment reference.'];
        yield 'MarkPaid on a seats_held order' => ['seats_held', $markPaid, 'Order %s is seats_held, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a seats_held order' => ['seats_held', $markTicketsIssued, 'Order %s is seats_held, not paid, so its tickets cannot be issued. Pay for it first: send BeginPayment, then MarkPaid with the payment reference.'];
        yield 'ReleaseSeat on a paying order' => ['paying', $releaseSeat, 'Order %s is paying, so ReleaseSeat cannot be applied to it. ReleaseSeat is accepted only while the order is started or seats_held.'];
        yield 'ReleaseSeat on a paid order' => ['paid', $releaseSeat, 'Order %s is paid, so ReleaseSeat cannot be applied to it. ReleaseSeat is accepted only while the order is started or seats_held.'];
        yield 'ReleaseSeat on a failed order' => ['failed', $releaseSeat, 'Order %s is failed, so ReleaseSeat cannot be applied to it: a failed order is final. Start a new order with StartOrder for the same customer and concert.'];
        yield 'BeginPayment on a paying order' => ['paying', $beginPayment, 'Order %s is paying, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkTicketsIssued on a paying order' => ['paying', $markTicketsIssued, 'Order %s is paying, not paid, so its tickets cannot be issued. Pay for it first: send MarkPaid with the payment reference.'];
        yield 'BeginPayment on a paid order' => ['paid', $beginPayment, 'Order %s is paid, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a paid order' => ['paid', $markPaid, 'Order %s is paid, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'BeginPayment on a tickets_issued order' => ['tickets_issued', $beginPayment, 'Order %s already has its tickets issued, so BeginPayment cannot be applied to it: an order with issued tickets is final. Start a new order with StartOrder to buy more tickets.'];
        yield 'MarkPaid on a tickets_issued order' => ['tickets_issued', $markPaid, 'Order %s already has its tickets issued, so MarkPaid cannot be applied to it: an order with issued tickets is final. Start a new order with StartOrder to buy more tickets.'];
        yield 'MarkTicketsIssued with other tickets on a tickets_issued order' => ['tickets_issued', $markTicketsIssued, 'Order %s already has tickets T-0001 issued, but this tickets.issued carries tickets T-1, so the storefront cannot tell which ones the customer holds. Keep the issued ones by deleting this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>, and void tickets T-1 in ticketing.'];
        yield 'FailOrder on a tickets_issued order' => ['tickets_issued', $failOrder, 'Order %s already has its tickets issued, so FailOrder cannot be applied to it: an order with issued tickets is final. Start a new order with StartOrder to buy more tickets.'];
        yield 'BeginPayment on a failed order' => ['failed', $beginPayment, 'Order %s is failed, so BeginPayment cannot be applied to it: a failed order is final. Start a new order with StartOrder for the same customer and concert.'];
        yield 'MarkPaid on a failed order' => ['failed', $markPaid, 'Order %s is failed, so MarkPaid cannot be applied to it: a failed order is final. Start a new order with StartOrder for the same customer and concert.'];
        yield 'MarkTicketsIssued on a failed order' => ['failed', $markTicketsIssued, 'Order %s failed (Payment declined) and it was never paid, so tickets T-1 that ticketing issued for it are not recorded. Void tickets T-1 in ticketing, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>.'];
        yield 'FailOrder on a failed order' => ['failed', $failOrder, 'Order %s is failed, so FailOrder cannot be applied to it: a failed order is final. Start a new order with StartOrder for the same customer and concert.'];
    }

    private function registerAda(): string
    {
        return $this->commandBus()->send(new RegisterCustomer(name: 'Ada Lovelace', email: 'ada@example.com'));
    }

    private function startOrder(): string
    {
        return $this->commandBus()->send(new StartOrder(customerId: $this->registerAda(), concertId: self::CONCERT_ID));
    }

    private function orderWithHeldSeats(): string
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1'));
        $this->commandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-1', holdId: 'hold-1', expiresAt: self::EXPIRES_AT));

        return $orderId;
    }

    private function payingOrder(): string
    {
        $orderId = $this->orderWithHeldSeats();
        $this->commandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        return $orderId;
    }

    private function paidOrder(): string
    {
        $orderId = $this->payingOrder();
        $this->commandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

        return $orderId;
    }

    private function orderIn(string $status): string
    {
        return match ($status) {
            'started' => $this->startOrder(),
            'seats_held' => $this->orderWithHeldSeats(),
            'paying' => $this->payingOrder(),
            'paid' => $this->paidOrder(),
            'tickets_issued' => $this->orderWithIssuedTickets(),
            'failed' => $this->failedOrder(),
        };
    }

    private function orderWithIssuedTickets(): string
    {
        $orderId = $this->paidOrder();
        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        return $orderId;
    }

    private function failedOrder(): string
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'Payment declined'));

        return $orderId;
    }

    private function tickets(): array
    {
        return [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-1', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']];
    }

    private function orderDetail(string $orderId): array
    {
        return $this->queryBus()->sendWithRouting('order.detail', $orderId);
    }

    private function commandBus(): CommandBus
    {
        return $this->app->make(CommandBus::class);
    }

    private function queryBus(): QueryBus
    {
        return $this->app->make(QueryBus::class);
    }
}
