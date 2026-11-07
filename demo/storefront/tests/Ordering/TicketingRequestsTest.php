<?php

declare(strict_types=1);

namespace Tests\Ordering;

use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\ReleaseSeat;
use App\Ordering\RequestSeat;
use DomainException;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @internal
 */
final class TicketingRequestsTest extends StorefrontFlowTestCase
{
    protected function storefrontServices(): array
    {
        $commitFailure = new class () {
            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, pointcut: CommandBus::class)]
            public function failBeforeCommit(MethodInvocation $invocation, #[Headers] array $headers): mixed
            {
                $result = $invocation->proceed();
                if (isset($headers['failBeforeCommit'])) {
                    throw new RuntimeException('the commit failed');
                }

                return $result;
            }
        };

        return [$commitFailure::class];
    }

    public function test_a_paid_order_whose_commit_fails_sends_nothing_to_ticketing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        try {
            $this->storefront->getCommandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'), metadata: ['failBeforeCommit' => true]);
            self::fail('MarkPaid committed');
        } catch (RuntimeException $failure) {
            self::assertSame('the commit failed', $failure->getMessage());
        }

        self::assertSame('paying', $this->order($orderId)['status']);
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_a_paid_order_reaches_ticketing_once_its_commit_is_followed_by_storefront_async(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        $this->storefront->getCommandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));
        $this->ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $this->ticketingService->received);
        self::assertSame(['tickets.confirm'], array_column($this->ticketingReceives(), 0));
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_requesting_a_seat_sends_tickets_hold_to_ticketing_with_the_concert_and_order_headers(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));

        self::assertSame(
            [['tickets.hold', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $customerId, 'holdId' => 'hold-1'], self::CONCERT_ID, $orderId]],
            $this->ticketingReceives(),
        );
    }

    public function test_two_seats_requested_at_once_are_asked_of_ticketing_one_after_the_other(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-2'));

        self::assertSame(
            [['tickets.hold', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $customerId, 'holdId' => 'hold-1'], self::CONCERT_ID, $orderId]],
            $this->ticketingReceives(),
        );
        self::assertSame([], $this->ticketingReceives());
        $this->storefrontConsumes();
        self::assertSame(
            [['tickets.hold', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-11', 'customerId' => $customerId, 'holdId' => 'hold-2'], self::CONCERT_ID, $orderId]],
            $this->ticketingReceives(),
        );
        $this->storefrontConsumes();
        self::assertSame([self::CONCERT_ID . '/A-12', self::CONCERT_ID . '/A-11'], array_column($this->order($orderId)['heldSeats'], 'seatId'));
    }

    public function test_a_refusal_of_the_first_requested_seat_still_asks_ticketing_for_the_next_one(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->ticketingService->holdsToRefuse = 1;

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-2'));

        self::assertSame([[self::CONCERT_ID . '/A-12', 'hold-1']], $this->holdsTicketingReceives());
        $this->storefrontConsumes();
        self::assertSame([[self::CONCERT_ID . '/A-11', 'hold-2']], $this->holdsTicketingReceives());
        $this->storefrontConsumes();
        self::assertSame([self::CONCERT_ID . '/A-11'], array_column($this->order($orderId)['heldSeats'], 'seatId'));
        self::assertSame(self::CONCERT_ID . '/A-12', $this->order($orderId)['lastRefusal']['seatId']);
    }

    public function test_releasing_a_seat_still_waiting_for_its_turn_never_asks_ticketing_to_hold_it(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-2'));

        $this->storefront->getCommandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11'));

        self::assertSame([[self::CONCERT_ID . '/A-12', 'hold-1']], $this->holdsTicketingReceives());
        $this->storefrontConsumes();
        self::assertSame([], $this->ticketingReceives());
        self::assertSame([self::CONCERT_ID . '/A-12'], array_column($this->order($orderId)['heldSeats'], 'seatId'));
    }

    public function test_releasing_the_seat_ticketing_is_answering_asks_for_the_next_one_only_after_the_reply(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-2'));

        $this->storefront->getCommandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));

        self::assertSame([[self::CONCERT_ID . '/A-12', 'hold-1']], $this->holdsTicketingReceives());
        $this->storefrontConsumes();
        self::assertSame(
            [['tickets.release', self::CONCERT_ID . '/A-12', 'hold-1'], ['tickets.hold', self::CONCERT_ID . '/A-11', 'hold-2']],
            array_map(fn (array $received) => [$received[0], $received[1]['seatId'], $received[1]['holdId']], $this->ticketingReceives()),
        );
    }

    public function test_a_seat_requested_while_another_is_asked_is_dropped_without_a_hold_once_payment_began(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-10');
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-2'));
        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-3'));
        self::assertSame([[self::CONCERT_ID . '/A-12', 'hold-2']], $this->holdsTicketingReceives());

        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));
        $this->storefrontConsumes();

        self::assertSame(
            [['tickets.release', self::CONCERT_ID . '/A-12', 'hold-2']],
            array_map(fn (array $received) => [$received[0], $received[1]['seatId'], $received[1]['holdId']], $this->ticketingReceives()),
        );
        self::assertSame([], $this->order($orderId)['askingSeatIds']);
    }

    public function test_requesting_a_seat_the_order_already_holds_is_refused(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s already holds seat %s/A-12, so RequestSeat does not ask ticketing for it again. Pick another seat, or release this one with ReleaseSeat.', $orderId, self::CONCERT_ID));

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
    }

    public function test_requesting_a_seat_whose_hold_expired_before_its_expiry_reached_the_order_asks_ticketing_to_hold_it_again(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        Carbon::setTestNow('2026-10-25 12:03:00');
        $this->ticketingService->holdReplies[] = ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $customerId, 'holdId' => 'hold-2', 'outcome' => 'held', 'reason' => null, 'reasonCode' => null, 'expiresAt' => '2026-10-25T12:05:00+00:00'];

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-2'));

        self::assertSame([['tickets.hold', self::CONCERT_ID . '/A-12', 'hold-2']], array_map(fn (array $request) => [$request[0], $request[1]['seatId'], $request[1]['holdId']], $this->ticketingReceives()));
        $this->storefrontConsumes();
        self::assertSame([['seatId' => self::CONCERT_ID . '/A-12', 'expiresAt' => '2026-10-25T12:05:00+00:00']], $this->order($orderId)['heldSeats']);
    }

    public function test_requesting_a_seat_once_payment_began_is_refused_and_sends_nothing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        try {
            $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-11', holdId: 'hold-1'));
            self::fail('RequestSeat was accepted');
        } catch (DomainException $refusal) {
            self::assertSame(sprintf('Order %s is paying, so RequestSeat cannot be applied to it. RequestSeat is accepted only while the order is started or seats_held.', $orderId), $refusal->getMessage());
        }
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_requesting_a_seat_of_another_concert_is_refused(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $otherConcertId = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e41';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Seat %s/A-12 belongs to concert %s, but order %s is for concert %s, so RequestSeat cannot add it.', $otherConcertId, $otherConcertId, $orderId, self::CONCERT_ID));

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: $otherConcertId . '/A-12', holdId: 'hold-1'));
    }

    public function test_releasing_a_held_seat_sends_tickets_release_to_ticketing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');

        $this->storefront->getCommandBus()->send(new ReleaseSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12'));

        self::assertSame(
            [['tickets.release', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $customerId, 'holdId' => 'hold-1'], self::CONCERT_ID, $orderId]],
            $this->ticketingReceives(),
        );
    }

    public function test_a_paid_order_asks_ticketing_to_confirm_every_held_seat_with_the_payment_reference(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-11');
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId, confirmationEmailBounces: false));

        $this->storefront->getCommandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

        self::assertSame(
            [['tickets.confirm', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'customerId' => $customerId, 'seatIds' => [self::CONCERT_ID . '/A-12', self::CONCERT_ID . '/A-11'], 'paymentReference' => 'pay_123'], self::CONCERT_ID, $orderId]],
            $this->ticketingReceives(),
        );
    }

    public function test_a_failed_order_releases_every_held_seat_in_ticketing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $customerId = $this->order($orderId)['customerId'];
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-11');

        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'The card was declined'));

        self::assertSame(
            [
                ['tickets.release', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-12', 'customerId' => $customerId, 'holdId' => 'hold-1'], self::CONCERT_ID, $orderId],
                ['tickets.release', ['orderId' => $orderId, 'concertId' => self::CONCERT_ID, 'seatId' => self::CONCERT_ID . '/A-11', 'customerId' => $customerId, 'holdId' => 'hold-1'], self::CONCERT_ID, $orderId],
            ],
            $this->ticketingReceives(),
        );
    }

    public function test_a_failed_order_with_no_held_seats_sends_nothing_to_ticketing(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');

        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'The card was declined'));

        self::assertSame([], $this->ticketingReceives());
    }

    private function holdsTicketingReceives(): array
    {
        return array_values(array_map(fn (array $received) => [$received[1]['seatId'], $received[1]['holdId']], array_filter($this->ticketingReceives(), fn (array $received) => $received[0] === 'tickets.hold')));
    }
}
