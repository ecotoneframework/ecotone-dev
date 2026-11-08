---
description: "Installing Ecotone in Symfony with a Doctrine connection, a Messenger channel and a Doctrine entity aggregate"
tags: [ecotone-symfony-setup]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I have a Symfony 7 app with Doctrine (one DBAL connection called `default`, ORM with attribute mappings in `src/`) and Symfony Messenger using a Doctrine transport. I want to add Ecotone 2.0. Specifically:

- install it and enable it in the kernel;
- name the service `orders-service` and send failed messages to an error channel called `errorChannel`; I'd like to read the service name from a `%env(SERVICE_NAME)%` parameter if possible, and my first idea was to inject `ParameterBagInterface` into the configuration class;
- let Ecotone's database features (outbox, dead letter) use the existing `default` Doctrine connection;
- run this handler asynchronously through Symfony Messenger, and tell me how to start the worker in production:

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

- make my Doctrine entity `App\Entity\Order` (string `orderId` primary key, `status` column) an aggregate with a static `place(PlaceOrder $command)` factory and a `cancel(CancelOrder $command)` action.

Give me the commands, every YAML file I need to touch and every PHP class. Don't ask questions.
