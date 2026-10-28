<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\EmailSender;
use App\Notification\OrderConfirmation;
use App\Notification\SentEmails;
use App\Ordering\MarkTicketsIssued;
use App\Payment\Checkout;
use App\Payment\Pay;
use App\Payment\PaymentAttempts;
use App\Payment\PaymentOutcome;
use App\Payment\PaymentProvider;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Ordering\StorefrontFlowTestCase;

/**
 * @internal
 */
final class OrderConfirmationEmailTest extends StorefrontFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('sent_emails')->delete();
        DB::table('payment_attempts')->delete();
    }

    protected function storefrontServices(): array
    {
        return [OrderConfirmation::class, EmailSender::class, SentEmails::class, Checkout::class, PaymentProvider::class, PaymentAttempts::class];
    }

    public function test_issued_tickets_send_the_confirmation_email_with_every_entry_code_once_storefront_async_runs(): void
    {
        $orderId = $this->paidOrderOf('Ada Lovelace', 'ada@example.com', confirmationEmailBounces: false);

        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        self::assertSame([], $this->sentEmails());
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertSame(
            [[
                'order_id' => $orderId,
                'recipient' => 'ada@example.com',
                'subject' => 'Your tickets for order ' . $orderId,
                'body' => "Hello Ada Lovelace,\n\nyour tickets are ready:\n\nT-0001  seat A-12 (vip)  entry code ENTRY-7F3A\nT-0002  seat A-11 (vip)  entry code ENTRY-9C21\n\nShow the entry code at the door.",
            ]],
            $this->sentEmails(),
        );
    }

    public function test_a_confirmation_email_chosen_to_bounce_at_checkout_fails_naming_the_recipient_and_the_way_out(): void
    {
        $orderId = $this->paidOrderOf('Linus Pauling', 'linus@example.com', confirmationEmailBounces: true);
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->bounceMessageOf($orderId));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_bounced_confirmation_email_lands_in_the_dead_letter_and_records_no_sent_email(): void
    {
        $orderId = $this->paidOrderOf('Linus Pauling', 'linus@example.com', confirmationEmailBounces: true);
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame(1, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $this->sentEmails());
        self::assertSame('tickets_issued', $this->order($orderId)['status']);
    }

    public function test_a_bounced_confirmation_email_leaves_the_entry_codes_on_the_order_page_and_its_replay_bounces_again(): void
    {
        $orderId = $this->paidOrderOf('Linus Pauling', 'linus@example.com', confirmationEmailBounces: true);
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $orderPage = $this->withCookie('storefront_customer', $this->order($orderId)['customerId'])->get('/orders/' . $orderId);
        $deadLetters = $this->storefront->getGateway(DeadLetterGateway::class);
        $deadLetters->replayAll();
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $orderPage->assertSeeInOrder(['tickets_issued', 'T-0001', 'ENTRY-7F3A', 'T-0002', 'ENTRY-9C21']);
        self::assertSame(1, $deadLetters->count());
        self::assertStringContainsString($this->bounceMessageOf($orderId), $deadLetters->list(1, 0)[0]->getMessage());
        self::assertSame([], $this->sentEmails());
    }

    private function bounceMessageOf(string $orderId): string
    {
        return sprintf('The confirmation email for order %s to linus@example.com bounced, because the buyer chose at checkout that it bounces, so the customer has not received their entry codes by email. Replaying this message bounces again: the choice stays with the order. The tickets and their entry codes are on the order page, /orders/%s in the storefront: hand them to the customer from there, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>. To email the next order, leave "The confirmation email bounces" unticked at checkout.', $orderId, $orderId);
    }

    private function paidOrderOf(string $name, string $email, bool $confirmationEmailBounces): string
    {
        $orderId = $this->startOrderOf($name, $email);
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-11');
        $this->storefront->getCommandBus()->send(new Pay(orderId: $orderId, outcome: PaymentOutcome::SUCCEEDS, confirmationEmailBounces: $confirmationEmailBounces));
        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        return $orderId;
    }

    private function tickets(): array
    {
        return [
            ['ticketNumber' => 'T-0001', 'seatId' => self::CONCERT_ID . '/A-12', 'tier' => 'vip', 'entryCode' => 'ENTRY-7F3A'],
            ['ticketNumber' => 'T-0002', 'seatId' => self::CONCERT_ID . '/A-11', 'tier' => 'vip', 'entryCode' => 'ENTRY-9C21'],
        ];
    }

    private function sentEmails(): array
    {
        return array_map(
            fn (array $email) => array_intersect_key($email, array_flip(['order_id', 'recipient', 'subject', 'body'])),
            $this->storefront->getGateway(SentEmails::class)->all(),
        );
    }
}
