<?php

declare(strict_types=1);

namespace App\Notification;

final class EmailSender
{
    private const BOUNCING_DOMAIN = '@bounce.example';

    public function __construct(private readonly SentEmails $sentEmails)
    {
    }

    public function sendOrderConfirmation(string $orderId, string $recipient, string $subject, string $body): void
    {
        if (str_ends_with($recipient, self::BOUNCING_DOMAIN)) {
            throw new EmailBounced(sprintf(
                'The confirmation email for order %s bounced: %s is at bounce.example, which accepts no mail, so the customer has not received their entry codes by email. Replaying this message bounces again. The tickets and their entry codes are on the order page, /orders/%s in the storefront: hand them to the customer from there, then delete this message from the dead letters in Ecotone Pulse or with php artisan ecotone:deadletter:delete <messageId>. To email the next order, register the customer with an address outside bounce.example.',
                $orderId,
                $recipient,
                $orderId,
            ));
        }
        $this->sentEmails->record(orderId: $orderId, recipient: $recipient, subject: $subject, body: $body);
    }
}
