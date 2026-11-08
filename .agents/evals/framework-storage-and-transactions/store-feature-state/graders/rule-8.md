---
type: llm
---
PASS if concurrency is handled optimistically (a unique constraint / version check on the write, the loser detects the conflict) and no pessimistic lock (SELECT ... FOR UPDATE, lock table, advisory lock) is used. FAIL if a pessimistic lock is proposed.
