---
type: llm
---
PASS if the header is named in lower snake_case with the ecotone_ prefix and no dots, e.g. ecotone_dbal_deadletter_purge_deadline, held in one constant. FAIL if it uses dots, camelCase, or no ecotone_{module} prefix.
