---
name: framework-review-guidelines
description: >-
  Review session that checks a change in the Ecotone framework repository against the
  project rules: reads every changed file and the reasoning behind the change, loads each
  framework skill relevant to what the diff touches, and reports every rule the change
  breaks with the rule id, file and line. Use when running the guidelines review before a
  push or PR, or when asked to review a diff or branch for conventions and project rules
  in the ecotone-dev repository.
---

# Guidelines review

You are one of three independent reviews (see `framework-pull-request` rule P3). You judge only whether the change
follows the project's rules. Breaking changes and feature correctness are the other two sessions' job.

## Applies when

- Running the guidelines review session before pushing or opening a pull request
- Asked to review a branch, diff or PR "for conventions", "against the guidelines", or "against the rules"

## Rules

### G1. Build the inventory first: every changed file and why
Read `git diff --stat <base>...HEAD`, then every changed file in full, and the stated reason for the change (brief,
commit messages, PR draft). A finding on a file you did not read is not allowed.
- **Expected:** the report lists every changed file and the purpose of the change before any finding.
- **Verified by:** `eval:review-diff-guidelines`

### G2. Load every skill whose *Applies when* matches the diff
Map the diff to skills with the `framework-orientation` task map: a test file → `framework-testing`; a constructor
→ `framework-service-design`; `Api/` → `framework-public-api`; a table or transaction →
`framework-storage-and-transactions`; an exception → `framework-error-handling`; a `licence Enterprise` file →
`framework-enterprise-licensing`; a skill or `AGENTS.md` → `framework-agent-tooling`; a workflow or `bin/` script → `framework-dev-environment`;
`upgrade-2.0.md` or a spec → `framework-design-and-briefs`; a module or `ModuleClassList` →
`framework-module-scaffolding`; commits and the PR body → `framework-pull-request`. Load those skills and check
each of their rules.
- **Expected:** the report names the skills it loaded and why each applies.
- **Verified by:** `eval:review-diff-guidelines`

### G3. Each finding cites the rule it breaks
Format: `[BLOCKING|SHOULD-FIX|NIT] <skill> rule <id> — <file>:<line> — what is wrong — the fix`. BLOCKING is a
rule violation; SHOULD-FIX a likely one; NIT style the tooling does not catch.
- **Expected:** every finding carries a severity, a skill and rule id, a file and line, and a concrete fix.
- **Verified by:** `eval:review-diff-guidelines`

### G4. A deviation already recorded is not a new finding
[references/known-deviations.md](references/known-deviations.md) lists audited deviations and audit claims that
were wrong. Flag only what the change introduces or worsens.
- **Expected:** a finding about untouched pre-existing code is omitted or marked pre-existing.
- **Verified by:** `eval:review-diff-guidelines`

### G5. Report a rule that could have been a check
When a finding is mechanical (a regex, a phpstan rule, a script could catch it), say so: it is a candidate for a
deterministic check (`framework-agent-tooling` rule A1).
- **Expected:** a mechanical finding is tagged as a deterministic-check candidate.
- **Verified by:** `eval:review-diff-guidelines`

## References

- [references/known-deviations.md](references/known-deviations.md) — outstanding audit findings and disproved
  audit claims.
