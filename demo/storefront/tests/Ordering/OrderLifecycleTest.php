<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Customer\RegisterCustomer;
use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\MarkSeatsHeld;
use App\Ordering\MarkTicketsIssued;
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

    public function test_held_seats_move_the_order_to_seats_held(): void
    {
        $orderId = $this->startOrder();

        $this->commandBus()->send(new MarkSeatsHeld(orderId: $orderId, seatIds: [self::CONCERT_ID . '/A-1', self::CONCERT_ID . '/A-2']));

        $order = $this->orderDetail($orderId);
        self::assertSame('seats_held', $order['status']);
        self::assertSame([self::CONCERT_ID . '/A-1', self::CONCERT_ID . '/A-2'], $order['seatIds']);
    }

    public function test_seats_held_later_are_added_to_the_seats_already_held(): void
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new MarkSeatsHeld(orderId: $orderId, seatIds: [self::CONCERT_ID . '/A-1']));

        $this->commandBus()->send(new MarkSeatsHeld(orderId: $orderId, seatIds: [self::CONCERT_ID . '/A-1', self::CONCERT_ID . '/A-2']));

        self::assertSame([self::CONCERT_ID . '/A-1', self::CONCERT_ID . '/A-2'], $this->orderDetail($orderId)['seatIds']);
    }

    public function test_beginning_payment_moves_an_order_with_held_seats_to_paying(): void
    {
        $orderId = $this->orderWithHeldSeats();

        $this->commandBus()->send(new BeginPayment(orderId: $orderId));

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

    public function test_marking_tickets_issued_moves_a_paid_order_to_tickets_issued(): void
    {
        $orderId = $this->paidOrder();

        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId));

        self::assertSame('tickets_issued', $this->orderDetail($orderId)['status']);
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
                'seatIds' => [],
                'paymentReference' => null,
                'failureReason' => null,
            ],
            $this->orderDetail($orderId),
        );
    }

    public function test_holding_no_seats_is_refused(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('MarkSeatsHeld for order %s names no seats, so it cannot move the order to seats_held. Send it with at least one seat id of concert %s, e.g. "%s/A-1".', $orderId, self::CONCERT_ID, self::CONCERT_ID));

        $this->commandBus()->send(new MarkSeatsHeld(orderId: $orderId, seatIds: []));
    }

    public function test_payment_cannot_begin_on_an_order_with_no_held_seats(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s is started, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.', $orderId));

        $this->commandBus()->send(new BeginPayment(orderId: $orderId));
    }

    public function test_tickets_cannot_be_issued_for_an_unpaid_order(): void
    {
        $orderId = $this->payingOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s is paying, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.', $orderId));

        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId));
    }

    public function test_an_order_with_issued_tickets_cannot_fail(): void
    {
        $orderId = $this->paidOrder();
        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s already has its tickets issued, so it cannot fail: an order with issued tickets is final. Send FailOrder only for an order that has not reached tickets_issued.', $orderId));

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
        $markSeatsHeld = fn (string $orderId) => new MarkSeatsHeld(orderId: $orderId, seatIds: [self::CONCERT_ID . '/A-2']);
        $beginPayment = fn (string $orderId) => new BeginPayment(orderId: $orderId);
        $markPaid = fn (string $orderId) => new MarkPaid(orderId: $orderId, paymentReference: 'pay_456');
        $markTicketsIssued = fn (string $orderId) => new MarkTicketsIssued(orderId: $orderId);
        $failOrder = fn (string $orderId) => new FailOrder(orderId: $orderId, reason: 'Too late');

        yield 'BeginPayment on a started order' => ['started', $beginPayment, 'Order %s is started, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a started order' => ['started', $markPaid, 'Order %s is started, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a started order' => ['started', $markTicketsIssued, 'Order %s is started, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.'];
        yield 'MarkPaid on a seats_held order' => ['seats_held', $markPaid, 'Order %s is seats_held, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a seats_held order' => ['seats_held', $markTicketsIssued, 'Order %s is seats_held, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.'];
        yield 'MarkSeatsHeld on a paying order' => ['paying', $markSeatsHeld, 'Order %s is paying, so MarkSeatsHeld cannot be applied to it. MarkSeatsHeld is accepted only while the order is started or seats_held.'];
        yield 'BeginPayment on a paying order' => ['paying', $beginPayment, 'Order %s is paying, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkTicketsIssued on a paying order' => ['paying', $markTicketsIssued, 'Order %s is paying, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.'];
        yield 'MarkSeatsHeld on a paid order' => ['paid', $markSeatsHeld, 'Order %s is paid, so MarkSeatsHeld cannot be applied to it. MarkSeatsHeld is accepted only while the order is started or seats_held.'];
        yield 'BeginPayment on a paid order' => ['paid', $beginPayment, 'Order %s is paid, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a paid order' => ['paid', $markPaid, 'Order %s is paid, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkSeatsHeld on a tickets_issued order' => ['tickets_issued', $markSeatsHeld, 'Order %s is tickets_issued, so MarkSeatsHeld cannot be applied to it. MarkSeatsHeld is accepted only while the order is started or seats_held.'];
        yield 'BeginPayment on a tickets_issued order' => ['tickets_issued', $beginPayment, 'Order %s is tickets_issued, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a tickets_issued order' => ['tickets_issued', $markPaid, 'Order %s is tickets_issued, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a tickets_issued order' => ['tickets_issued', $markTicketsIssued, 'Order %s is tickets_issued, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.'];
        yield 'FailOrder on a tickets_issued order' => ['tickets_issued', $failOrder, 'Order %s already has its tickets issued, so it cannot fail: an order with issued tickets is final. Send FailOrder only for an order that has not reached tickets_issued.'];
        yield 'MarkSeatsHeld on a failed order' => ['failed', $markSeatsHeld, 'Order %s is failed, so MarkSeatsHeld cannot be applied to it. MarkSeatsHeld is accepted only while the order is started or seats_held.'];
        yield 'BeginPayment on a failed order' => ['failed', $beginPayment, 'Order %s is failed, so BeginPayment cannot be applied to it. BeginPayment is accepted only while the order is seats_held.'];
        yield 'MarkPaid on a failed order' => ['failed', $markPaid, 'Order %s is failed, so MarkPaid cannot be applied to it. MarkPaid is accepted only while the order is paying.'];
        yield 'MarkTicketsIssued on a failed order' => ['failed', $markTicketsIssued, 'Order %s is failed, not paid, so its tickets cannot be issued. Send MarkPaid with the payment reference first.'];
        yield 'FailOrder on a failed order' => ['failed', $failOrder, 'Order %s is failed, so FailOrder cannot be applied to it. FailOrder is accepted only while the order is started or seats_held or paying or paid.'];
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
        $this->commandBus()->send(new MarkSeatsHeld(orderId: $orderId, seatIds: [self::CONCERT_ID . '/A-1']));

        return $orderId;
    }

    private function payingOrder(): string
    {
        $orderId = $this->orderWithHeldSeats();
        $this->commandBus()->send(new BeginPayment(orderId: $orderId));

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
        $this->commandBus()->send(new MarkTicketsIssued(orderId: $orderId));

        return $orderId;
    }

    private function failedOrder(): string
    {
        $orderId = $this->startOrder();
        $this->commandBus()->send(new FailOrder(orderId: $orderId, reason: 'Payment declined'));

        return $orderId;
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
