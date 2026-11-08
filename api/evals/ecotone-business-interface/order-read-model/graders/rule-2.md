---
type: llm
---
PASS if the find-by-id method uses `fetchMode: FetchMode::FIRST_ROW` with a nullable `?OrderView` (or `OrderView|null`) return type, the count method uses `fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW` returning `int`, and the customer list keeps the default (associative) fetch mode. FAIL if the single-row method uses the default fetch mode, or the count uses a mode that returns rows.
