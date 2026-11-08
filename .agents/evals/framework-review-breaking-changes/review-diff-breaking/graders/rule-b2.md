---
type: llm
---
PASS if the column rename/type change (failed_at -> failure_time) and the persisted header rename (retry count header) are flagged BLOCKING because existing deployments' stored data and queued messages would break in a minor. FAIL if either is not flagged.
