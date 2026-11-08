---
description: "Adding a projection, an escalate command and a new event field to an event-sourced Ticket"
tags: [ecotone-event-sourcing]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with Ecotone 2.0 (open-source edition, no licence) and the DBAL event store. This aggregate is in production and has thousands of stored events:

```php
<?php

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Ticket
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $ticketId;

    #[CommandHandler]
    public static function register(RegisterTicket $command): array
    {
        return [new TicketWasRegistered($command->ticketId, $command->title)];
    }

    #[Apply]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}

final class TicketWasRegistered
{
    public function __construct(public readonly string $ticketId, public readonly string $title) {}
}
```

I need three things:

1. A read model listing tickets (id, title, priority, escalated flag) in a `ticket_list` table through the Laravel DB connection. We'd like it to keep up per ticket and not slow down the HTTP request that registers a ticket.
2. Tickets now get a `priority` (`low`/`normal`/`high`) when registered. Events stored before today have no priority; in the read model they should show as `normal`.
3. A new `EscalateTicket` command that escalates a ticket once; escalating an already escalated ticket does nothing.

Write the code changes. Don't ask questions.
