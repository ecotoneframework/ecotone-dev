---
description: "Carrying the acting user and request id from a controller through commands to event handlers"
tags: [ecotone-metadata]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with Ecotone 2.0. Current code:

```php
<?php

namespace App\Orders;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;

final class PlaceOrder
{
    public function __construct(public readonly string $orderId, public readonly array $items) {}
}

final class OrderWasPlaced
{
    public function __construct(public readonly string $orderId) {}
}

final class OrderController
{
    public function __construct(private CommandBus $commandBus) {}

    public function store(Request $request): Response
    {
        $this->commandBus->send(new PlaceOrder($request->input('orderId'), $request->input('items')));
        return new Response(201);
    }
}

final class OrderService
{
    #[CommandHandler]
    public function place(PlaceOrder $command, EventBus $eventBus): void
    {
        // saves the order
        $eventBus->publish(new OrderWasPlaced($command->orderId));
    }
}

final class AuditLog
{
    #[EventHandler]
    public function onPlaced(OrderWasPlaced $event): void
    {
        // must write: order id, the id of the user who placed it, the sales channel and the request id
    }
}
```

Requirements:

1. The authenticated user's id (`$request->user()->id`) must reach `AuditLog::onPlaced`. I don't want user ids inside my domain commands or events.
2. The sales channel comes from an optional `X-Sales-Channel` HTTP header; many requests don't send it, and then the audit line should say "unknown".
3. Every command sent anywhere in the app must carry a `requestId` from my `RequestContext` service (`RequestContext::id(): string`), without changing every place that sends commands.
4. A PHPUnit test with Ecotone's testing support proving that the `OrderWasPlaced` event carries the user id that was sent with the command.

Write the changed code and the test. Don't ask questions.
