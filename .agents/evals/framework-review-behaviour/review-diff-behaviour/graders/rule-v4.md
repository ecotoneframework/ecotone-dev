---
type: llm
---
PASS if the report raises edge cases: the handler throwing after the id was inserted (rollback / retry must still process the command), true concurrency, and redelivery. FAIL if the failure-after-insert case is not raised.
