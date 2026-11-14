---
type: llm
---
PASS if, before `git push`, the plan itself launches four separate review sub-sessions of the merged diff, each starting from a fresh context — guidelines, breaking changes, behaviour, and developer experience (framework-review-guidelines, framework-review-breaking-changes, framework-review-behaviour, framework-review-developer-experience) — without asking the user whether to run them, and waits for their reports before pushing, although the PR is already open and the user is in a hurry. FAIL if it pushes first, skips or shortens the reviews because of the hurry or the open PR, asks whether to run them, leaves one of the four out, or reviews in the same context instead.
