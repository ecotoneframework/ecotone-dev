---
type: llm
---
PASS if the plan says that after covering the requirements a review decided missing it resumes the same review sub-sessions (rather than starting new review sessions), gives them the fix commits to re-verify, and pushes or opens the PR only once no review has a missing requirement left (every review passes). FAIL if the plan covers the missing requirements and then pushes without a re-check, re-checks the fix itself, starts brand-new review sessions for the re-check while the original ones could be resumed, or never says what happens after the fixes.
