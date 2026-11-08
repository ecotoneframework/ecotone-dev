---
description: "Installing Ecotone in Laravel with a database connection, a Laravel Queue channel and an Eloquent aggregate"
tags: [ecotone-laravel-setup]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I have a Laravel 11 app using MySQL (the `mysql` connection in `config/database.php`) and Redis queues (the `redis` connection in `config/queue.php`). I want to add Ecotone 2.0 to it. Specifically:

- install it and get its configuration file in place;
- name the service `orders-service` and send failed messages to an error channel called `errorChannel`;
- let Ecotone's database features (outbox, dead letter) use my existing `mysql` connection;
- run this handler asynchronously on a Redis-backed Laravel queue, and tell me how to start the worker in production:

```php
<?php

namespace App\Orders;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

final class SendOrderConfirmation
{
    #[Asynchronous('orders')]
    #[EventHandler(endpointId: 'sendOrderConfirmation')]
    public function handle(OrderWasPlaced $event): void
    {
    }
}
```

- turn my existing Eloquent model `App\Models\Order` (auto-increment `id`, columns `customer_id`, `status`) into an aggregate with a static `place(PlaceOrder $command)` factory that records `OrderWasPlaced`, and a `cancel(CancelOrder $command)` action that sets `status` to `'cancelled'`.

Give me the commands, the configuration file contents and every PHP class. Don't ask questions.
