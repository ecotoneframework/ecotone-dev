# DCB friction mining — group c

Scope: `implement-dcb-extension-object`, `implement-dcb-batched-loading`, `implement-dcb-multitenant-backfill`,
`implement-dcb-aggregate-boundary`, `implement-dcb-aggregate-backed-models`,
`implement-decision-boundary-parameters`, `implement-decision-model-snapshots`,
`design-dcb-aggregate-full-tag`, `design-dcb-fetched-aggregates`,
`research-dcb-fold-cost`, `research-dcb-read-comparison`, `research-dcb-single-read-snapshot`,
`research-dcb-dynamic-tag-values`.

Method: these 13 branches are stacked. Each branch's own contribution was isolated with
`git log --first-parent <predecessor-tip>..<branch-tip>` against `dgafka/ecotone-2-0-dcb-design`, after locating
the stack order by finding each branch's tip commit inside `dgafka/ecotone-2-0-dcb-design`'s first-parent log
(`implement-dcb-batched-loading`, `implement-dcb-multitenant-backfill` and `implement-decision-model-snapshots`
merged as their own side-branches instead; their ranges were taken between the merge commit's two parents).
~62 commits were attributed this way. Every finding below cites the commit(s) that are its evidence.

**Cross-check performed before writing anything down:** `docs/coding-conventions.md` and `AGENTS.md` already exist
on this base branch and already cite several commits from these exact 13 branches (the stateless-services rule,
the optimistic-locking rule, the one-word-per-number rule). Where my branches' evidence is already fully captured
there, it is listed in §6 as a cross-reference, not repeated as a new ranked finding — duplicating it would just
give the consolidator two edits to the same paragraph.

---

## 1. Ranked findings

### Finding 1 — A new index/backfill mechanism was designed before checking whether the existing event stream already answered the query

**What happened.** `design-dcb-aggregate-full-tag`'s first commit (`c40c483c7`, "full tags for event-sourced
aggregates — design proposal") proposed indexing every event of every event-sourced aggregate as tag rows in
`ecotone_tagged_events`, with a backfill job to populate the index for existing aggregates. The very next commit
on the same branch (`f84eb145b`) is headed "**Revision 2 — maintainer redirect**" and quotes the correction
verbatim:

> "there should be simpler solution, we already have the events in event stream. Therefore DecisionModel backed by
> aggregate type attribute plus ability to point what matches aggregate id would be sufficient to build the model,
> and then existing aggregate tags would do the trick. we dont need to backfill or populate aggregate type
> identifier on daily basics"

The revised design (§0 of the same file) uses `EventStore::loadAggregateEvents($stream, $type, $id, $fromVersion,
$count, $eventNames)` against the stream's own `(aggregate_type, aggregate_id, no)` index — no new index, no
backfill, no daily population job. The document's own comparison table (§4.5) says revision 2 is "smaller than
revision 1 by an order of magnitude." Revision 1's discarded analysis is kept in Part 5 of the same file "because
its cost arithmetic is now the justification for not building it" — good practice (see §4), but only useful
*after* the wrong turn had already been taken and written up.

**How many times.** Once directly (`design-dcb-aggregate-full-tag`). The same shape — a first-pass proposal
assuming new storage was needed, corrected once someone checked what already existed — recurs in
`research-dcb-single-read-snapshot`'s own "revision 2" (`13858fe70`): "OQ5 is answered again and revision 1's
reason was wrong... the right move is a hand-off through `DecisionModelLoadedState` rather than replacing the
converter." That second instance cost nothing beyond document text (caught and corrected within the same research
pass, before any code existed) — see §5.

**Cost.** Low in this instance, but only because a human maintainer read the proposal before implementation
started and redirected it. Revision 1's own cost estimate for what it would have built — index rows for every
event-sourced aggregate's events plus a daily backfill job — is the counterfactual: had it not been caught, the
cost would have been implementing, testing and later reverting that entire mechanism (the shape of `ca8a1b0fc`,
elsewhere in this effort, at ~110 deleted lines for a much smaller piece of state).

**The instruction that would have prevented it.** Add to the brief for any DCB (or event-sourcing) design task:
*"Before proposing a new index, backfill job, or write-path state to make a value queryable, first check whether
it is already queryable through an existing index or read path — name the read path you checked and why it is
insufficient, in the design doc, before proposing new storage."* This is a design-review checklist item, not a
coding rule — it belongs in the design-task brief template, not `docs/coding-conventions.md`.

**Category:** task-brief-template item (design tasks) + process step (a design doc must justify new storage against
existing read paths before proposing it).

---

### Finding 2 — A converter built one `loadByCriteria()` call per injected model; the design's own stated "one read" guarantee wasn't tested until a later review pass caught the fan-out

**What happened.** `implement-dcb-batched-loading`'s fix commit (`c6e670fc4`, "batch every handler's injected
decision-model loads into one `loadByCriteria()` call (M1)") describes the bug directly: *"`DecisionModelConverter`
used to be a plain per-parameter converter: each injected `#[DecisionModel]` paid its own `loadByCriteria()` round
trip, so a handler with N models cost N read-side statement sets instead of the design's promised one."* Fixing
it required a real cross-cutting refactor: `DecisionModelModule` now discovers every handler with at least one
injected model and wires a shared `DecisionModelBatchLoader`; a new `DecisionModelBatchLoaderRegistry` and
`DecisionModelLoadedInstancesCollector` were added so sibling converters can read from one shared load.
`6b30eb5cd` then adds the regression test that should have existed from the start: a DBAL *query-count* assertion
for the batched load.

**How many times.** Once, but the `(M1)` suffix in the commit message is a numbered-review reference — the same
numbering scheme that produced `(N13)` on `implement-dcb-multitenant-backfill` (Finding 3). Both were caught by a
dedicated review pass over already-merged code, not by the original feature's own test suite.

**Cost.** 5 commits, a new class (`DecisionModelBatchLoader`) plus two supporting registry/collector classes, and
changes across `DecisionModelModule`, `DecisionModelConverterBuilder` and `DecisionModelConverter` — a
cross-cutting refactor to retrofit a guarantee the design already claimed to have.

**The instruction that would have prevented it.** *"When a handler can inject more than one of the same kind of
parameter (here, `#[DecisionModel]`), and the design states a shared-cost guarantee for N of them (here: 'one read
regardless of N'), write the N>1 regression test — asserting the actual read/query count, not just the returned
values — as part of the same commit that first makes N>1 work. Don't ship the N=1 case and defer the N>1 cost
guarantee to a later review."* This is a coding rule (a query-count assertion is part of the feature, not an
afterthought) and a task-brief-template item (state the shared-cost guarantee explicitly as an acceptance
criterion when the brief asks for multi-parameter injection).

**Category:** coding rule (`docs/coding-conventions.md`, testing section) + task-brief-template item.

---

### Finding 3 — Multi-tenant routing for two new console commands wasn't verified until a numbered review item asked for it — no bug was found, but 313 lines of test had to be written after the fact to prove it

**What happened.** `implement-dcb-multitenant-backfill` has exactly two commits. The test commit (`1d5477356`,
"prove backfill-tags and verify-schema route by tenant header (N13)") states plainly: *"Both console commands
already resolve their connection through the same per-message `MultiTenantConnectionFactory` routing
`DbalEventStore` uses... no source change needed."* The commit itself is 313 lines of new test
(`MultiTenantTagBackfillConsoleCommandTest.php`) plus a docs commit (`0c4f24e2b`). So the console commands were
correct from when they first shipped, but nothing proved it until a review item asked the question, well after the
commands existed and could have been used against a real multi-tenant install unverified.

**How many times.** Once in my scope, but it is the second instance of the `(M1)`/`(N13)` pattern from Finding 2 —
a capability that should have been part of the original feature's Definition of Done, instead surfacing as a
numbered item in a separate review pass.

**Cost.** 313 lines of test + 1 docs commit, entirely verification debt (no bug fixed) — cheap compared to Finding
2, but it is the second time in this scope that "does the multi-tenant/shared-cost guarantee actually hold"
was answered by a later review rather than the original feature's own tests.

**The instruction that would have prevented it.** *"Every new console command or read path added to a package that
ships multi-tenant support needs a tenant-routing test in the same PR that adds the command — add 'proves
tenant-header routing (or explicitly states why it's exempt)' to the console-command checklist."*

**Category:** task-brief-template item / test-coverage checklist, specifically for console commands.

---

### Finding 4 — A brand-new Laravel test fixture directory needs its `storage/logs` and `bootstrap/cache` scaffolding copied from a sibling fixture, or the generated `laravel.log` gets committed

**What happened.** The new `DcbSmoke` Laravel fixture needed two follow-up commits to get its directory tracking
right. First, `f575d7c60` ("keep the `DcbSmoke` fixture's `bootstrap/cache` directory like the other Laravel
fixtures") — a single empty `.gitkeep`. Then, separately, `ff8dab584` ("stop tracking `laravel.log` in the
`DcbSmoke` and `DbalConnectionRequirement` fixtures, keep the directory like the other fixtures") removed a
committed 177-line `laravel.log` plus two more generated log files across three fixture directories, adding a
`storage/logs/.gitignore` to each. Between the two commits, running the test suite against the freshly scaffolded
fixture had silently committed its own log output into git.

**How many times.** Two follow-up commits for the same root cause (missing `.gitignore`/`.gitkeep` scaffolding),
across three fixture directories, within this scope alone.

**Cost.** 2 commits, 177+ lines of generated log content committed and later stripped. Cheap to fix, cheap to
prevent, and purely mechanical — exactly the "cheapest wins, easiest to forget" category the brief calls out.

**The instruction that would have prevented it.** *"When scaffolding a new Laravel test fixture directory, copy
`storage/logs/.gitignore` and `bootstrap/cache/.gitkeep` from an existing fixture (e.g. any sibling under
`packages/Laravel/tests/`) before running any test against it — don't let the first test run be what populates
those directories."*

**Category:** tooling/repo fix. Concrete options: a `bin/new-laravel-fixture.sh` that scaffolds the directories, or
a one-line note in `AGENTS.md`'s dev-loop section next to the Laravel setup instructions.

---

### Finding 5 — An optional runtime dependency used behind an expression string still isn't declared in `composer.json`'s `suggest` block

**What happened.** `research-dcb-dynamic-tag-values` (`6ca72c2d1`) documents, as part of its inventory of what
already works: *"`symfony/expression-language` is `require-dev` in `packages/Ecotone/src/../composer.json` and is
not even in `suggest`, so a production install that writes a string expression gets a bare
`\InvalidArgumentException` per message."* Checked against the current tree
(`packages/Ecotone/composer.json:69,71-73`): `symfony/expression-language` is still listed only under
`require-dev`, and `suggest` still lists only `symfony/console`. The gap the research doc found is still present.

**How many times.** Once (found, not yet fixed).

**Cost.** Currently low (one missing `suggest` line, and a generic-exception message with no package name), but it
is a real production footgun for any application that reaches a `#[Fetch]`/`#[EventTag]` string expression without
having pulled in the package itself.

**The instruction that would have prevented it.** *"The moment a feature's runtime path can execute an optional
package (here: string expressions), add that package to the shipping package's `composer.json` `suggest` block in
the same commit, and make the failure-path exception name the missing package."* This one is still open — it
belongs in `docs/coding-conventions.md`'s optional-dependency guidance if one exists, or as a follow-up ticket; it
was not fixed by any commit in my scope.

**Category:** tooling/repo fix — a small, still-actionable code change, not a process document edit.

---

## 2. Cost summary

| Finding | Commits | Rework size | Bug found? |
|---|---|---|---|
| 1 — full-tag index avoided by maintainer redirect | 1 (revision-2 doc) | design-only; near-miss on an "order of magnitude" larger build | No — caught pre-code |
| 2 — decision-model batch-loading retrofit | 5 | new class + 2 registries, cross-module refactor | Yes — real N+1 |
| 3 — multi-tenant console-command routing test | 2 | 313-line test, no source change | No — verification debt only |
| 4 — Laravel fixture `.gitignore`/`.gitkeep` scaffolding | 2 | 177+ generated lines committed then stripped | No — mechanical |
| 5 — missing `composer.json` `suggest` entry | 0 (still open) | 1-line fix, not yet made | No — latent |

---

## 3. What went right

- **A structured, severity-ranked design-level review caught ten issues in one pass instead of piecemeal.**
  `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` — produced on `design-dcb-fetched-aggregates` —
  reviewed the shipped design against a fixed reading order (attributes → `EventStore` → modules → flows →
  collaborators → tests), ranked each proposal S/M/L by size, and named exactly which files each touches. Every
  item it raised (naming consistency, CQS violations, the `#[DecisionBoundary]` home, the on/off decision made in
  four places) was closed by a follow-up commit in the same branch (`c3b1cfa8e`, `4bb23eb51`, `a8f23212c`,
  `74f7bba62`, `a6bd5f26e`, `40eacaa22`, `1a9dd2681`). Worth keeping as the template for any large stack before its
  final merge: read in the order a newcomer would, rank by size, cite the exact files.

- **Performance research on the shared docker compose host explicitly controls for its own noise before drawing
  conclusions.** `research-dcb-fold-cost` (§1.2, "the noise floor, and how it is controlled") runs each
  configuration in its own process, shuffles the order of (shape, ablation) pairs within every pass so a slow
  period of the host cannot land on one configuration, and wires null controls (`Z-null`, flag that changes
  nothing) so a result is only trusted if it moves further than the null. It reports the resulting noise floor
  per measured shape (±2% to ±43%) and declines to conclude anything from the two shapes whose floor is too coarse
  (H1, H2). `research-dcb-read-comparison` reuses the same discipline and explicitly separates "noise" from "real
  effect" throughout. This is exactly the discipline the "never run package test suites in parallel — shared
  docker services cause random cross-package failures" lesson already recorded elsewhere in this project's memory
  would predict is necessary — worth promoting to a reusable checklist for any future performance-claim task on
  this host: own-process runs, shuffled order, an explicit null control, and a stated noise floor before any
  number is trusted.

- **Discarded design analysis is kept in the document, not deleted, and self-corrections are marked explicitly.**
  `design-dcb-aggregate-full-tag` keeps revision 1's full cost analysis in Part 5 "because its cost arithmetic is
  now the justification for not building it." `research-dcb-single-read-snapshot` marks its own reasoning
  corrections inline with a literal **"Corrected:"** prefix (e.g. §10.4, §13.4) rather than silently rewriting the
  earlier text. Both make the next similar decision cheaper to make correctly.

- **Clean TDD shape, no rework.** `implement-decision-boundary-parameters` is a 3-commit branch: RED test
  (`5f26a39f4`) → GREEN feature (`fd9538b57`) → docs (`6796d17ff`), with no follow-up fix or refactor commit. When
  the brief's scope is narrow and self-contained, this is what a friction-free branch looks like — worth noting as
  the positive control against Findings 2–4.

---

## 4. Rejected candidates

- **`research-dcb-single-read-snapshot`'s own revision-2 pivot** (OQ5: "revision 1's reason was wrong"). Judged
  inherent to genuine open research questions rather than a fixable process gap: the correction happened within the
  same document, before any code was written, and the document marks the correction explicitly (see §3). No
  process change proposed beyond what Finding 1 already covers for the *design* (not research) case.

- **The `(M1)`/`(N13)`/`(N9)`/`(N6)`/`(N10)`/`(N11)`/`(N12)`/`(M5)`/`(B1)`/`(V1)`/`(V2)` numbered-review references
  across DCB commit messages** point to a review pass whose source document I could not find committed anywhere
  across the 26 DCB worktrees (searched `docs/superpowers/` in every worktree under
  `/home/dgafka/orca/workspaces/ecotone-dev/` for the exact item IDs). It likely lived in conversation or ephemeral
  review notes rather than a committed spec, unlike the readability review (§3) which *was* committed with its
  full ranked list. This is plausibly a process gap — numbered review findings should probably be committed
  alongside the fixes that close them — but I'm not proposing a `coding-conventions.md`/`AGENTS.md` edit for it
  myself, since none of the commits that would evidence *why* the review wasn't committed are in my assigned
  branches. Flagging for the consolidating worker to weigh against groups a/b's evidence, if any.

- **Decision-model snapshot shape-invalidation** (`f8035b25f`, "invalidate an aggregate snapshot when the aggregate
  folds other events") looked at first like a reversal, but the commit sequence on `implement-decision-model-snapshots`
  shows it built directly on the snapshot feature two commits earlier in the same branch, not as a fix to a bug
  shipped and later discovered — it reads as proactive completion of the feature's own correctness envelope, not
  friction. Not counted as a finding.

---

## 5. Findings already captured elsewhere — cross-reference only, no new edit proposed

Several strong findings visible from this scope's own commits are **already fully documented** in
`docs/coding-conventions.md` on this base branch, citing the same commits I found independently. Listed here so
the consolidating worker doesn't duplicate them:

- **Constructor-injected services must be stateless — no static caches, no `$somethingByMessageId` singleton
  collectors.** Evidenced in my scope by `ca8a1b0fc` (dropped 105 lines of per-connection snapshot tracking in
  `DbalTagVersionRegister` after a RED test, `424220979`, proved it leaked state across transactions), `6b42c7a9b`
  (replaced two message-id-keyed singleton collectors with an immutable per-invocation header,
  `DecisionModelLoadedState`), and `a98c23fa8` (removed `DecisionModelReflection`'s static caches). All three are
  already cited in `docs/coding-conventions.md` rule 3 ("Constructor-injected services are stateless") and rule 8
  ("Always use optimistic locking"), including the maintainer's own quoted reasoning. This was, independently, the
  single most-repeated corrective pattern in my scope (3 occurrences in 2 branches) — its documentation is already
  in good shape and needs no further edit from this report.

- **One word per concept, used everywhere, renamed in one commit.** `a8f23212c` ("version" vs "sequence"),
  `c3b1cfa8e`, `40eacaa22` — already cited in `docs/coding-conventions.md` §6a, with the cost spelled out via a
  pointer to `2026-09-28-dcb-readability-review.md` §2.

- **CQS: a read must never run DDL, a mutator must never return a value.** `4bb23eb51` — already cited in
  `docs/coding-conventions.md` rule 4.

---

## 6. Evidence index (commit → finding)

| Commit | Branch | Finding |
|---|---|---|
| `c40c483c7` | design-dcb-aggregate-full-tag | 1 |
| `f84eb145b` | design-dcb-aggregate-full-tag | 1 |
| `13858fe70` | research-dcb-single-read-snapshot | 1 (corroborating) |
| `c6e670fc4` | implement-dcb-batched-loading | 2 |
| `6b30eb5cd` | implement-dcb-batched-loading | 2 |
| `bae82dca6` | implement-dcb-batched-loading | 2 |
| `75e943982` | implement-dcb-batched-loading | 2 |
| `b2d4d7ecb` | implement-dcb-batched-loading | 2 |
| `1d5477356` | implement-dcb-multitenant-backfill | 3 |
| `0c4f24e2b` | implement-dcb-multitenant-backfill | 3 |
| `f575d7c60` | implement-dcb-extension-object | 4 |
| `ff8dab584` | design-dcb-fetched-aggregates | 4 |
| `6ca72c2d1` | research-dcb-dynamic-tag-values | 5 |
| `2026-09-28-dcb-readability-review.md` | design-dcb-fetched-aggregates | §3 |
| `2026-09-29-dcb-fold-cost.md` §1.2 | research-dcb-fold-cost | §3 |
| `2026-09-29-dcb-read-comparison.md` | research-dcb-read-comparison | §3 |
| `5f26a39f4`, `fd9538b57`, `6796d17ff` | implement-decision-boundary-parameters | §3 |
| `f8035b25f` | implement-decision-model-snapshots | §4 (rejected) |
| `ca8a1b0fc`, `6b42c7a9b`, `a98c23fa8` | implement-dcb-extension-object, implement-dcb-aggregate-backed-models | §5 (cross-ref) |
| `a8f23212c`, `c3b1cfa8e`, `40eacaa22`, `4bb23eb51` | design-dcb-fetched-aggregates | §5 (cross-ref) |
