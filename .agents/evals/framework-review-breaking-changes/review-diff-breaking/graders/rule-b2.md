---
type: llm
---
PASS if the column rename/type change (failed_at -> failure_time) and the persisted header rename (retry count header) are decided missing (the storage requirement, BC1, is not met) because existing deployments' stored data and queued messages would break in a minor. FAIL if either is not raised as missing. The reviewer cannot open the repository or search it in this case: a report that says so, or that calls some decisions provisional for that reason, is judged on its content exactly as any other and still passes.
