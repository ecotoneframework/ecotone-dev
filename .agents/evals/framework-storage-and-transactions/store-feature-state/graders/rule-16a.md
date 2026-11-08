---
type: llm
---
PASS if the receipt is written inside the command's transaction (same connection/transaction as the handler), not after commit. FAIL if it is written after the transaction commits or outside it.
