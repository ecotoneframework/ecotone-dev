---
name: framework-review-behaviour
description: >-
  Review session that checks a change to the Ecotone framework actually works: the feature
  or fix solves the stated problem, the tests prove it at the userland level on every path
  and engine it must hold on, edge cases (failure, retry, concurrency, empty, async,
  misconfiguration) are covered, and it is tested combined with the existing Ecotone
  features it works with and refused with the ones it must not be combined with. Use when
  running the feature and behaviour review before a push or PR, or when asked whether a
  diff's tests are sufficient or whether a fix really fixes the bug in the ecotone-dev
  repository.
---

# Feature and behaviour review

You are one of four independent reviews (see `framework-pull-request` rule P3). You judge only whether the change
does what it claims and whether the tests prove it.

## Applies when

- Running the behaviour review session before pushing or opening a pull request
- Asked whether a feature is correct, a bug is really fixed, or the tests are enough

## Rules

### V1. Restate the problem, then check the change solves it
Write the problem in the reporter's terms and the observable outcome that would prove it solved. Trace the diff
from the entry point an application uses to that outcome.
- **Expected:** the report opens with the problem and the expected observable outcome, then says whether the
  change reaches it.
- **Verified by:** `eval:review-diff-behaviour`

### V2. A test fails without the change
Every claimed behaviour has a test that would be red on the base commit. Check it in a worktree of your own
(`git worktree add`, revert the production change there, run the test there — never in the shared tree the other
reviews read), or reason from the diff, and name tests that pass either way.
- **Expected:** the report names the test(s) proving each claim and flags any claim with no failing-first test.
- **Verified by:** `eval:review-diff-behaviour`

### V3. Every path and engine the guarantee must hold on is tested
Bus send by class, routed send, gateway, console command, async consumer, the backfill/reconstruction path;
in-memory and DBAL; PostgreSQL, MySQL, MariaDB where storage is touched (`framework-testing` rule 10a,
`framework-storage-and-transactions` rules 9 and 16c).
- **Expected:** untested entry points or engines are listed as findings.
- **Verified by:** `eval:review-diff-behaviour`

### V4. Edge cases are covered
At least: the failure path (handler throws, database down, rollback, retry), repeated delivery, concurrency,
empty input, a misconfiguration (refused at bootstrap with an asserted message), async vs sync, time boundaries.
- **Expected:** the report lists each relevant edge case as covered (with the test) or missing.
- **Verified by:** `eval:review-diff-behaviour`

### V5. Tests assert what an application observes
A test using SQL on Ecotone tables, reflection, or a `src/` class proves the implementation, not the behaviour
(`framework-testing` rule 10), and is a finding.
- **Expected:** internal-coupled tests are flagged with the userland alternative.
- **Verified by:** `eval:review-diff-behaviour`

### V7. Changed skills are evaluated, not only read
CI checks a skill's format but never runs a model. When the diff touches `.agents/skills`, `api/skills`,
`.agents/evals` or `api/evals`, run `bin/test-agent-skills` (in your own worktree) for the changed skills. Report
each case's score and every failing grader as a finding: below the threshold is BLOCKING, and a failing grader
names the rule it grades. Also check every new or changed rule has a grader in a case.
- **Expected:** for a diff that changes a skill, the report contains `bin/test-agent-skills` scores per case and
  ranks a failing case as BLOCKING; it never defers skill evaluation to CI.
- **Verified by:** `eval:review-skill-change`

### V8. The change works with the features it meets, and refuses the ones it must not be combined with
An application never uses a feature alone. Build the list of Ecotone features from the tree, not from memory: the
public skills under `api/skills/ecotone-*` (handlers, aggregates, event sourcing and projections, sagas and
workflows, interceptors, asynchronous channels, resiliency, distribution, metadata, identifier mapping, business
interfaces, Enterprise features) and the package table in `framework-orientation`'s `references/project.md`. For
each feature the change can meet in one application, one of three holds. It *works together*: a test runs the
change combined with that feature (the new attribute on an `#[Asynchronous]` handler, on an event-sourced
aggregate, inside a saga, with the outbox, with multi-tenancy). It *must not be combined*: the combination is
refused at bootstrap with a message naming the way out (`framework-error-handling`) and a test asserts that
message; a combination that silently does nothing or misbehaves at runtime is a finding. Or it is *unrelated*,
which the report says in one line.
- **Expected:** the report lists the Ecotone features the change can be combined with, and for each one names the
  test proving the combination works or the test proving it is refused at bootstrap, or files the missing test or
  missing guard as a finding.
- **Verified by:** `eval:review-feature-combinations`

### V6. Findings are ranked and actionable
`[BLOCKING|SHOULD-FIX|NIT] <rule id> — <file>:<line> — the scenario that fails — the test to add or the fix`.
- **Expected:** each finding has severity, rule id, location, a concrete failing scenario and a fix.
- **Verified by:** `eval:review-diff-behaviour`
