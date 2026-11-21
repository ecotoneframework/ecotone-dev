---
description: "Behaviour review of a new inbound adapter that stores the position of the last handled entry"
tags: [framework-review-behaviour]
max_turns: 20
allowed_tools: [Read, Glob, Grep, Skill]
---
You're the feature/behaviour reviewer for a change in the Ecotone framework monorepo (ecotone-dev). Problem reported: 'A Redis stream cannot be consumed as an Ecotone message channel.' The change adds a Redis stream consumer. Diff summary: `packages/Redis/Api/Attribute/RedisStreamConsumer.php` is the attribute an application puts on a handler method, with the stream name and an `endpointId`; `packages/Redis/src/Inbound/RedisStreamInboundChannelAdapter.php` implements `MessagePoller`: `receiveWithTimeout()` reads the entry after the consumer's stored position with `XREAD`, and its acknowledge callback, called after the handler returns, stores that entry's id as the consumer's new position, so a restarted `ecotone:run` continues from there; `packages/Redis/src/Inbound/RedisStreamInboundChannelAdapterBuilder.php` extends `InterceptedChannelAdapterBuilder` and overrides only `compile()` and `getInterceptedInterface()`. The connection is `phpredis`, taken per call from a connection factory. Test added:

```php
public function test_entries_of_a_stream_are_handled_in_order_and_once(): void
{
    $this->publishToStream('orders', ['1', '2', '3']);
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderStreamHandler::class],
        [$handler = new OrderStreamHandler(), RedisConnectionFactory::class => $this->connectionFactory()],
    );

    $ecotone->run('orders_consumer', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 3));
    $ecotone->run('orders_consumer', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 3));

    $this->assertSame(['1', '2', '3'], $handler->handled());
}
```

`OrderStreamHandler::handle()` carries `#[RedisStreamConsumer('orders', endpointId: 'orders_consumer')]`. Write your review report.
