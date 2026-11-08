---
type: llm
---
PASS if the production configuration has a `#[ServiceContext]` method returning a database-backed channel builder (e.g. `DbalBackedMessageChannelBuilder::create('<name>')`) whose name exactly matches the channel name used in `#[Asynchronous('<name>')]`. FAIL if the channel is never registered, is registered under a different name, is configured through YAML/XML instead of `#[ServiceContext]`, or production uses only an in-memory `SimpleMessageChannelBuilder`.
