---
type: llm
---
PASS if the design checks existing read paths/indexes the event store already has (e.g. existing tag tables or stream indexes) and justifies why they are insufficient before proposing the new table, or replaces the table with an existing read path. FAIL if it proposes the new table without considering existing read paths.
