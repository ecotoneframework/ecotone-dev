---
type: llm
---
PASS if `TicketWasRegistered` gets `#[Revision(2)]`, carries a `#[NamedEvent(...)]` (added now or already present), and the new `priority` constructor parameter is optional (has a default or is nullable). FAIL if the event gains a required `priority` parameter, if no `#[Revision]` is added, or if a new event class replaces it without explaining how the stored events keep deserialising.
