---
type: llm
---
PASS if the answer says the per-type move commits revert only newest-first (as a suffix, because adjacent use lines conflict), that this is checked with git revert --no-commit / --abort, and that the report should state it — without proposing to squash or reorder sorted files to avoid it. FAIL if it claims each commit reverts independently, or does not address reverting.
