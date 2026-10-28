<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\EmailSender;
use App\Notification\OrderConfirmation;
use App\Notification\SentEmails;
use App\Ordering\BeginPayment;
use App\Ordering\MarkPaid;
use App\Ordering\MarkTicketsIssued;
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
    }

    protected function storefrontServices(): array
    {
        return [OrderConfirmation::class, EmailSender::class, SentEmails::class];
    }

    public function test_issued_tickets_send_the_confirmation_email_with_every_entry_code_once_storefront_async_runs(): void
    {
        $orderId = $this->paidOrderOf('Ada Lovelace', 'ada@example.com');

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

    public function test_a_confirmation_email_to_a_bounce_example_address_fails_naming_the_address_and_the_way_out(): void
    {
        $orderId = $this->paidOrderOf('Linus Bounce', 'linus@bounce.example');
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('The confirmation email for order %s bounced: linus@bounce.example is at bounce.example, which accepts no mail, so the customer has not received their entry codes. Replaying this message bounces again. Use another address: register the customer with an address outside bounce.example and buy the tickets as that customer, then delete this message from the dead letters in Ecotone Pulse.', $orderId));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_bounced_confirmation_email_lands_in_the_dead_letter_and_records_no_sent_email(): void
    {
        $orderId = $this->paidOrderOf('Linus Bounce', 'linus@bounce.example');
        $this->storefront->getCommandBus()->send(new MarkTicketsIssued(orderId: $orderId, tickets: $this->tickets()));

        $this->storefront->run('storefront_async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame(1, $this->storefront->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $this->sentEmails());
        self::assertSame('tickets_issued', $this->order($orderId)['status']);
    }

    private function paidOrderOf(string $name, string $email): string
    {
        $orderId = $this->startOrderOf($name, $email);
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-12');
        $this->seatIsHeld($orderId, self::CONCERT_ID . '/A-11');
        $this->storefront->getCommandBus()->send(new BeginPayment(orderId: $orderId));
        $this->storefront->getCommandBus()->send(new MarkPaid(orderId: $orderId, paymentReference: 'pay_123'));

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
