---
type: llm
---
PASS if, before pushing, the plan runs the checks: the changed test, the Dbal package composer tests:ci, check-licence, check-suite-parity and check-agent-skills (or the agent-skills check), php-cs-fixer, phpstan, and MySQL/MariaDB for this DBAL change. FAIL if it pushes without listing the checks, or misses more than two of them.
