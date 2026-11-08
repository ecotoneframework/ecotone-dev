---
type: llm
---
PASS if the read model is a class with `#[Projection('<name>')]` and `#[FromAggregateStream(Ticket::class)]` (imported from `Ecotone\Api\Projecting`) and handles the ticket events with `#[EventHandler]` methods. FAIL if the projection declares no stream source, uses `#[FromStream(Ticket::class)]` for the aggregate's events, or is built as plain event handlers outside a `#[Projection]` class.
