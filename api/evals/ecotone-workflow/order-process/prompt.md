---
description: "Writing an order payment saga with retries and a stateless image-processing pipeline"
tags: [ecotone-workflow]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0 (open-source edition). Two pieces of work:

**A. Order payment process.** These events exist:

```php
<?php

final class OrderWasPlaced { public function __construct(public readonly string $orderId, public readonly int $amount) {} }
final class PaymentFailed { public function __construct(public readonly string $orderId) {} }
final class PaymentSucceeded { public function __construct(public readonly string $orderId) {} }
final class ShipmentDelivered { public function __construct(public readonly string $orderId) {} }
```

and a payment service already handles taking money:

```php
<?php

use Ecotone\Api\Attribute\CommandHandler;

final class PaymentService
{
    #[CommandHandler('takePayment')]
    public function take(TakePayment $command): void { /* calls the provider, publishes PaymentSucceeded or PaymentFailed */ }
}
```

When an order is placed, the process must ask for payment (send `TakePayment` with the order id and amount). If the payment fails, try once more; after the second failure give up and mark the process failed. When it succeeds, mark it paid. The process must also mark itself delivered on `ShipmentDelivered` — but that event is also published for orders placed before this process existed, and those must simply be ignored. Taking the payment must happen in the background on the `async` channel.

**B. Image upload.** A `ProcessImage` command with a file path must be validated (only jpg/png), then resized, then uploaded to S3. Each step is a separate method; there is no state to keep.

Write the classes. Don't ask questions.
