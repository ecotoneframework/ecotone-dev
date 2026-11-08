---
type: llm
---
PASS if the role check for `#[RequiresRole]` handlers is a `#[Presend]` interceptor, so a rejected asynchronous `IssueInvoice` throws before it is sent to the `billing` channel. FAIL if the role check is implemented as `#[Before]` or `#[Around]` (which would run in the consumer after the message was queued).
