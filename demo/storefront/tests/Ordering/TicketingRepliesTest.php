<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\MarkSeatHeld;
use App\Ordering\ReleaseSeat;
use App\Ordering\RequestSeat;
use App\Ordering\TicketingReplies;
use App\Payment\Checkout;
use App\Payment\Pay;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentProvider;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\DistributedBus;
use Illuminate\Support\Facades\DB;

/**
 * @internal
 */
final class TicketingRepliesTest extends StorefrontFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('payment_attempts')->delete();
    }

    protected function storefrontServices(): array
    {
        return [TicketingReplies::class, Checkout::class, PaymentProvider::class, PaymentAttempts::class];
    }

    public function test_a_held_reply_marks_the_requested_seat_held_until_its_expiry(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));

        $this->ticketingReplies();

        $order = $this->order($orderId);
        self::assertSame('seats_held', $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-12', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
    }

    public function test_a_refused_reply_records_the_reason_for_the_requested_seat(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));
        $this->ticketingService->holdReplies[] = $this->holdReply($orderId, outcome: 'refused', reason: 'someone was faster: seat A-12', expiresAt: null);

        $this->ticketingReplies();

        $order = $this->order($orderId);
        self::assertSame('started', $order['status']);
        self::assertSame([], $order['heldSeats']);
        self::assertSame(['seatId' => self::CONCERT_ID . '/A-12', 'reason' => 'someone was faster: seat A-12'], $order['lastRefusal']);
    }

    public function test_a_hold_reply_with_an_outcome_outside_the_contract_is_refused_naming_the_outcomes_it_has(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));
        $this->ticketingService->holdReplies[] = $this->holdReply($orderId, outcome: 'pending', reason: null, expiresAt: null);
        $this->ticketingReceives();

        $this->expectExceptionMessage(sprintf('tickets.hold.result for seat %s/A-12 of order %s has outcome "pending", but the contract has only "held" and "refused", so the storefront cannot tell whether the seat is the customer\'s. Correct the reply in ticketing and replay this message from the dead letters in Ecotone Pulse.', self::CONCERT_ID, $orderId));

        $this->storefrontConsumes();
    }

    public function test_a_sold_confirmation_leaves_the_order_paid_waiting_for_its_tickets(): void
    {
        $orderId = $this->paidOrder();

        $this->ticketingReplies();

        self::assertSame('paid', $this->order($orderId)['status']);
    }

    public function test_a_refused_confirmation_fails_the_order_with_the_reason_refunds_it_and_releases_its_seats(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4242424242424242'));
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
        $this->ticketingService->confirmReplies[] = ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $this->order($orderId)['customerId'], 'outcome' => 'refused', 'reason' => 'the hold of seat A-12 expired', 'seatIds' => [self::CONCERT_ID . '/A-12']];

        $this->ticketingReplies();
        $this->storefrontAsyncRuns();

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertSame('Ticketing refused to confirm the seats: the hold of seat A-12 expired. The payment is refunded.', $order['failureReason']);
        self::assertSame('refunded', $this->storefront->sendQueryWithRouting('payment.status', $order['paymentReference']));
        self::assertSame([['tickets.release', self::CONCERT_ID . '/A-12']], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_an_expired_hold_published_by_ticketing_drops_the_seat_from_the_order(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', expiresAt: self::EXPIRES_AT));

        $this->publishFromTicketing('tickets.hold_expired', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $this->order($orderId)['customerId']]);

        self::assertSame([['seatId' => self::CONCERT_ID . '/A-11', 'expiresAt' => self::EXPIRES_AT]], $this->order($orderId)['heldSeats']);
    }

    public function test_tickets_issued_by_ticketing_move_the_paid_order_to_tickets_issued_with_their_entry_codes(): void
    {
        $orderId = $this->paidOrder();
        $tickets = [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']];

        $this->publishFromTicketing('tickets.issued', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $this->order($orderId)['customerId'], 'tickets' => $tickets]);

        $order = $this->order($orderId);
        self::assertSame('tickets_issued', $order['status']);
        self::assertSame($tickets, $order['tickets']);
    }

    public function test_tickets_issued_twice_keep_the_tickets_and_dead_letter_nothing(): void
    {
        $orderId = $this->paidOrder();
        $tickets = [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']];
        $issued = ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $this->order($orderId)['customerId'], 'tickets' => $tickets];

        $this->publishFromTicketing('tickets.issued', $issued);
        $this->publishFromTicketing('tickets.issued', $issued);

        $order = $this->order($orderId);
        self::assertSame('tickets_issued', $order['status']);
        self::assertSame($tickets, $order['tickets']);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_tickets_issued_again_with_other_tickets_is_refused_naming_both_sets_and_the_way_out(): void
    {
        $orderId = $this->paidOrder();
        $customerId = $this->order($orderId)['customerId'];
        $this->publishFromTicketing('tickets.issued', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $customerId, 'tickets' => [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']]]);

        $this->expectExceptionMessage(sprintf('Order %s already has tickets T-0001 issued, but this tickets.issued carries tickets T-0002, so the storefront cannot tell which ones the customer holds. Keep the issued ones by deleting this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>, and void tickets T-0002 in ticketing.', $orderId));

        $this->publishFromTicketing('tickets.issued', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $customerId, 'tickets' => [['ticketNumber' => 'T-0002', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-9B1C']]]);
    }

    public function test_tickets_issued_for_a_failed_order_are_refused_naming_the_refund_and_the_way_out(): void
    {
        $orderId = $this->paidOrder();
        $customerId = $this->order($orderId)['customerId'];
        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'the hold of seat A-12 expired'));

        $this->expectExceptionMessage(sprintf('Order %s failed (the hold of seat A-12 expired) and its payment is refunded, so tickets T-0001 that ticketing issued for it are not recorded. Void tickets T-0001 in ticketing, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>.', $orderId));

        $this->publishFromTicketing('tickets.issued', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $customerId, 'tickets' => [['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A']]]);
    }

    public function test_a_held_reply_arriving_after_payment_began_releases_the_seat_in_ticketing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11'));
        $this->ticketingReceives();
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId));

        $this->storefrontConsumes();

        $order = $this->order($orderId);
        self::assertSame('paying', $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-12', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
        self::assertSame([['tickets.release', self::CONCERT_ID . '/A-11']], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_a_held_reply_repeated_for_a_seat_the_paying_order_holds_keeps_it_and_releases_nothing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));
        $this->ticketingReplies();
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId));

        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));

        $order = $this->order($orderId);
        self::assertSame('paying', $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-12', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_a_refused_reply_arriving_after_payment_began_changes_nothing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11'));
        $this->ticketingService->holdReplies[] = [...$this->holdReply($orderId, outcome: 'refused', reason: 'someone was faster: seat A-11', expiresAt: null), 'seatId' => self::CONCERT_ID . '/A-11'];
        $this->ticketingReceives();
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId));

        $this->storefrontConsumes();

        $order = $this->order($orderId);
        self::assertSame('paying', $order['status']);
        self::assertNull($order['lastRefusal']);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_expired_hold_of_a_seat_already_released_changes_nothing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));

        $this->publishFromTicketing('tickets.hold_expired', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $this->order($orderId)['customerId']]);

        $order = $this->order($orderId);
        self::assertSame('seats_held', $order['status']);
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-11', 'expiresAt' => self::EXPIRES_AT]], $order['heldSeats']);
        self::assertSame(0, $this->storefront->getGateway(DeadLetterGateway::class)->count());
    }

    private function paidOrder(): string
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId));
        $this->storefront->getCommandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

        return $orderId;
    }

    private function holdReply(string $orderId, string $outcome, ?string $reason, ?string $expiresAt): array
    {
        return [
            'orderId' => $orderId,
            'concertId' => self::CONCERT_ID,
            'seatId' => self::CONCERT_ID . '/A-12',
            'customerId' => $this->order($orderId)['customerId'],
            'outcome' => $outcome,
            'reason' => $reason,
            'expiresAt' => $expiresAt,
        ];
    }

    private function publishFromTicketing(string $routingKey, array $payload): void
    {
        $this->ticketing->getGateway(DistributedBus::class)->convertAndPublishEvent($routingKey, $payload, metadata: ['concertId' => $payload['concertId']]);
        $this->storefrontConsumes();
    }
}
