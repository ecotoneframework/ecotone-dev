---
description: "Routing commands, foreign events, header-only ids and routing-key commands to an Order aggregate, a saga and an Invoice"
tags: [ecotone-identifier-mapping]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with Ecotone 2.0. I have this aggregate and saga:

```php
<?php

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Saga;

#[Aggregate]
final class Order
{
    #[Identifier]
    private string $orderId;
    private string $status = 'placed';
    private string $address = '';
}

#[Saga]
final class ShippingProcess
{
    #[Identifier]
    private string $orderId;

    #[EventHandler]
    public static function start(OrderWasPlaced $event): self
    {
        $saga = new self();
        $saga->orderId = $event->orderId;
        return $saga;
    }
}
```

Add the following, each targeting the right existing instance:

1. A `CancelOrder` command (our class) handled by `Order`.
2. A `ChangeShippingAddress` command (our class) handled by `Order`. The mobile team insists the property holding the order id is called `id`.
3. `ShippingProcess` must react to `PaymentConfirmed` from the payment vendor's package (we cannot modify that class). It has `public readonly string $reference`, which holds our order id.
4. `ShippingProcess` must react to `CourierAssigned`, whose payload has only the courier name; the order id travels in the `x-order-id` message header.
5. A webhook controller marks an order as paid: it has the order id from the URL and a plain array payload `['paidAt' => ...]`, and sends the `order.markAsPaid` routing key. Write the controller call and the `Order` handler; don't create a command class for it.
6. A new `Invoice` aggregate must store its number in `private string $internalNumber` (the Doctrine mapping depends on that name), but the aggregate's identifier must be called `invoiceId`, because other services address it as `['invoiceId' => ...]`. Add the `Invoice` aggregate and an `ApproveInvoice` command handled by it.

Write the code. Don't ask questions.
