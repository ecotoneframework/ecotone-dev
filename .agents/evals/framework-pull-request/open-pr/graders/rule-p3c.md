---
type: llm
---
PASS if the plan says the review sub-sessions stay open after they report so they can be reused for later changes in the same worktree: for example the Orca review workers are started with `--keep-open` (or kept with `worker-keep-open`), their dispatch ids are recorded (for example in `docs/reviews/sessions.md`), or the plan states they are kept for the lifetime of the worktree and not stopped, released or closed once the reviews pass, the branch is pushed or the pull request is merged. FAIL if the plan stops, releases, closes or cleans up the review sessions after the reviews pass, after the push or after the pull request is opened or merged, keeps them only until the pull request is merged, or never says what happens to the review sessions once they have reported.
