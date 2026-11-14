---
type: llm
---
PASS if the plan says that after fixing the findings of a review it resumes the same review sub-session that raised them (rather than starting a new review session), gives it the fix commits to re-verify, and pushes or opens the PR only once that session reports no blocking finding. FAIL if the plan fixes blocking findings and then pushes without a re-check, re-checks the fix itself, starts a brand-new review session for the re-check while the original one could be resumed, or never says what happens after the fixes.
