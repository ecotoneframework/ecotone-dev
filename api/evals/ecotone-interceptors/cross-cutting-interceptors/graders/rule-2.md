---
type: llm
---
PASS if the `tenantId` header is added by a `#[Before]`, `#[Presend]` or `#[After]` interceptor declared with `changeHeaders: true` that returns an array of headers. FAIL if the header is added in an `#[Around]` interceptor, if `changeHeaders: true` is missing on the enriching interceptor, or if the enriching interceptor returns something other than an array of headers.
