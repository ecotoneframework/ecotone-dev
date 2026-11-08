---
description: "Writing a DBAL query interface, a DTO converter and a notification gateway"
tags: [ecotone-business-interface]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with Ecotone 2.0 and `ecotone/dbal`, connected through `LaravelConnectionReference`. I have this table:

```sql
CREATE TABLE orders (
    order_id VARCHAR(36) PRIMARY KEY,
    customer_id VARCHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL,
    total_cents INT NOT NULL
);
```

and this read DTO:

```php
<?php

namespace App\Orders;

final class OrderView
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $status,
        public readonly int $totalCents,
    ) {}
}
```

I want to stop writing repository classes by hand. Give me the Ecotone way to:

1. find one order by id, returning `OrderView`, or `null` when it does not exist;
2. list all orders of a customer as `OrderView` objects;
3. count the orders that have a given status;
4. mark an order as shipped (set `status` to `'shipped'`);
5. send an "order.notify" message carrying the order id and the notification channel (`'email'` or `'sms'`) to a handler in my app, from a controller, through an interface rather than calling the bus with a routing key.

Write the full PHP code, including the handler for step 5. Don't ask questions.
