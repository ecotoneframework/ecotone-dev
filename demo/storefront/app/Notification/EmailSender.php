<?php

declare(strict_types=1);

namespace App\Notification;

final class EmailSender
{
    public function __construct(private readonly SentEmails $sentEmails)
    {
    }

    public function sendOrderConfirmation(string $orderId, string $recipient, string $subject, string $body, EmailOutcome $outcome): void
    {
        if ($outcome === EmailOutcome::BOUNCES) {
            throw new EmailBounced(sprintf(
                'The confirmation email for order %s to %s bounced, because the buyer chose at checkout that it bounces, so the customer has not received their entry codes by email. Replaying this message bounces again: the choice stays with the order. The tickets and their entry codes are on the order page, /orders/%s in the storefront: hand them to the customer from there, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>. To email the next order, leave "The confirmation email bounces" unticked at checkout.',
                $orderId,
                $recipient,
                $orderId,
            ));
        }
        $this->sentEmails->record(orderId: $orderId, recipient: $recipient, subject: $subject, body: $body);
    }
}
