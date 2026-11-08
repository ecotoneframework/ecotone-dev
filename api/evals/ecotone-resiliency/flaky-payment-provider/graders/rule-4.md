---
type: llm
---
PASS if the answer makes the total of 4 provider calls hold given that instant retries also run on asynchronous endpoints in a DBAL transaction: it either disables them with `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)` alongside `maxRetries(3)`, or otherwise sizes both budgets so (instant retries + 1) x (delayed retries + 1) = 4, and states the resulting total. FAIL if it configures `maxRetries(3)` with default instant retries left on (yielding 16 calls) or never accounts for instant retries when claiming exactly 4 calls.
