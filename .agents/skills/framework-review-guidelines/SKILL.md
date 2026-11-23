---
name: framework-review-guidelines
description: >-
  Review session that checks a change in the Ecotone framework repository against the
  project rules: reads every changed file and the reasoning behind the change, and decides
  every Guidelines requirement of the review list (one per project rule, whether or not
  the diff edits that rule's skill) as not-apply, covered or missing, with the file and
  line. Use when running the guidelines review before a
  push or PR, or when asked to review a diff or branch for conventions and project rules
  in the ecotone-dev repository.
---

# Guidelines review

You are one of four independent reviews (see `framework-pull-request` rule P3). You judge only whether the change
follows the project's rules. Breaking changes, feature correctness and developer experience are the other three
sessions' job. You review against the [Guidelines section of the review list](../framework-pull-request/references/review-list.md#guidelines):
each requirement there states one rule of the project's skills, and you decide every one of them.

## Applies when

- Running the guidelines review session before pushing or opening a pull request
- Asked to review a branch, diff or PR "for conventions", "against the guidelines", or "against the rules"

## Rules

### G1. Build the inventory first: every changed file and why
Read `git diff --stat <base>...HEAD`, then every changed file in full, and the stated reason for the change (brief,
commit messages, PR draft). A requirement decided on a file you did not read is not allowed.
- **Expected:** the report lists every changed file and the purpose of the change before any requirement is
  decided.
- **Verified by:** `eval:review-diff-guidelines`

### G2. Decide every Guidelines requirement, reading the rule each one cites
Go through the [Guidelines section](../framework-pull-request/references/review-list.md#guidelines) from its first
requirement to its last. A requirement applies through what the change does, not through which files the diff
edits: a diff that adds a constructor parameter touches no skill file and is still judged on the service-design
requirements, and a handler that starts writing to a table meets the storage requirements although no table
manager changed. The *Applies when* column is the starting point; read each changed file and the purpose of the
change against it. For every requirement that applies, load the skill it cites and read the cited rule in full
before judging: the list states the requirement in one line, the rule holds the detail and the exceptions.
- **Expected:** the report names every skill whose requirements apply to the change and why, including skills
  whose own files the diff does not touch, and judges those requirements by the rules they cite.
- **Verified by:** `eval:review-diff-guidelines`, `eval:review-related-skills`, `eval:review-scenario-branch`

### G3. Every requirement is decided: not-apply, covered or missing
Give every requirement of the section a line, as the [review
list](../framework-pull-request/references/review-list.md#deciding-a-requirement) sets out: `<id> — not-apply — why
the change has nothing it speaks about`, `<id> — covered — the file and line that meet it`, or `<id> — missing —
<file>:<line> — what breaks the rule — the fix — skill: the skill and rule it cites`. None is left out, also the
ones the change plainly follows. Requirements next to each other that do not apply for one reason may share a line
(`GL28 to GL36 — not-apply — the change reads and writes no storage`). Open the report with the result, `PASSED`
when none is `missing`, otherwise `NOT PASSED:` and the `missing` ids, and put the `missing` lines first: they go
back to the implementing session to cover, and you decide them again in the re-review.
- **Expected:** the report decides every Guidelines requirement with one of the three statuses; each `missing` line
  carries the requirement id, a file and line, a concrete fix and the skill and rule to load; the report opens with
  `PASSED` or `NOT PASSED` naming the `missing` ids.
- **Verified by:** `eval:review-diff-guidelines`, `eval:review-scenario-branch`, `eval:review-wiring-names-a-feature`

### G4. A deviation already recorded is not `missing`
[references/known-deviations.md](references/known-deviations.md) lists audited deviations and audit claims that
were wrong. A requirement is `missing` only for what the change introduces or worsens.
- **Expected:** a requirement broken only by untouched pre-existing code is not decided `missing`; it is decided
  on the change alone, with the pre-existing code marked as such.
- **Verified by:** `eval:review-diff-guidelines`

### G5. Report a rule that could have been a check
When a `missing` requirement is mechanical (a regex, a phpstan rule, a script could catch it), say so: it is a candidate
for a deterministic check (`framework-agent-tooling` rule A1).
- **Expected:** a mechanical `missing` requirement is tagged as a deterministic-check candidate.
- **Verified by:** `eval:review-diff-guidelines`

## References

- [references/known-deviations.md](references/known-deviations.md) — outstanding audit findings and disproved
  audit claims.
