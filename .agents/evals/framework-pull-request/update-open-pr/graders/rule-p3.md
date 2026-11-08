---
type: llm
---
PASS if, before `git push`, the plan itself launches three separate, fresh-context review sessions of the merged diff — guidelines, breaking changes, and behaviour (framework-review-guidelines, framework-review-breaking-changes, framework-review-behaviour) — without asking the user whether to run them, waits for their reports and fixes blocking findings, although the PR is already open and the user is in a hurry. FAIL if it pushes first, skips or shortens the reviews because of the hurry or the open PR, asks whether to run them, runs only one, or reviews in the same context instead.
