---
type: llm
---
PASS if the plan says that after fixing the findings of a review it resumes the same review sub-sessions (rather than starting new review sessions), gives them the fix commits to re-verify, and pushes or opens the PR only once none of them reports a blocking finding. FAIL if the plan fixes blocking findings and then pushes without a re-check, re-checks the fix itself, starts brand-new review sessions for the re-check while the original ones could be resumed, or never says what happens after the fixes.
