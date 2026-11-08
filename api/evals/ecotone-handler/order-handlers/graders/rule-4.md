---
type: llm
---
PASS if the cancel command is a `#[CommandHandler('<routing key>')]` that takes the array payload, and the webhook controller calls `$commandBus->sendWithRouting('<the same routing key>', [...])`, while the place-order controller uses `$commandBus->send(new PlaceOrder(...))` (and status lookups, if shown, use `QueryBus::send`). FAIL if the routing keys differ, if the webhook uses `send()` with an array, or if a new command class is introduced for cancelling.
