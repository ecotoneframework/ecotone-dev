<?php

declare(strict_types=1);

namespace Tests\Payment;

use App\Ordering\FailOrder;
use App\Ordering\MarkSeatHeld;
use App\Payment\Checkout;
use App\Payment\Pay;
use App\Payment\PaymentProvider;
use DomainException;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
        return [Checkout::class, PaymentProvider::class];
    }

    public function test_paying_moves_the_order_to_paying_until_the_card_is_charged(): void
    {
        $orderId = $this->orderWithHeldSeat();

        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4242424242424242'));

        self::assertSame('paying', $this->order($orderId)['status']);
    }

    public function test_a_charged_card_marks_the_order_paid_and_asks_ticketing_to_confirm_its_seats(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4242424242424242'));

        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('paid', $order['status']);
        self::assertSame('charged', $this->storefront->sendQueryWithRouting('payment.status', $order['paymentReference']));
        $received = $this->ticketingReceives();
        self::assertSame('tickets.confirm', $received[0][0]);
        self::assertSame($order['paymentReference'], $received[0][1]['paymentReference']);
    }

    public function test_a_card_ending_0000_is_declined_and_fails_the_order_releasing_its_seats(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4000000000000000'));

        $this->charge();

        $order = $this->order($orderId);
        self::assertSame('failed', $order['status']);
        self::assertSame('The card ending 0000 was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.', $order['failureReason']);
        self::assertSame([['tickets.release', self::CONCERT_ID . '/A-12']], array_map(fn (array $request) => [$request[0], $request[1]['seatId']], $this->ticketingReceives()));
    }

    public function test_a_card_ending_9999_fails_its_first_charge_naming_the_card_and_the_replay_from_pulse(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4111111111119999'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(sprintf('/^The payment provider is unavailable, so payment pay_[0-9a-f-]{36} of order %s with the card ending 9999 was not charged\. It is a transient outage: replay this ChargeCard message from the dead letters in Ecotone Pulse, or run php artisan ecotone:deadletter:replay <messageId> in the storefront, and the card is charged again\.$/', $orderId));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_card_ending_9999_replayed_from_the_dead_letter_pays_the_order_and_asks_ticketing_to_confirm(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4111111111119999'));
        $deadLetter = $this->storefront->getGateway(DeadLetterGateway::class);

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame(1, $deadLetter->count());
        self::assertSame('paying', $this->order($orderId)['status']);
        self::assertSame([], $this->ticketingReceives());

        $deadLetter->replayAll();
        $this->charge();

        self::assertSame(0, $deadLetter->count());
        self::assertSame('paid', $this->order($orderId)['status']);
        self::assertSame(['tickets.confirm'], array_column($this->ticketingReceives(), 0));
    }

    #[DataProvider('cardNumbersThatAreNotCardNumbers')]
    public function test_a_card_number_that_is_not_12_to_19_digits_is_refused_and_leaves_the_order_unpaid(string $cardNumber): void
    {
        $orderId = $this->orderWithHeldSeat();

        try {
            $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: $cardNumber));
            self::fail('Pay was accepted');
        } catch (DomainException $refusal) {
            self::assertSame(sprintf('The card number for order %s is not 12 to 19 digits, so the card cannot be charged. Enter the number without spaces, like 4242424242424242.', $orderId), $refusal->getMessage());
        }
        self::assertSame('seats_held', $this->order($orderId)['status']);
    }

    public static function cardNumbersThatAreNotCardNumbers(): iterable
    {
        yield 'too short' => ['42424242424'];
        yield 'with spaces' => ['4242 4242 4242 4242'];
        yield 'with a trailing newline' => ["4242424242424242\n"];
    }

    public function test_a_paid_order_that_fails_is_refunded(): void
    {
        $orderId = $this->orderWithHeldSeat();
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, cardNumber: '4242424242424242'));
        $this->charge();

        $this->storefront->getCommandBus()->send(new FailOrder(orderId: $orderId, reason: 'someone was faster: seat A-12'));

        self::assertSame('refunded', $this->storefront->sendQueryWithRouting('payment.status', $this->order($orderId)['paymentReference']));
    }

    public function test_a_payment_reference_never_charged_has_no_status(): void
    {
        self::assertNull($this->storefront->sendQueryWithRouting('payment.status', 'pay_0199f3a8-6f2a-7c1e-9a51-000000000000'));
    }

    private function orderWithHeldSeat(): string
    {
        $orderId = $this->startOrderOf('Ada Lovelace', 'ada@example.com');
        $this->storefront->getCommandBus()->send(new MarkSeatHeld(orderId: $orderId, seatId: self::CONCERT_ID . '/A-12', expiresAt: self::EXPIRES_AT));

        return $orderId;
    }

    private function charge(): void
    {
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }
}
