---
description: "Writing command, event and query handlers for an order flow with an async listener, a routing-key command and metadata"
tags: [ecotone-handler]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with Ecotone 2.0. I have these messages:

```php
<?php

final class PlaceOrder
{
    public function __construct(public readonly string $orderId, public readonly array $productIds) {}
}

final class OrderWasPlaced
{
    public function __construct(public readonly string $orderId, public readonly array $productIds) {}
}

final class GetOrderStatus
{
    public function __construct(public readonly string $orderId) {}
}
```

Write the handler code for this:

1. An `OrderService` that places an order (store it in an in-memory array is fine) and publishes `OrderWasPlaced`, and answers `GetOrderStatus` with a string status.
2. When an order is placed, the `InventoryService` must reserve stock for the products, and a `CustomerNotifier` must send a confirmation e-mail. The e-mail is slow, so it should run in the background on a channel called `notifications`.
3. The confirmation e-mail must mention the id of the user who placed the order. The controller knows the user id from the session, but `PlaceOrder` must not change.
4. A payment-provider webhook controller receives `{"orderId": "..."}` as a plain array and must cancel the order. I don't want to create a class for that command.

Also show the controller code that places the order and the webhook controller that cancels it. Don't ask questions.
