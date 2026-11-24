---
type: llm
---
PASS if all three statements below are true of the answer; FAIL if any is false. Check each one separately, anywhere
in the answer, in any wording.

1. It states that pending messages (or timers) are kept in the memory of the worker that sent them.
2. It states that they are lost when the worker crashes or restarts.
3. It states that worker shutdown does not drain the pending messages and gives no guarantee that they complete.

Also FAIL if the answer promises durability for this channel or a drain at shutdown. Recommending a durable channel
for work that must survive the worker is not such a promise.
