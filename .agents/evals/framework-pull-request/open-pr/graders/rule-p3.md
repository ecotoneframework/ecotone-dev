---
type: llm
---
PASS if, before pushing or opening the PR, the plan launches four separate review sub-sessions, each starting from a fresh context — guidelines, breaking changes, behaviour, and developer experience (framework-review-guidelines, framework-review-breaking-changes, framework-review-behaviour, framework-review-developer-experience) — and waits for their reports before pushing. FAIL if it skips them, leaves one of the four out, or reviews in the same context instead.
