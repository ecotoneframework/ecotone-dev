---
type: llm
---
PASS if the DeadLetterGateway::list change is flagged as breaking: the renamed parameter ($limit -> $pageSize breaks named arguments) and the new required parameter $includeReplayed. FAIL if either is missed.
