---
description: "Showing in a flow test every step Ecotone ran for a command"
tags: [ecotone-testing]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm on Ecotone 2.0. A teammate asked why placing an order goes through so many interceptors. This is the handler:

```php
<?php

namespace App\Ordering;

use Ecotone\Api\Attribute\CommandHandler;

final class PlaceOrder { public function __construct(public string $orderId) {} }

final class OrderService
{
    public array $placed = [];

    #[CommandHandler]
    public function place(PlaceOrder $command): void
    {
        $this->placed[] = $command->orderId;
    }
}
```

Write a PHPUnit flow test that sends `PlaceOrder`, prints every step Ecotone ran for it, and asserts the recorded
process is the one that ran `OrderService::place`. Give me the complete test class. Don't ask questions.
