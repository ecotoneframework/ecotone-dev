<?php

declare(strict_types=1);

namespace Tests\Payment;

use App\Ordering\FailOrder;
use App\Ordering\Order;
use App\Payment\ChargeCard;
use App\Payment\Checkout;
use App\Payment\Pay;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentOutcome;
use App\Payment\PaymentProvider;
use Doctrine\DBAL\Driver\AbstractException;
use DomainException;
use Doctrine\DBAL\Exception\DeadlockException;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Ordering\StorefrontFlowTestCase;

/**
 * @internal
 */
final class PaymentTest extends StorefrontFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('payment_attempts')->delete();
    }

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

        $deadlockOnFirstMarkPaid = new class () {
            public bool $deadlocked = false;

            #[Around(pointcut: Order::class . '::markPaid')]
            public function deadlockOnce(MethodInvocation $invocation): mixed
            {
                if (! $this->deadlocked) {
                    $this->deadlocked = true;

                    throw new DeadlockException(new class ('deadlock detected') extends AbstractException {}, null);
                }

                return $invocation->proceed();
            }
        };

        return [Checkout::class, PaymentProvider::class, PaymentAttempts::class, $commitFailure::class, $deadlockOnFirstMarkPaid::class];
    }

    public function test_paying_moves_the_order_to_paying_until_the_card_is_charged(): void
    {
        $orderId = $this->orderWithHeldSeat();

        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));

        self::assertSame('paying', $this->order($orderId)['status']);
    }

    public function test_paying_for_an_order_whose_seat_hold_expired_is_refused_before_the_card_is_charged(): void
    {
        $orderId = $this->orderWithHeldSeat();
        Carbon::setTestNow('2026-10-25 12:02:00');

        try {
            $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));
            self::fail('Pay was not refused');
        } catch (DomainException $refusal) {
            self::assertSame(sprintf('The hold of seat A-12 of order %s expired, so BeginPayment does not charge the card: ticketing refuses to confirm a seat whose hold expired, and the payment would only be refunded. Request the seat again with RequestSeat, or release it with ReleaseSeat, then pay.', $orderId), $refusal->getMessage());
        }
        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('seats_held', $order['status']);
        self::assertNull($order['paymentReference']);
    }

    public function test_a_charged_card_marks_the_order_paid_and_asks_ticketing_to_confirm_its_seats(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));

        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('paid', $order['status']);
        self::assertSame('charged', $this->storefront->sendQueryWithRouting('payment.status', $order['paymentReference']));
        $received = $this->ticketingReceives();
        self::assertSame('tickets.confirm', $received[0][0]);
        self::assertSame($order['paymentReference'], $received[0][1]['paymentReference']);
    }

    public function test_a_charge_retried_after_a_deadlock_charges_the_card_once(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));

        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('paid', $order['status']);
        self::assertSame(1, DB::table('payment_attempts')->where('payment_reference', $order['paymentReference'])->value('attempts'));
        self::assertSame(['tickets.confirm'], array_column($this->ticketingReceives(), 0));
    }

    public function test_a_charge_card_replayed_for_a_paid_order_charges_nothing_and_confirms_nothing_again(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));
        $this->charge();
        $this->ticketingReceives();
        $paymentReference = $this->order($orderId)['paymentReference'];

        $this->storefront->getCommandBus()->send(new ChargeCard(orderId: $orderId, paymentReference: $paymentReference, outcome: PaymentOutcome::SUCCEEDS));
        $this->charge();

        self::assertSame('paid', $this->order($orderId)['status']);
        self::assertSame(1, DB::table('payment_attempts')->where('payment_reference', $paymentReference)->value('attempts'));
        self::assertSame([], $this->ticketingReceives());
    }

    public function test_choosing_a_declined_card_fails_the_order_releasing_its_seats(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::DECLINED, confirmationEmailBounces: false));

        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertSame('The card was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.', $order['failureReason']);
        self::assertSame([['tickets.release', self::CONCERT_ID . '/A-12']], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_choosing_a_provider_outage_fails_the_first_charge_naming_the_replay_from_pulse(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::PROVIDER_OUTAGE, confirmationEmailBounces: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(sprintf('/^The payment provider is unavailable, so payment pay_[0-9a-f-]{36} of order %s was not charged\. It is a transient outage: replay this ChargeCard message from the dead letters in Ecotone Pulse, or run php artisan ecotone:deadletter:replay <messageId> in the storefront, and the card is charged again\.$/', $orderId));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }

    public function test_a_charge_hit_by_the_provider_outage_replayed_from_the_dead_letter_pays_the_order_and_asks_ticketing_to_confirm(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::PROVIDER_OUTAGE, confirmationEmailBounces: false));
        $deadLetter = $this->storefront->getGateway(DeadLetterGateway::class);

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame(1, $deadLetter->count());
        self::assertSame('paying', $this->order($orderId)['status']);
        self::assertSame([], $this->ticketingReceives());

        $deadLetter->replayAll();
        $this->charge();

        self::assertSame(0, $deadLetter->count());
        self::assertSame('paid', $this->order($orderId)['status']);
        self::assertSame(['tickets.confirm'], array_column($this->ticketingReceives(), 0));
    }

    public function test_a_paid_order_that_fails_is_refunded(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));
        $this->charge();

        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'someone was faster: seat A-12'));
        $this->storefrontAsyncRuns();

        self::assertSame('refunded', $this->storefront->sendQueryWithRouting('payment.status', $this->order($orderId)['paymentReference']));
    }

    public function test_a_paid_order_whose_failure_does_not_commit_is_not_refunded(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: false));
        $this->charge();

        try {
            $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'someone was faster: seat A-12'), metadata: ['failBeforeCommit' => true]);
            self::fail('FailOrder committed');
        } catch (RuntimeException $failure) {
            self::assertSame('the commit failed', $failure->getMessage());
        }
        $this->storefrontAsyncRuns();

        $order = $this->order($orderId);
        self::assertSame('paid', $order['status']);
        self::assertSame('charged', $this->storefront->sendQueryWithRouting('payment.status', $order['paymentReference']));
    }

    public function test_a_payment_reference_never_charged_has_no_status(): void
    {
        self::assertNull($this->storefront->sendQueryWithRouting('payment.status', 'pay_0199f3a8-6f2a-7c1e-9a51-000000000000'));
    }

    private function orderWithHeldSeat(): string
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');

        return $orderId;
    }

    private function charge(): void
    {
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
    }
}
