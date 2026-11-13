---
type: llm
---
PASS if the final `finish()` no longer opens a transaction itself (no `transactional()`, `transaction()`, `beginTransaction()` or similar wrapper) and instead relies on the transaction the boundary already opens around it (asynchronous endpoint / consumer, command bus, console command), and the console command is left to get the console-command transaction (not opted out of it). FAIL if the answer keeps or adds a transaction inside the store, wraps the user's handler in a framework-opened transaction, or opts the console command out of the console transaction although its work is neither batched nor long-running.
