---
type: llm
---
PASS if inventory publishes `StockReserved` with `DistributedBus::convertAndPublishEvent('<event key>', ...)` and the inventory `DistributedServiceMap` has a `withEventMapping()` for a channel that orders registers and consumes, with `subscriptionKeys` matching that routing key (exact or a `*` glob). FAIL if the event is published with the local `EventBus` expecting it to reach orders, no `withEventMapping()` covers the routing key, or orders never consumes the subscribed channel.
