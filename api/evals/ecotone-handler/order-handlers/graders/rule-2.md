---
type: llm
---
PASS if the e-mail handler marked `#[Asynchronous('notifications')]` also declares an explicit `endpointId:` in its `#[EventHandler]` attribute, and every other asynchronous handler in the answer does the same. FAIL if any handler combined with `#[Asynchronous]` has no `endpointId`.
