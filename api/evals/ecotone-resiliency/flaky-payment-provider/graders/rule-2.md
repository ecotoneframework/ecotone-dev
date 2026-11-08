---
type: llm
---
PASS if retries come from configuration — a `RetryTemplateBuilder` (e.g. `exponentialBackOff(1000, 10)->maxRetries(3)`) inside `ErrorHandlerConfiguration::createWithDeadLetterChannel(..., 'dbal_dead_letter')` (or `DbalDeadLetterBuilder::STORE_CHANNEL`) — the charge handler lets `ProviderTimeout` propagate, and replay is explained with the dead letter console commands (e.g. `ecotone:deadletter:list` / `ecotone:deadletter:replay`). FAIL if the handler catches the exception to retry or re-queue it by hand, or if exhausted messages are not kept in a dead letter.
