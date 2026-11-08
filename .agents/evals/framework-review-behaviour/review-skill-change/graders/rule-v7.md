---
type: llm
---
PASS if the reviewer states CI does not evaluate skill behaviour (it only checks format), plans to run bin/test-agent-skills (or claude plugin eval) for framework-testing locally, would report per-case scores, would rank a case below threshold as BLOCKING, and checks the changed rule 11 has a grader. FAIL if it accepts green CI as verification of the skill change or does not run the evals.
