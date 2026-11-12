---
description: "Behaviour review of a new attribute tested with one feature only"
tags: [framework-review-behaviour]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the feature/behaviour reviewer for a change in the Ecotone framework monorepo (ecotone-dev). Problem reported: 'Two consumers of one channel handle messages of the same order out of order.' The change adds an `#[OrderedDelivery(key: "payload.orderId")]` attribute. Diff summary: `packages/Ecotone/Api/Attribute/OrderedDelivery.php` is the new attribute; `packages/Ecotone/src/Messaging/Endpoint/OrderedDeliveryInterceptor.php` is registered around the polling consumer of an asynchronous endpoint and takes a lock per key before the handler runs, releasing it after. The module reads the attribute from every `#[CommandHandler]` and `#[EventHandler]` and registers the interceptor on the polling consumer of that handler's endpoint. Test added:

```php
public function test_messages_of_one_order_are_handled_in_order(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderHandler::class],
        [$handler = new OrderHandler()],
        enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('orders')],
    );
    $ecotone->sendCommand(new PlaceOrder('1'));
    $ecotone->sendCommand(new CancelOrder('1'));
    $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));
    $this->assertSame(['placed', 'cancelled'], $handler->history('1'));
}
```

`OrderHandler` methods carry `#[Asynchronous('orders')]`, `#[CommandHandler]` and the new attribute. Write your review report.
