# DCB friction report — group a: conventions and code quality

Retrospective on 7 assigned worktrees from the DCB effort: `implement-no-nullable-services`,
`implement-stateless-dcb-services`, `implement-blackbox-tests`, `implement-dcb-readability`,
`implement-dcb-cohesion`, `implement-dcb-read-path-hygiene`, `implement-expression-failure-context`.

**Method.** These branches are stacked, so a raw `git log base..branch` mixes in every earlier unit's commits.
Each branch's own contribution below was isolated either from its two-parent merge commit into
`dgafka/ecotone-2-0-dcb-design` (`git show -s --format='%P' <merge-sha>` gives `<base> <branch-tip>`, then
`git log --first-parent <base>..<branch-tip>`), or — where no merge commit exists because the branch was
integrated as a linear continuation — from the same first-parent technique against the preceding unit's tip.
Evidence was cross-checked against committed reports and specs still sitting in each worktree under
`docs/superpowers/`.

| Unit | Own commit range | Count |
|---|---|---|
| `implement-no-nullable-services` | `6e51f95cd..eb859ceb8` | 6 |
| `implement-stateless-dcb-services`¹ | `294c0a3d4..f575d7c60` | 9 |
| `implement-blackbox-tests` | `91518daf5..04e831b55` | 22 |
| `implement-dcb-cohesion` | `f575d7c60..0548c4130` | 7 |
| `implement-dcb-readability` | `0548c4130..40eacaa22` | 7 |
| `implement-dcb-read-path-hygiene` | `13858fe7..c9f130d70` | 4 |
| `implement-expression-failure-context` | `6796d17ff..f5bdda536` | 7 |

¹ The `dgafka/implement-stateless-dcb-services` branch ref itself points one commit past this range (a lone
per-package-isolation test commit); the actual "stateless DCB services" content — the work the unit's name
promises — is this 9-commit range, identified by content (transactions-required, singleton removal, optimistic
locking) rather than by ref name. Worth flagging on its own: a stacked worktree's branch pointer can drift from
the concern it was named for, which makes exactly this kind of retrospective harder than it needs to be (see
finding 8).

---

## Ranked findings

### 1. Tests written against internals get rewritten wholesale, later, as a dedicated unit

**What happened.** The entire `implement-blackbox-tests` unit — 22 of its 22 own commits — is test rewrites, not
new coverage. Every commit replaces one of: direct construction of `InMemoryEventStore` /
`OpenCoreAppendStrategy` / `OpenCoreInMemoryTagCollaborator` instead of `EcotoneLite::bootstrapFlowTesting()`;
raw reads of `ecotone_tagged_events` / `ecotone_tag_versions` / `ecotone_event_stream` instead of asserting
through the gateway; `EventStore::RAW_REFERENCE` instead of `getGateway(EventStore::class)`; or a
`DbalTagVersionRegister` unit test instead of a real-database contention test. Two of the 22
(`f2ef0469a`, `f383622f4`) are "restore exact ... guarantees" commits — the rewrite itself over-corrected and
had to be walked back, i.e. meta-churn on top of the churn.

**How many times.** 22 commits, one whole worktree, touching at least 10 distinct test files across
`packages/PdoEventSourcing`, `packages/Symfony` and `packages/Laravel`.

**Evidence.** `c11b863dc`, `3c5bdf306`, `2f54403fe`, `8b7fec7f7`, `4a2acf01d`, `cb1ca48be`, `e7e2c11a8`,
`191178d36`, `e53928a3e`, `3087bd642`, `8065c0b19`, `d15d2627d`, `9e1d89d43`, `70dad03b8`, `d3920e4f2`,
`f000f86ef`, `817b20250`, `f2ef0469a`, `f383622f4`, `50354a085`, `04e831b55`, `c590919da`.

**The instruction that would have prevented it.** State this in every DCB (and by extension every Ecotone)
task brief, not just in a skill or a memory file consulted after the fact: *"Every test you write for this unit
must go through `EcotoneLite::bootstrapFlowTesting()` and the public gateway. Assert on observable behaviour —
thrown exceptions, returned models, values read back through `getGateway(EventStore::class)` — never construct
`DbalEventStore`/`InMemoryEventStore`/a tag collaborator directly, never `SELECT` an `ecotone_*` table, never use
`EventStore::RAW_REFERENCE` in a test."* This already exists as a standing rule
(`feedback_no_reflection_tests.md`); the finding is that it was not being *applied* while the DCB tests were
originally written, only enforced retroactively by a dedicated sweep.

**Category.** Coding rule (already exists — the gap is enforcement timing) + task-brief template item (repeat
the rule verbatim in every brief that asks for a new test, don't rely on the writer having internalised it).

**Cost.** 22 commits / one full worktree devoted to zero new behaviour, purely paying down a testing-style debt
that a one-line reminder in each originating brief would have avoided.

---

### 2. A known "no nullable service dependencies" rule was not applied while building, then swept afterward

**What happened.** `InMemoryEventStore`, `DbalEventStore`, `DecisionModelParameterLoader` and
`InMemoryEventSourcedRepository` all shipped with `?AppendStrategy`/`?TagCollaborator`/
`?ProjectionInvariantGuard`/`?AttributeExpressionExecutor` constructor parameters defaulting to `null`, each
guarded by a null-check at the call site. `implement-no-nullable-services` is a 6-commit unit whose entire job
is deleting these defaults, because — per its own commit messages — "every real construction site already
passed concrete instances." The rule that services must be registered unconditionally and gated at runtime
(not via nullable wiring) already exists (`feedback_no_nullable_services.md`); this unit is evidence it was not
checked against while the original DCB store/module code was written.

**How many times.** 6 commits, 4 distinct classes.

**Evidence.** `3e9e92d56`, `7f7d24bc0`, `65ba2a852`, `84e70e8c4`, `e15bd042f`, `eb859ceb8`.

**The instruction that would have prevented it.** Add "no nullable service constructor parameters — register
unconditionally, gate behaviour at runtime" to the Definition-of-Done checklist a unit is verified against
before it is marked complete, not only to the coding-conventions document a writer may or may not re-read.

**Category.** Coding rule (already documented) + task-brief/Definition-of-Done template item (verification
step, not just a style guide entry).

**Cost.** 6 commits, one dedicated worktree.

---

### 3. A locking mechanism was built, found to leak state across transactions, and reverted

**What happened.** An earlier (out-of-scope for this group) unit built "InnoDB own-bump snapshot tracking" —
per-connection state inside `DbalTagVersionRegister` to avoid re-querying tag versions. Inside
`implement-stateless-dcb-services`, a RED test (`424220979`, "InnoDB own-bump tracking must not leak into the
next transaction") proved the tracking survived across transactions on a pooled connection, which is exactly
the shape of bug the project's stateless-services rule exists to prevent. The very next commit
(`ca8a1b0fc`) rips the mechanism out: *"The guarded UPDATE is the sole conflict mechanism;
`DbalTagVersionRegister` no longer keeps per-connection state."* This is the textbook reversal shape the brief
asked me to hunt for.

**How many times.** One mechanism, built once, reverted once, but built and reverted in two different units —
so the cost lands on a later unit's schedule, not the one that introduced it.

**Evidence.** Original: `4da95c42d`, `dc8ca145e` (different, unassigned unit). Reversal: `424220979` (RED test),
`ca8a1b0fc` (revert), documented in `ad8eea1df`.

**The instruction that would have prevented it.** State the "no server-side/per-connection state between
requests" stateless-services constraint *before* a locking or version-tracking mechanism is designed, and write
the cross-transaction-leak test first — the RED test in this unit is exactly the test that should have gated
the original mechanism's merge, not followed it by however many units later.

**Category.** Process step (a stated invariant + a test-first requirement for any new state-carrying
mechanism), belongs in `AGENTS.md` or the DCB-specific design doc's constraints section, not in
coding-conventions (this isn't a style rule, it's a design constraint that has to be stated per-feature).

**Cost.** Unknown exact size of the discarded mechanism (built in an unassigned unit), but a full design
approach — schema/index work and its own tests — was built, shipped, and torn out one unit later.

---

### 4. Aggregate saves bumped tag counters unconditionally — a real concurrency-guarantee bug shipped and was fixed later

**What happened.** `implement-dcb-cohesion` commit `c09bac1e0` — *"guard every tagged append on the tag
versions captured inside the transaction"* — states plainly that until this fix, "aggregate saves ... bump[ped]
tag counters unconditionally," meaning a competing commit made after an aggregate was loaded would **not** be
caught by `DecisionModelConcurrencyException`. That is a correctness bug in the core DCB guarantee (the whole
point of the feature), shipped in an earlier unit, silently, and fixed two units downstream.

**How many times.** One commit fixes it, but it is the kind of bug a black-box test should have caught at the
point the append path was first written — there is no earlier commit in this group's evidence adding a
concurrent-writer test for the aggregate-save path specifically.

**Evidence.** `c09bac1e0`.

**The instruction that would have prevented it.** *"Any change to the tagged-append path must include a test
that proves a competing commit — made after this handler's load, before this handler's save — is rejected with
`DecisionModelConcurrencyException`. This applies to every entry point that appends under a tag condition,
including aggregate saves via `SaveAggregateService`, not only the DCB-specific handler path."* Write that test
before the append-path code, not after a downstream unit notices the gap.

**Category.** Coding rule / correctness invariant — belongs in `docs/coding-conventions.md` as a required test
shape for any tag-guarded append, and in the DCB design spec as a stated guarantee with its own test name.

**Cost.** 1 commit to fix, but represents a real concurrency hole that shipped for at least one intervening
unit's worth of time.

---

### 5. Backfill silently excluded filter-only tags that the append path already included

**What happened.** `implement-dcb-cohesion` commit `0548c4130` — *"backfill indexes filter-only tags too,
sharing `EventsTags::sequencedBy` with the append path"* — fixes an asymmetry: the live append path indexed
filter-only tags (tags used only to narrow a query, never counted for optimistic locking), but the backfill
path that reconstructs the index from history did not, so a backfilled index would silently disagree with a
live one for any filter-only tag.

**How many times.** One occurrence, but it is a second instance of the same shape as finding 4 — a guarantee
that holds on the "live" code path but was not verified to hold on the "backfill/reconstruction" code path for
the same concept.

**Evidence.** `0548c4130`.

**The instruction that would have prevented it.** *"Any new behaviour added to `EventsTags`/tag indexing on
the append path must be mirrored by a test that runs the same scenario through the backfill path and asserts
identical indexing — the two must share one method, not two independently-maintained ones."*

**Category.** Coding rule / test-coverage requirement — a "parity test between live and backfill" pattern,
belongs in `docs/coding-conventions.md` under any future "dual code path" guidance.

**Cost.** 1 commit; low visible cost, but the second occurrence of the live/reconstruction-parity gap shape
(see also finding 4) means this is a class of bug, not a one-off.

---

### 6. The same Laravel test-fixture hygiene fix was made twice, in two different branches in this group

**What happened.** `implement-stateless-dcb-services` (`f575d7c60`, *"keep the DcbSmoke fixture's
bootstrap/cache directory like the other Laravel fixtures"*) and `implement-dcb-cohesion` (`ff8dab584`,
*"stop tracking laravel.log in the DcbSmoke and DbalConnectionRequirement fixtures"*) both fix the same class
of problem: running the Laravel `DcbSmoke` test fixture generates `laravel.log` / `bootstrap/cache` contents
that git then tracks, because the fixture's own `.gitignore` doesn't cover them the way older Laravel fixtures'
do. Each worktree that touches this fixture rediscovers and re-fixes it independently.

**How many times.** 2 occurrences within this group's 7 branches alone; the pattern (a test fixture missing the
same `.gitignore` coverage other, older fixtures already have) is the kind of thing likely to recur in any
future branch that runs this fixture.

**Evidence.** `f575d7c60`, `ff8dab584`.

**The instruction that would have prevented it.** This is not a briefing problem, it's a one-time repo fix:
add `laravel.log` and `bootstrap/cache/*` to the `DcbSmoke`/`DbalConnectionRequirement` fixture directories'
own `.gitignore` (matching the pattern the other Laravel fixtures already use), once, so no future branch needs
to rediscover it.

**Category.** Tooling/environment fix — belongs in the repo (a `.gitignore` entry), not in any process
document. Cheapest possible win in this report.

**Cost.** 2 commits so far; trivial to fix once, guaranteed to recur (at low cost each time) until then.

---

### 7. The design spec drifted from the shipped names and decisions, and needed a dedicated readability pass to catch up

**What happened.** `implement-dcb-readability` opens with a full review
(`docs/superpowers/specs/2026-09-28-dcb-readability-review.md`, commit `eec9625d5`) that found, among ten
ranked issues: the designated first-read spec document still used draft table/column names
(`ecotone_event_tags`, `tag_version`) that don't exist in the shipped code (`ecotone_tagged_events`,
`tag_sequence`); the same two numbers (`version`, `sequence`) were named five different ways across the
codebase; the same "is DCB on" decision was made independently in four modules; and a documented guarantee
(*"a decision model scoped only by a filter-only tag name is a bootstrap `ConfigurationException`"*) was not
implemented anywhere. The unit then spent 6 more commits implementing only 6 of the 10 ranked proposals
(1, 2, 9, 7, 8, 10); proposals 3 (type the design's nouns instead of arrays — touches public `Api/`), 4
(collapse `AppendStrategy` into the tag collaborator), 5 (stop passing the store into its own collaborators —
the largest item) and 6 (name the DCB-handler concept, collapse four parallel maps into one) were correctly
deferred as separate, larger calls rather than rushed in — see "rejected candidates" below.

**How many times.** One dedicated review unit + 6 remediation commits; 4 of 10 findings still open at the time
of this report.

**Evidence.** `eec9625d5` (review), `0fc701ada`, `a8f23212c`, `74f7bba62`, `1a9dd2681`, `a6bd5f26e`, `40eacaa22`
(remediation).

**The instruction that would have prevented it.** *"Any unit that renames or moves a class, table or column
that the design spec documents must update the spec's worked examples and DDL in the same commit — the spec is
part of the unit's Definition of Done, not a follow-up."* Applied continuously, this would have caught the
naming drift as it happened rather than needing a dedicated archaeology pass at the end.

**Category.** Process step — a Definition-of-Done addition ("spec kept in sync with shipped names"), belongs
in `AGENTS.md` next to the other DCB-adjacent process guidance, not in the coding-conventions style rules.

**Cost.** 7 commits so far (1 review + 6 fixes), plus 4 ranked proposals still outstanding — real but bounded
future rework already scoped and ready to pick up.

---

### 8. Task-brief scope was ambiguous enough that the coordinator had to be asked mid-unit, in 3 of the 7 assigned units

**What happened.** Two units in this group document, in their own committed reports, a point where the
original brief did not settle a scope question and the coordinator had to be asked directly, mid-implementation:

- `implement-expression-failure-context`'s report lists two such points: whether a *convention-path* failure
  (one that doesn't go through an expression at all) should also switch to the new unified exception — resolved
  as "no, only the expression branch" — and whether "`#[Deduplicated]` is excluded because it lives in
  `packages/Dbal`" meant excluding *tests* for it too, or only production code — resolved as "production code
  only."
- `implement-dcb-read-path-hygiene`'s report has an explicit "Scope decision (coordinator, 2026-09-29)"
  paragraph: the brief said "the `#[Fetch]` capture/load in DCB handlers" should get the missing-table fix, but
  the *load* half of that can't be isolated from the ordinary aggregate-load path without a flag the brief had
  already ruled out, or a second repository adapter owned by a different, parallel unit — so the coordinator
  had to narrow the brief's own scope live.

**How many times.** 3 distinct clarifying exchanges across 2 of the 7 units in this group (roughly 3 of 7 units
needed at least one).

**Evidence.** `docs/superpowers/specs/2026-09-29-expression-failure-context-report.md` §"What the direction did
not settle" items 2–3; `docs/superpowers/specs/2026-09-29-missing-table-instructions-audit.md` §1 "Scope
decision."

**The instruction that would have prevented it.** Every brief that lists included/excluded packages or paths
should say explicitly whether the exclusion covers tests as well as production code, and whether a "handle X in
DCB handlers" instruction is expected to reach every code path X touches (including ones shared with
non-DCB features) or only the DCB-exclusive ones — stating the boundary up front costs one more sentence in the
brief and saves a blocking round-trip mid-unit.

**Category.** Task-brief template item — two boilerplate sentences that should appear in every scoped-exclusion
brief.

**Cost.** No rework, but 3 blocking round-trips across 7 units (~43% of this group needed one) — not free, even
though each was resolved correctly.

---

## What went right

- **Audit-doc-first, then one commit per line item.** Both `implement-dcb-read-path-hygiene` (the missing-table
  audit table, §2 of its report, `✓`/`→`/`~` per call site) and `implement-dcb-readability` (the ten ranked
  proposals) front-loaded a complete survey before writing any code, then implemented against that survey one
  item at a time. Both units landed clean, single-purpose commits with no walk-backs. Worth prescribing as the
  default shape for any "sweep the codebase for X" unit.
- **The expression-failure-context report's per-producer message table.** Listing the exact exception message
  for every producer, each asserted verbatim by a named test, made verification mechanical and gave the
  reviewer (and this retrospective) a direct way to check the work without re-deriving it. Worth keeping as the
  standard report shape for any unit that changes an error/exception surface.
- **`implement-no-nullable-services` and `implement-dcb-read-path-hygiene` were both clean, single-purpose
  sweeps** — every commit maps 1:1 to one item on a pre-written list, with zero corrective follow-up commits
  inside the unit itself. This is the shape to imitate: a bounded, enumerable list of sites, one commit each.
- **Deliberate, explicit deferral of risky items.** The readability review flagged proposal 3 ("touches
  `AppendCondition`'s public array-returning accessors ... needs a deliberate call") and proposal 4
  ("names decided by the maintainer ... would disappear — flag before doing") as out of scope for this pass
  rather than rushing them in. That's the correct behaviour for a Api/-surface or maintainer-named-thing change,
  not friction — see rejected candidates.

## Rejected candidates

Friction judged inherent to the problem, not fixable by a better brief or a new rule:

- **The InnoDB own-bump reversal (finding 3) required a real database under real contention to be provable
  wrong.** The RED test that caught it needs a live MySQL/PostgreSQL connection pool with a concurrent second
  writer — that class of bug is not reliably catchable by review or a stated constraint alone, only by writing
  exactly the test that caught it. The actionable part (write that test *before* the mechanism, not after) is
  already captured in finding 3; the underlying fact that some correctness bugs only surface under real
  contention is not something a brief can eliminate.
- **4 of the 10 readability-review proposals remaining unimplemented is not friction.** Proposals 3–6 were
  explicitly identified as touching public `Api/` surface or maintainer-decided names, and deliberately deferred
  for a separate call rather than folded into this unit. Correctly scoping a large refactor into
  ship-now/decide-later is the desired behaviour, not a gap.
- **Needing a dedicated `implement-stateless-dcb-services` unit to remove state introduced elsewhere is partly
  inherent to working in parallel stacked worktrees**: two units can each look locally consistent (one adds
  tracking for performance, another enforces statelessness elsewhere) and only conflict once integrated
  first-parent into the shared branch. The specific bug (finding 3) was preventable with a test-first
  discipline; the general fact that parallel worktrees can build locally-reasonable, globally-incompatible
  mechanisms is a property of the stacking strategy itself, not something a per-unit brief fixes.

---

## Summary for prioritisation

| # | Finding | Category | Cost | Fix effort |
|---|---|---|---|---|
| 1 | Tests against internals rewritten wholesale | coding rule + brief template | 22 commits, 1 unit | Add rule sentence to every DCB test brief |
| 2 | Nullable service deps swept after the fact | coding rule + DoD checklist | 6 commits, 1 unit | Add DoD checklist line |
| 3 | Stateful locking mechanism built then reverted | process step (design constraint + test-first) | 1 discarded mechanism + 2 commits | State constraint before design; test-first |
| 4 | Unconditional tag-counter bump on aggregate save | coding rule (correctness invariant) | 1 commit, real bug window | Add required-test-shape rule |
| 5 | Backfill/live indexing parity gap | coding rule (test-coverage) | 1 commit | Add parity-test pattern |
| 6 | Laravel fixture hygiene fixed twice | tooling/repo fix | 2 commits, will recur | One `.gitignore` fix |
| 7 | Spec drifted from shipped names | process step (DoD: spec sync) | 7 commits, 4 items still open | Add DoD checklist line |
| 8 | Brief scope ambiguity needed live clarification | brief template | 3 round-trips / 7 units | Add two boilerplate sentences to brief template |
