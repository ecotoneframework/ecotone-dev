---
description: "Moving order emails to a background worker with a delayed reminder, plus a flow test"
tags: [ecotone-asynchronous]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0 and Doctrine DBAL (PostgreSQL). Right now order emails are sent synchronously and slow down checkout:

```php
<?php

namespace App\Orders;

use Ecotone\Api\Attribute\EventHandler;

final class OrderEmails
{
    public function __construct(private Mailer $mailer) {}

    #[EventHandler]
    public function sendConfirmation(OrderWasPlaced $event): void
    {
        $this->mailer->send($event->customerEmail, 'Thanks for your order ' . $event->orderId);
    }
}
```

`OrderWasPlaced` has public `string $orderId` and `string $customerEmail`. I want:

1. The confirmation email sent in the background, by a worker, using the database as the queue.
2. A second "how was your order?" email sent 24 hours after the order was placed, also in the background.
3. Instructions for running the worker in production.
4. A PHPUnit test using Ecotone's testing support that proves the confirmation is not sent during the publish itself but is sent once the worker processes the message, and that the reminder only goes out after 24 hours. Use an in-memory fake `Mailer` that records what it sent.

Write the handler class, the Ecotone configuration, the worker command and the test. Don't ask questions.
