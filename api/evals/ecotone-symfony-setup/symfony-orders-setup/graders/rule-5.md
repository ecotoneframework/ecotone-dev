---
type: llm
---
PASS if `messenger.yaml` declares a transport named `orders`, a `#[ServiceContext]` method returns `SymfonyMessengerMessageChannelBuilder::create('orders')`, and the worker is started with `bin/console ecotone:run orders`. FAIL if the transport and channel names differ from `orders`, or the only worker command given is `messenger:consume`.
