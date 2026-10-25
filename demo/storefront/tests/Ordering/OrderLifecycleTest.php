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
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Support\Facades\DB;
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

    public function test_payment_cannot_begin_on_an_order_with_no_held_seats(): void
    {
        $orderId = $this->startOrder();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s has no held seats, so payment cannot begin. Hold at least one seat with MarkSeatsHeld before sending BeginPayment.', $orderId));

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
