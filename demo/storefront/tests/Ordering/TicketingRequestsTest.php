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

    public function test_requesting_a_seat_the_order_already_holds_is_refused(): void
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(sprintf('Order %s already holds seat %s/A-12, so RequestSeat does not ask ticketing for it again. Pick another seat, or release this one with ReleaseSeat.', $orderId, self::CONCERT_ID));

        $this->storefront->getCommandBus()->send(new RequestSeat(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', holdId: 'hold-1'));
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
}
