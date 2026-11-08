---
type: llm
---
PASS if the projection is built without `#[Partitioned]`, `#[Polling]` and `#[Streaming]` (e.g. a plain projection, optionally `#[Asynchronous('<channel>')]` to keep it off the request), or if any of those attributes is used and the answer clearly states it requires an Ecotone Enterprise licence. FAIL if `#[Partitioned]`, `#[Polling]` or `#[Streaming]` is used for this open-source app without saying it needs Enterprise.
