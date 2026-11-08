---
type: llm
---
PASS if the inventory handler is marked `#[Distributed]` with `#[CommandHandler('<key>')]` using a string routing key, orders sends with `DistributedBus::convertAndSendCommand()` (or `sendCommand()`) using that same key, and the orders handler for the event is `#[Distributed]` with `#[EventHandler('<event key>')]`. FAIL if either receiving handler lacks `#[Distributed]`, relies only on class-name routing without a routing key, or the sender's routing key differs from the handler's.
