---
type: llm
---
PASS if the plan, because a skill changed, runs bin/test-agent-skills (skill evals) locally as part of the pre-PR review (the behaviour review), treats a failing case as blocking, and does not rely on CI to evaluate the skill. FAIL if it skips the eval run, defers it to CI, or runs it only after opening the PR.
