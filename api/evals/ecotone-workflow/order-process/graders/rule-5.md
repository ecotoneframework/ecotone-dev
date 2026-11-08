---
type: llm
---
PASS if every handler marked `#[Asynchronous('async')]` (at least the one that triggers or performs the payment) also declares an explicit, distinct `endpointId:` in its handler attribute. FAIL if any `#[Asynchronous]` handler lacks `endpointId`, or if nothing is made asynchronous.
