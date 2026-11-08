---
description: "Retrying a flaky payment API with backoff, dead letter, outbox and a retried command bus"
tags: [ecotone-resiliency]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0 and Doctrine DBAL (MySQL). We have an Ecotone Enterprise licence. Our payment provider times out a lot:

```php
<?php

namespace App\Payments;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;

final class PaymentService
{
    public function __construct(private PaymentProviderClient $provider, private StockRepository $stock) {}

    #[EventHandler]
    public function chargeWhenOrderPlaced(OrderWasPlaced $event): void
    {
        $this->provider->charge($event->orderId, $event->amount); // throws ProviderTimeout
    }

    #[CommandHandler]
    public function reserveStock(ReserveStock $command): void
    {
        $this->stock->reserve($command->sku, $command->quantity); // sometimes throws DeadlockDetected
    }
}
```

`OrderWasPlaced` is published from our `PlaceOrder` command handler, which saves the order in the same MySQL database.

Requirements:

1. The charge must never be lost: if the process crashes right after the order is committed, the charge must still happen later.
2. A failing charge is retried with growing delays of 1 s, 10 s and 100 s. The provider must be called **exactly 4 times in total** for a charge that keeps failing — our contract with them forbids more.
3. After the last attempt the message is kept so support can inspect and replay it.
4. `ReserveStock` is sent synchronously from our checkout controller through the command bus; when it throws `DeadlockDetected` it should be retried immediately up to 2 times before the controller sees the error.

Write the handler changes, the Ecotone configuration classes and the controller wiring, and explain how support replays a failed charge. Don't ask questions.
