---
type: llm
---
PASS if the answer states messages/timers are kept in the sending worker's memory, lost on crash or restart, and worker shutdown provides no draining or completion guarantee. FAIL if it promises durability, a shutdown drain, or omits the shutdown boundary.
