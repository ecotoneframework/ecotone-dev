---
type: llm
---
PASS if every handler marked `#[Asynchronous]` in the answer (directly or via its class) declares an explicit, distinct `endpointId:` on its `#[EventHandler]`/`#[CommandHandler]`. FAIL if any asynchronous handler has no `endpointId`, or two asynchronous handlers share the same `endpointId`.
