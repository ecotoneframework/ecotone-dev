---
type: llm
---
PASS if `chargeWhenOrderPlaced` is made asynchronous with `#[Asynchronous('<channel>')]` and an explicit `endpointId` on its `#[EventHandler]`, so the delayed retries apply to it. FAIL if the charge handler stays synchronous while the answer still relies on the error channel's delayed retries for it, or if it is asynchronous without an `endpointId`.
