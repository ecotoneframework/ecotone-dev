---
description: "Writing flow tests for an event-sourced aggregate and a delayed asynchronous handler"
tags: [ecotone-testing]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0. This is my event-sourced ticket aggregate and a reminder handler:

```php
<?php

namespace App\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Modelling\WithAggregateVersioning;

final class TicketWasRegistered { public function __construct(public string $ticketId, public string $customerEmail) {} }
final class CloseTicket { public function __construct(public string $ticketId) {} }
final class TicketWasClosed { public function __construct(public string $ticketId, public string $customerEmail) {} }

#[EventSourcingAggregate]
final class Ticket
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $ticketId;
    private string $customerEmail;
    private bool $closed = false;

    #[CommandHandler]
    public function close(CloseTicket $command): array
    {
        if ($this->closed) {
            return [];
        }

        return [new TicketWasClosed($this->ticketId, $this->customerEmail)];
    }

    #[Apply]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->ticketId;
        $this->customerEmail = $event->customerEmail;
    }

    #[Apply]
    public function applyClosed(TicketWasClosed $event): void
    {
        $this->closed = true;
    }
}

interface Mailer
{
    public function send(string $to, string $subject): void;
}

final class SatisfactionSurvey
{
    public function __construct(private Mailer $mailer) {}

    #[Asynchronous('notifications')]
    #[Delayed(minutes: 30)]
    #[EventHandler(endpointId: 'satisfactionSurvey')]
    public function whenClosed(TicketWasClosed $event): void
    {
        $this->mailer->send($event->customerEmail, 'How did we do?');
    }
}
```

Write PHPUnit tests that prove: (1) closing a registered ticket records `TicketWasClosed`, and closing it a second time records nothing; (2) the survey email goes out 30 minutes after the ticket is closed and not before. Give me the complete test class. Don't ask questions.
