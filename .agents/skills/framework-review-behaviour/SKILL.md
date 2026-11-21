---
name: framework-review-behaviour
description: >-
  Review session that checks a change to the Ecotone framework actually works: the feature
  or fix solves the stated problem, the tests prove it at the userland level on every path
  and engine it must hold on, edge cases (failure, retry, concurrency, empty, async,
  misconfiguration) are covered, it is tested combined with the existing Ecotone
  features it works with and refused with the ones it must not be combined with, and it
  holds when one process handles several messages at once on Swoole or OpenSwoole
  coroutines. Use when running the feature and behaviour review before a push or PR, or
  when asked whether a diff's tests are sufficient, whether a fix really fixes the bug, or
  whether a change is safe to run on coroutines in the ecotone-dev repository.
---

# Feature and behaviour review

You are one of four independent reviews (see `framework-pull-request` rule P3). You judge only whether the change
does what it claims and whether the tests prove it. You review against the
[Behaviour section of the review list](../framework-pull-request/references/review-list.md#behaviour): you decide every
requirement there (the BH ids), and the rules below say how to judge them: V1 for BH1, V2 for BH2, V3 for BH3 and
BH4, V4 for BH5 to BH11, V5 for BH12, V7 for BH13, V8 for BH14, V9 for BH15 to BH21.

## Applies when

- Running the behaviour review session before pushing or opening a pull request
- Asked whether a feature is correct, a bug is really fixed, or the tests are enough
- Asked whether a change is safe on Swoole or OpenSwoole coroutines, or with fibers

## Rules

### V1. Restate the problem, then check the change solves it
Write the problem in the reporter's terms and the observable outcome that would prove it solved. Trace the diff
from the entry point an application uses to that outcome. That paragraph is the reasoning, not the decision: BH1
still gets its own status line with the others (rule V6), `missing` when the trace depends on something the diff or
a test does not show, and then BH1 is among the ids of the `NOT PASSED` line.
- **Expected:** the report opens with the problem and the expected observable outcome, then says whether the
  change reaches it.
- **Verified by:** `eval:review-diff-behaviour`

### V2. A test fails without the change
Every claimed behaviour has a test that would be red on the base commit. Check it by reasoning from the diff: you
review as a session in the worktree the other reviews read, so never revert, edit, switch or stash it and never
create a worktree of your own (`git worktree add`) to do so. You may run a test on the tree as it is. Proof that a
test was red is what the implementer handed over with the brief: its failing output against the base commit, or a
commit that adds the test before the change it proves (`git log`). Name tests that pass either way, and when
neither proof exists and the diff cannot show the test was red, decide BH2 `missing` and ask for the red run.
- **Expected:** the report names the test(s) proving each claim and flags any claim with no failing-first test.
- **Verified by:** `eval:review-diff-behaviour`

### V3. Every path and engine the guarantee must hold on is tested
Bus send by class, routed send, gateway, console command, async consumer, the backfill/reconstruction path;
in-memory and DBAL; PostgreSQL, MySQL, MariaDB where storage is touched (`framework-testing` rule 10a,
`framework-storage-and-transactions` rules 9 and 16c).
- **Expected:** untested entry points or engines are decided `missing`.
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
`.agents/evals` or `api/evals`, run `bin/test-agent-skills` for the changed skills, on the tree as it is. Report
each case's score and every failing grader: a case below the threshold makes BH13 `missing`, and a failing grader
names the rule it grades. Also check every new or changed rule has a grader in a case.
- **Expected:** for a diff that changes a skill, the report contains `bin/test-agent-skills` scores per case and
  decides BH13 `missing` on a failing case; it never defers skill evaluation to CI.
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

### V9. The change holds when one process handles several messages at once
On Swoole and OpenSwoole coroutines (a server with `enable_coroutine`, a consumer run with
`ecotone:run <channel> --coroutines=8`) and on fibers, one process handles several messages interleaved: an
execution stops at every wait (a query, a broker call, a sleep) and another one runs on the same container and the
same services. Read the diff for each of the six below and report only the ones the diff really has, each tied to
its file and line; the others are not listed as possibilities. A change that has none of them (no service state, no
wait, no consumer) is *unrelated*, which the report says in one line.
- **State on a shared service.** A property written after construction, a static, a cache: every message in flight
  sees it. It is a finding unless it lives in `ExecutionScope` or holds nothing of the message being handled
  (`framework-service-design` rules 3 and 3a).
- **A resource kept across a wait.** A connection, a channel, a transaction or a lock stored in a property or
  handed to a long-lived object is shared by every execution (`framework-storage-and-transactions` rule 16e).
- **Read, wait, write.** Code that reads shared state, waits, then writes what it decided from the read: another
  execution ran during the wait. In one process this did not exist before coroutines.
- **A wait coroutines cannot wait on.** An I/O call the diff introduces, through an extension or a client the
  runtime does not hook, stops every coroutine of the process, and two of them waiting on each other never finish.
  It either waits (proven in the containers below) or is refused with a message naming the way out. The clients
  already in the tree are decided and are not a finding: `ConnectionPool`, for one, refuses PostgreSQL where a
  coroutine cannot wait on it.
- **A consumer.** A new endpoint or inbound adapter that cannot be consumed by several coroutines (it commits a
  position, it runs on a schedule) is refused by `ConsumerCoroutines`: an adapter reading a stream names it in
  `streamsConsumed()`, and a test asserts the refusal.
- **State copied into a coroutine a handler starts.** Only read-only context implements `CopiedToChildExecution`;
  a transaction flag, a collector or a connection copied to the child is a finding.

The proof is a test, not the reading. State is proven by interleaving two executions at the userland level with
`Fiber::suspend()` in a handler, which needs no extension
(`packages/Ecotone/tests/Messaging/Execution/InterleavedExecutionsTest.php`). A change to waiting, to connections
or to a consumer is proven in real coroutines, as in
`packages/Ecotone/tests/Messaging/Execution/CoroutineExecutionsTest.php`,
`packages/Dbal/tests/Integration/CoroutineExecutionsTest.php` and
`packages/Amqp/tests/Integration/CoroutineExecutionsTest.php`, and you run those tests in the `swoole` and the
`openswoole` container, one after the other
([framework-dev-environment](../framework-dev-environment/references/workflow.md)). Such a test is skipped wherever
neither extension is loaded, so CI runs it only when its file is listed in `COROUTINE_TESTS` of
`.github/workflows/test-monorepo.yml`: a new file missing from that list is a finding.
- **Expected:** the report has a part on messages handled at once: for each of the six that the change touches it
  names the code and the test that interleaves two executions or, where the usage is refused, the test asserting
  the refusal; or it files a finding that states the interleaving (which two executions, the wait between them,
  what each then observes) and the test to add; or it says in one line that the change is unrelated. A change is
  never passed on a test that handles one message at a time, and a new test in real coroutines is listed in
  `COROUTINE_TESTS`.
- **Verified by:** `eval:review-coroutine-compatibility`, `eval:review-coroutine-consumer`

### V6. Every requirement is decided: not-apply, covered or missing
Give every requirement of the section a line, as the [review
list](../framework-pull-request/references/review-list.md#deciding-a-requirement) sets out: `<id> — not-apply —
why`, `<id> — covered — the test by name`, or `<id> — missing — <file>:<line> — the scenario that fails — the test
to add or the fix — skill: the skill to load to cover it`. None is left out, also the ones the change plainly does
not touch, and a requirement that asks for a test is `covered` only by a test. Wherever a rule above says a
finding, it means that requirement is `missing`. Open the report with the result, `PASSED` when none is `missing`,
otherwise `NOT PASSED:` and the `missing` ids, and put the `missing` lines first: they go back to the implementing
session to cover, and you decide them again in the re-review.
- **Expected:** the report has a line for every requirement of the section with one of the three statuses, each
  `missing` line naming a location, a concrete failing scenario, the test to add or the fix and the skill to load
  to cover it, and opens with `PASSED` or `NOT PASSED` naming the `missing` ids.
- **Verified by:** `eval:review-diff-behaviour`
