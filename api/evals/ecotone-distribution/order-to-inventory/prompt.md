---
description: "Wiring a command and an event between two Ecotone microservices over RabbitMQ"
tags: [ecotone-distribution]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I have two Symfony applications built with Ecotone 2.0, both connected to the same RabbitMQ: **orders** and **inventory**.

In orders, after an order is placed I need to tell inventory to reserve stock:

```php
<?php

namespace App\Orders;

final class ReserveStock
{
    public function __construct(public readonly string $orderId, public readonly array $skus) {}
}
```

In inventory, this handler exists today but is only called locally:

```php
<?php

namespace App\Inventory;

use Ecotone\Api\Attribute\CommandHandler;

final class StockService
{
    #[CommandHandler]
    public function reserve(ReserveStock $command): void
    {
        // reserves stock
    }
}
```

After reserving, inventory must publish a `StockReserved` event (`orderId`) that a handler in orders reacts to, to mark the order as ready to ship.

Write everything needed in both applications: configuration classes, the sending code in orders, the receiving handlers in both services, and the commands to run in production. Don't ask questions.
