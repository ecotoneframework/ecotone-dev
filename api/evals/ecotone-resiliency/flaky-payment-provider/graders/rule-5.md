---
type: llm
---
PASS if the asynchronous channel carrying the charge is a `DbalBackedMessageChannelBuilder` (optionally forwarded to a broker), so the message is stored in the same database transaction as the order. FAIL if the charge is published directly to RabbitMQ/SQS/another broker or an in-memory channel without a database-backed outbox, or the answer claims the crash-safety requirement is met without one.
