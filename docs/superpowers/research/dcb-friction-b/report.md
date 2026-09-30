# DCB friction retrospective — group b: review cycles, edge cases, engine portability

Scope: `dgafka/implement-dcb-edge-cases`, `dgafka/implement-dcb-review-fixes`,
`dgafka/implement-dcb-review-followups`, `dgafka/implement-aggregate-load-defects`,
`dgafka/implement-dbal-mysql-green`, `dgafka/implement-unified-event-store`.

Method: each branch's own contribution was isolated with
`git log --first-parent <fork-point>..<tip>`, where `<fork-point>` is the last merge
commit of a *different* worktree that appears in that branch's first-parent history
(found via `git merge-base` against sibling branches and by reading merge-commit
subjects). Full commit bodies were read for every commit attributed to these six
branches — not just subjects. All SHAs below are as of this worktree's checkout.

## Ranked findings

### 1. `InMemoryEventStore` and the DBAL store silently disagreed on behaviour (3 occurrences, 3 different branches)

**What happened.** Three separate times, a behaviour was implemented (or fixed) for
the DBAL/PDO event store and the in-memory store was left divergent, discovered only
later:

- `d59bd5989` (edge-cases): `DbalEventStore` treats appending zero events as a no-op;
  `InMemoryEventStore` raised a conflict for a stale condition on the same no-op case
  — "a flow test disagreed with production."
- `ade73e6c5` (unified-event-store): unifying `TaggedEventStore`/`AggregateEventStore`
  into `EventStore` required *adding* aggregate optimistic-lock enforcement to
  `InMemoryEventStore` because DBAL already had it via its unique index — the two
  stores had never actually enforced the same guarantee.
- `503d70bc7` (review-followups): the in-memory flow-testing repository held the
  `EventStore` *gateway* reference while every other caller (and the one OpenTelemetry
  decorates) uses `EventStore::RAW_REFERENCE`, so a conditional append from an
  in-memory aggregate silently produced no tracing span, unlike the same save on DBAL.

**Why it cost time.** Each is a separate commit, on a separate branch, discovered at a
different point in the project (edge-case audit, store-unification refactor, and a
tracing feature add). The in-memory store exists specifically so
`EcotoneLite::bootstrapFlowTesting()` tests can stand in for the real database — every
time it silently diverges, tests using it are validating the wrong thing.

**Prevention instruction.** *"Any test or behaviour that exercises `EventStore` must
run against both the in-memory and at least one DBAL implementation via a shared,
parametrized conformance suite (append-condition matching, empty-append handling,
which service reference is used for gateway/tracing decoration). When adding new
`EventStore` behaviour, add the assertion to that shared suite, not to a DBAL-only or
in-memory-only test — the fixture choice must not be a place bugs can hide."*
This is a coding-conventions-level rule (`docs/coding-conventions.md`, event-sourcing
section) and a test-authoring rule (`dgafka:ecotone-testing` skill).

**Cost estimate.** 3 commits directly, but each sits inside a larger unrelated unit of
work (an edge-case audit, a store unification, a tracing feature), so the real cost is
that none of those three units could be verified complete without first re-discovering
this gap.

### 2. No bootstrap-time validation for DCB configuration invariants (18 commits, 1 branch)

**What happened.** `implement-dcb-edge-cases` is 20 commits (18 of them `fix(dcb):`)
found by a dedicated edge-case audit after the DCB feature had already shipped through
design, core implementation, and one review-fix round. Every one of the 18 fixes is
the same shape: a rule already stated in the design spec or upgrade guide had no
enforcing code, so misconfiguration ran silently instead of failing at bootstrap or
naming the real cause. Examples (all `dgafka/implement-dcb-edge-cases`,
range `40eacaa22..fa344d080`):

- `083b9edbd` — a model whose handled events share no `#[EventTag]` name "folded
  nothing and guarded nothing" — no error.
- `9984fae25` — a class carrying both `#[DecisionModel]` and `#[Aggregate]`/`#[Saga]`
  "booted silently with two contradictory roles."
- `39d420535` — narrowing an `or()` criteria combination "silently selected something
  else" instead of throwing.
- `6ef6e48df` — a command property holding an array for a model's tag "silently scoped
  the model by its first element."
- `823dd41c3` — a model scoped only by filter-only tags "ran with a condition that
  guards nothing."
- `c48e1b7c7` — an unknown filter-only tag name "booted silently and left the real tag
  counted."
- `d92883148` — tag value length counted bytes instead of characters, and NUL/invalid
  UTF-8 "reached the driver as an opaque error."
- `d90ae125f`, `383de545a`, `b984c8d41`, `24edcf663` — more of the same class
  (non-static boundary methods, nullable tag the message can't supply, unvalidated
  `#[Fetch]` values, tags lost on event subclasses).

A further 6 of the 18 (`7cf42b477`, `f85f057a8`, `d8f81c26b`, `c9b54d3f9`,
`d2a0f5171`, `d3a25ac3c`) are not missing validation but *wrong* error messages: an
error *was* raised, but it named the wrong table, the wrong cause, or a fictitious
resume position.

**Why it cost time.** This is not scattered review feedback — it is a single
after-the-fact audit that found an entire *class* of gap (missing bootstrap
validation, plus untested error-message content) systematically across the whole DCB
surface. Every one of these had to be found by someone deliberately trying to misuse
the feature, one axis at a time, after the feature was believed done.

**Prevention instruction.** *"Every new DCB configuration rule stated in the design
spec (a scope needs a tag name, a model can't double as an aggregate, a `#[Fetch]`
value must normalise like an event tag value, etc.) ships in the same commit as: (a) a
bootstrap-time `ConfigurationException` naming the offending class/model/tag and the
fix, and (b) a test asserting the exception's message content
(`assertStringContainsString`), not just its class. A spec sentence with no such test
is an edge case waiting to be found later."* This is a coding-convention rule
(bootstrap validation + message-content assertions) and a task-brief-template item:
every brief that introduces a new attribute/config surface should require an explicit
"what happens when this is misused" pass before it is considered done, not as a
follow-up branch.

**Cost estimate.** 20 commits, one whole dedicated worktree/branch, after the fact.

### 3. Enterprise licence-boundary leak found only in review (2 commits, large diffs)

**What happened.** `a7f08f6a7` and `7bf1082a2` (`implement-dcb-review-fixes`) found
that `DbalEventStore` and `InMemoryEventStore` — both licensed Apache-2.0 — contained
the *entire* DCB tag protocol inline: `appendEventsWithTagCondition`,
`loadByCriteria`'s capture/read, `backfillTagsForStream`, the InnoDB
snapshot-hazard bookkeeping, the deadlock/lock-code SQL-state mapping. As the commit
says: *"That is Enterprise logic living in an Apache-2.0 file."* Fixing it required
extracting five new classes per store family
(`DbalTagVersionRegister`, `DbalTagConditionalAppender`, `DbalTaggedEventReader`,
`DbalTagBackfiller`, `EnterpriseDbalTagCollaborator`/`OpenCoreDbalTagCollaborator`,
and the in-memory equivalents) wired through `LicenceDecider`, mirroring the pattern
`EnterpriseAppendStrategy` already used elsewhere in the same codebase.

**Why it cost time.** The correct pattern (`LicenceDecider::prepareDefinition`,
open-core/Enterprise split classes) already existed in the codebase for
`AppendStrategy` at the time the tag protocol was written inline — this was not an
undiscovered pattern, it was an existing convention not applied to new code, caught
only by a dedicated review pass rather than at write time.

**Prevention instruction.** *"Before writing an implementation for Enterprise-only
behaviour (DCB tags, counters, criteria matching, or anything else gated by
`LicenceDecider`), check whether the store class you're editing is Apache-2.0. If it
is, the Enterprise logic goes in a separate class behind the existing
open-core/Enterprise collaborator-interface pattern from the first commit — not as a
later extraction."* Belongs in `docs/coding-conventions.md` (licensing/module
boundaries section) as a concrete rule, not just a review checklist item.

**Cost estimate.** 2 commits, but each is a multi-file extraction refactor across a
whole subsystem (5–6 new classes per store), done after the feature was believed
complete.

### 4. Documented guarantees shipped with no test proving them (≥5 commits)

**What happened.** Several `implement-dcb-review-fixes` commits are pure test
additions for behaviour that was *already true and already documented*, just never
pinned:

- `ef6df5468` — "no test proves" the open-core/Enterprise split at the DBAL boundary.
- `0161ed16b` (M5) — the MariaDB 1020 / PostgreSQL 55P03 error-code mapping to
  `ConcurrencyException` had "zero test coverage anywhere in the tree."
- `4e510a054` (N9) — §4.5's central "counters first" atomicity claim "had no test
  forcing a genuine mid-append failure with no ambient transaction."
- `70a7dd4bf` (N10) — "every existing retry test engineers exactly one conflict... none
  forced every attempt to fail," leaving the operationally important failure path
  (exhausted retries) unverified.
- `94715ee33` (N11) — no test read back `tag_sequence` ordering for a backfill at a
  small batch size, the exact scenario the design doc's trade-off note (N5) depends on.

**Why it cost time.** In each case the code was correct; a review had to independently
re-derive the guarantee from the design doc and notice no test named it. This is
review effort spent re-verifying claims that should have been self-evident from the
test suite.

**Prevention instruction.** *"When a design-doc section makes a specific claim
('counters first means a lost condition writes nothing', 'a MariaDB 1020 maps to
ConcurrencyException'), the commit that implements it adds a test whose name states
the claim, in the same commit. A guarantee is not done until it has a test that would
fail if the guarantee were removed."* This is a `dgafka:test-driven-development` /
`dgafka:ecotone-testing` skill rule, and a task-brief-template item: cite the spec
section being implemented, and require the PR to point at the test proving it.

**Cost estimate.** 5–6 commits of review-fix-only test writing, one full review round.

### 5. New interface methods / attributes not wired through every dispatch path (3 commits, 2 branches)

**What happened.** Twice, a new capability was added to one call path and shipped
without checking the others:

- `implement-dbal-mysql-green`: `57c076084` made `#[WithoutDatabaseTransaction]`
  honoured for handlers dispatched through the `CommandBus` gateway wrapper. The very
  next commit, `9ae4e3fd3`, found the *same attribute* was still not honoured for a
  class-routed `CommandBus::send()` (no explicit routing key), because that path
  resolves routing differently (`BusRoutingKeyResolver` falling back to the payload's
  class name) and the first fix didn't cover it.
- `implement-unified-event-store`: `ade73e6c5`/`1be829b95` added `loadByCriteria` to
  the unified `EventStore` interface; `99daa69ba` had to fix it afterward because it
  was never registered as a gateway action in `EventSourcingModule`, so `EventStore`
  obtained from the container or gateway didn't expose it at all.

**Why it cost time.** Same root cause both times: Ecotone has multiple ways to reach a
handler or a store (direct call, `CommandBus::send()`, `CommandBus::sendWithRouting()`,
gateway/container reference), and a change that only updates one of them looks
complete until someone exercises another.

**Prevention instruction.** *"When adding a new interface method or a new
per-handler/per-message attribute, enumerate every dispatch entry point it must work
through (direct call, class-routed bus send, routed bus send, gateway registration)
as a checklist in the same commit or PR description, and add one test per entry
point."* This is a task-brief-template item — the brief for "add X to the event
store / add attribute Y" should list the entry points to check, not assume the author
will find them all.

**Cost estimate.** 3 corrective commits across 2 branches; low per-commit cost, but a
repeat of the identical mistake shape is a missing checklist, not bad luck.

### 6. MySQL/MariaDB implicit-commit-on-DDL is a recurring, not one-off, source of rework

**What happened.** `implement-dbal-mysql-green` (my assigned branch, 2 commits,
`6e51f95cd..9ae4e3fd3`) exists as a *second* dedicated "make MySQL/MariaDB green"
effort — an earlier, separate worktree `implement-mysql-mariadb-green` (merged at
`22fe48dbc`, not in this group's scope) already targeted the same class of problem.
Both `57c076084` and `9ae4e3fd3` are about the same root cause: MySQL/MariaDB commit
DDL implicitly, so a handler that opts out via `#[WithoutDatabaseTransaction]` still
broke if *any* transaction-wrapping path missed the opt-out, "breaking the
interceptor's later commit with 'There is no active transaction'."

**Why it cost time.** Two differently-named worktrees, weeks apart, both chasing
MySQL/MariaDB-only transaction failures that don't reproduce on PostgreSQL/SQLite —
the engine-specific semantics (implicit commit on DDL) keep resurfacing because
transaction-wrapping is implemented per dispatch path (see finding 5) rather than
centrally.

**Prevention instruction.** *"Any change to transaction wrapping, DDL execution, or
`#[WithoutDatabaseTransaction]` handling must be tested against MySQL and MariaDB
specifically (not just PostgreSQL/SQLite) before merge, because implicit-commit
semantics are engine-specific and will not show up in the other two engines' test
runs."* Belongs in the task-brief template for any PdoEventSourcing/Dbal transaction
change: name MySQL/MariaDB explicitly as engines requiring verification, not just
"run the suite."

**Cost estimate.** A full extra worktree/branch (dbal-mysql-green) plus whatever the
earlier `implement-mysql-mariadb-green` branch cost (out of this group's scope, but
corroborating evidence that the class of problem recurred).

### 7. Store interface split then unified — a maintainer-reversed design decision

**What happened.** `implement-unified-event-store`'s own commits
(`983db0c5a`, `5a5f08572`, `ade73e6c5`, `1be829b95`) fold `TaggedEventStore` and
`AggregateEventStore` — two separate interfaces created earlier in the DCB effort —
back into a single `EventStore`, with an `AppendStrategy` split by licence instead.
`bd255a13a`'s commit message explicitly records "a decision-log entry recording the
2026-09-27 maintainer call" — i.e., the two-interface design was an explicit earlier
decision that a later maintainer review reversed.

**Why it cost time.** Splitting the store into two interfaces first meant every call
site (repositories, gateway registration, the DCB backfill/smoke tests) was written
against the split shape, then had to be rewritten against the unified shape:
`TaggedEventStore`, `AggregateEventStore` and `InMemoryTaggedEventStore` were deleted,
and integration tests/`DcbSmokeTest` moved off the deleted interface onto
`loadByCriteria`.

**Prevention instruction.** *"When a new capability needs to be licence-gated
(Enterprise vs. open-core), default to one interface with a strategy object selected
by `LicenceDecider` (the `AppendStrategy` pattern already used elsewhere) rather than
splitting the interface itself by capability. Splitting interfaces by licence is the
wrong axis — it was tried once for the event store and reversed."* This is a
design-pattern rule best captured as an architecture note (AGENTS.md or a DCB-specific
design doc), for the next feature that's tempted to gate behaviour by adding a
parallel interface instead of a strategy.

**Cost estimate.** 4 refactor/feat commits rewriting the store boundary, plus the
follow-up gateway-wiring fix (finding 5), after the split had already propagated into
tests and docs.

## What went right

- **Escalating design ambiguity into a recorded maintainer decision, not an
  assumption.** Multiple fixes cite an explicit call: `(maintainer, 2026-09-23)` in
  `3695089a1`, `(maintainer decision)` in `823dd41c3`/`c48e1b7c7`/`6ef6e48df`/
  `24edcf663`, and "the 2026-09-27 maintainer call" in `bd255a13a`. Every one of these
  is a genuinely ambiguous rule (does a mixed filter-only+counted scope count as
  filter-only? does a model resolve one tag value or many?) that got answered once,
  in writing, instead of each implementer guessing. Worth keeping as an explicit
  process step: when a design question is genuinely ambiguous, record the answer in
  the commit message or design doc, named as a decision, not folded silently into the
  diff.
- **Benchmarking work doubling as defect discovery.** `4f6d6e22b`
  (`implement-aggregate-load-defects`) measured three *proposed* read optimisations
  against the shipped code and, as a side effect, found two real defects (a
  `tableExists` probe run on every call, and an empty extra page when an aggregate's
  event count is an exact multiple of the load batch size) — cheaper to fix than any
  of the three optimisations being evaluated. Worth recording: a rigorous
  before/after measurement pass over existing code is a cheap way to surface
  correctness bugs, not just a performance exercise.
- **Traceable review-finding IDs in commit trailers.** `implement-dcb-review-fixes`
  commits are tagged with finding codes (`B1`, `M2`, `M4`-`M6`, `N1`-`N12`), letting
  each fix be traced back to a specific review comment rather than a vague "review
  fixes" bucket. The one gap: the review document assigning these IDs was not found
  committed anywhere in this branch's history or its `docs/superpowers/` tree — only
  its *resolutions* are. Recommendation: commit the review report itself (even
  informally) alongside the fixes, so the mapping from finding ID to original finding
  text survives past the fix commits.
- **Commit-message discipline.** Every commit examined for this report states what
  broke, why, and what changed in prose sufficient to reconstruct the defect without
  reading the diff (e.g. `050e760f1`, `9ae4e3fd3`, `36783f980`). This is what made a
  six-branch, ~70-commit archaeology exercise tractable in one pass; it is worth
  stating explicitly as a convention worth preserving rather than assuming it's
  automatic.

## Rejected candidates

Friction judged inherent to the problem, not fixable by a better upfront instruction:

- **The pagination extra-page cost (`36783f980`).** Fixed by asking for
  `loadBatchSize + 1` rows instead of exactly `loadBatchSize`. The commit itself notes
  "no break condition can remove it: a full page says nothing about whether another
  event follows" — this is an inherent property of limit-based pagination without a
  total count, not a process gap. The fix is a normal, cheap consequence of measuring,
  not evidence of a missing instruction.
- **SQLite's RETURNING-clause version floor (`5edb56e666`).** Documented rather than
  runtime-guarded, and the commit explains why: "version detection would need a
  driver-specific probe with no clean seam in the current schema-factory abstraction,
  and the operational fix either way is 'upgrade SQLite'." This is the team correctly
  choosing not to build unneeded runtime machinery — a good call, not friction.
- **The stacked-worktree structure itself.** Reconstructing "this branch's own
  contribution" required `merge-base` lookups and manual first-parent range-finding
  for every one of the six branches (see Method above). This *was* friction for this
  retrospective task specifically, but it is a consequence of a fast-moving,
  deeply-stacked trunk-based workflow across 26 worktrees on one feature — fixing it
  would mean changing how the DCB effort was decomposed into worktrees in the first
  place, which is a coordination-process question for `dgafka:orchestration-coordinator`
  / `dgafka:orchestration-sub-worktree`, not a coding or review-process rule these six
  branches could have followed differently.
