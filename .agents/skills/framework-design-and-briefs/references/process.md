## 19. Keep the two audiences' documents current

A behaviour change that a 1.x application would notice belongs in `upgrade-2.0.md`, with **Before / Now / How to
adapt**. A design decision and its rejected alternatives belong in a spec under `docs/superpowers/specs/`. Both
are where rule 6 sends the prose you did not put in a comment.

**A rename updates the spec in the same commit.** The spec is part of the unit, not a follow-up, and when it is
treated as one somebody pays for an archaeology pass instead. `eec9625d5` is a dedicated readability review that
found the designated first-read design document still describing `ecotone_event_tags` and `tag_version` — a table
and a column the shipped code never had (`ecotone_tagged_events`, `tag_sequence`) — alongside a documented
guarantee, that a decision model scoped only by a filter-only tag name is a bootstrap `ConfigurationException`,
implemented nowhere. That one review produced seven commits and left four ranked items open.

`docs/superpowers/` is in `.gitignore` but the specs are tracked, so adding one needs `git add -f`.

---

## Design, briefs and review

Everything above is what to run. This is what a design task, a task brief and a review pass owe each other. All of
it comes from three independent friction reports over the DCB effort
(`docs/superpowers/research/dcb-friction-{a,b,c}/report.md`, parked on branch `dgafka/dcb-docs`), where each gap below was measured in commits somebody
had to write twice.

### A design justifies new storage against the read paths that already exist

Before proposing an index, a backfill job, or write-path state to make a value queryable, name the existing index or
read path you checked and why it is not enough — in the design document, before the proposal. `c40c483c7` proposed
indexing every event of every event-sourced aggregate as tag rows in `ecotone_tagged_events`, plus a daily backfill
to populate it. The next commit on the same branch, `f84eb145b`, is headed "Revision 2 — maintainer redirect" and
quotes the correction:

> there should be simpler solution, we already have the events in event stream. Therefore DecisionModel backed by
> aggregate type attribute plus ability to point what matches aggregate id would be sufficient to build the model

The replacement queries the stream's own `(aggregate_type, aggregate_id, no)` index — no new index, no backfill, no
daily population job — and the document's own comparison calls it smaller than revision 1 "by an order of
magnitude". It cost only document text because a human read the proposal before implementation started. Nothing in
the process caught it.

Keep the discarded analysis in the document rather than deleting it: `design-dcb-aggregate-full-tag` keeps
revision 1's cost arithmetic "because its cost arithmetic is now the justification for not building it", and
`research-dcb-single-read-snapshot` marks its own corrections inline with a literal **"Corrected:"** prefix instead
of silently rewriting the earlier text. Both make the next decision of the same shape cheaper to make correctly.

### A design document's claims are the unit's acceptance criteria

All three friction reports found this independently; it is the largest process gap they agree on. When a design
document claims something specific — "one read regardless of how many models a handler injects", "a MariaDB 1020
maps to `ConcurrencyException`", "counters first means a lost condition writes nothing", "the console commands route
by tenant header" — the commit that implements it adds a test named after the claim. A claim with no such test is
not shipped; it is waiting for a review pass to re-derive it from the document:

- `c6e670fc4` — the design promised one batched read. `DecisionModelConverter` was a plain per-parameter converter,
  so a handler with N injected `#[DecisionModel]`s paid N round trips. Retrofitting the promise took five commits, a
  new `DecisionModelBatchLoader` and two supporting registry/collector classes, across `DecisionModelModule`,
  `DecisionModelConverterBuilder` and `DecisionModelConverter`.
- `ef6df5468`, `0161ed16b`, `4e510a054`, `70a7dd4bf`, `94715ee33` — five review-fix commits that add nothing but
  tests. The code was already correct in every case; nobody could tell without reading the design document and the
  tree side by side.
- `1d5477356` — 313 lines of test and no source change, proving two console commands route by tenant header. They
  always had. Nothing said so until a numbered review item asked.

Where the claim is about cost rather than behaviour — a statement count, a round-trip count — a test is the wrong
instrument and review holds it instead; see
[conventions rule 10](../../framework-testing/references/rules.md#10-tests-validate-at-the-userland-level-only), and `6b30eb5cd`
followed by `04e831b55` for the one that was written and then deleted. The behaviour still needs its test:
[rule 10a](../../framework-testing/references/rules.md#10a-a-guarantee-is-proved-on-every-path-that-has-to-hold-it).

### What a task brief has to settle before the work starts

Three of the seven units in one friction group needed a blocking round-trip to the coordinator because the brief had
left a boundary open (`docs/superpowers/specs/2026-09-29-expression-failure-context-report.md` § "What the direction
did not settle"; `docs/superpowers/specs/2026-09-29-missing-table-instructions-audit.md` §1 "Scope decision"). Every
one was answered correctly, and every one cost a stop. A brief states, up front:

- **Whether an exclusion covers tests as well as production code.** "`#[Deduplicated]` is excluded because it lives
  in `packages/Dbal`" was read both ways.
- **Whether "handle X in DCB handlers" reaches every code path X touches**, including the ones shared with non-DCB
  features, or only the DCB-exclusive ones. The `#[Fetch]` *load* half turned out not to be separable from the
  ordinary aggregate-load path at all, which the brief had not anticipated.
- **The engines the change must be verified on** when it touches transactions, DDL or the event store — MySQL and
  MariaDB by name, not "run the suite" (see [step 7](../../framework-pull-request/references/checklist.md#before-opening-a-pull-request)).
- **The conventions rules the unit will be checked against**, quoted rather than referenced, when it writes tests or
  constructors. Rules 2 and 10 were each swept by a worktree of its own after the fact rather than applied while the
  code was written — 6 and 22 commits, no new behaviour in either — and each of rule 10a's five missed paths was a
  later corrective commit.

### A review that numbers its findings commits the numbered list

DCB commit subjects carry finding IDs — `(B1)`, `(M1)`, `(M5)`, `(N9)`–`(N13)` — and two of the three friction
reports independently went looking for the document that assigned them, across all 26 DCB worktrees, and could not
find it committed anywhere. Only the resolutions survive, so no fix can be read against what was actually asked. The
readability review is the counter-example and the shape to copy:
`docs/superpowers/specs/2026-09-28-dcb-readability-review.md` is committed with its full ranked list, and every item
it raised can still be traced to the commit that closed it.

---

### A move of several co-imported types reverts newest-first

One commit per moved type keeps a large rename reviewable, but those commits do not revert independently. A file
that imports two of the moved types has both `use` lines next to each other, before the move and after php-cs-fixer
re-sorts them. Each commit edits one of the two adjacent lines, and git treats edits to adjacent lines as a
conflict, so reverting the earlier commit while the later one stands stops on that file. The order of the commits
does not change this: whichever comes first, it is the one that cannot revert alone.

What does hold is the suffix property: **the last commit reverts cleanly alone, and commit *k* reverts cleanly once
commits *k*+1 to *n* are reverted** — so the unit is undone newest-first. The `EcotoneLite` move measured it
(`ab0b21319`, `3f0ab3a4c`, `0a917c33c`, `d98c4602a`): reverting the `MessageChannel` commit alone was clean and left
phpstan at 0 errors, while reverting `EcotoneLite` alone conflicted in 89 files, `FlowTestSupport` in 14 and
`ConfiguredMessagingSystem` in 8, every hunk inside a `use` block or the namespace-map rows. Two types landing in
one namespace add a second, logical dependency: `ConfiguredMessagingSystem` and `MessageChannel` both moved into
`Ecotone\Api\Messaging`, php-cs-fixer dropped the now same-namespace import, and reverting the first alone would
leave it naming a `MessageChannel` that no longer resolves.

The maintainer kept the four commits rather than squash them, because a tree with only some of the types moved is
one nobody would want, and one commit of 400 files is harder to review than four named ones. So for a unit like
this: run the revert check (`git revert --no-commit <sha>` against the unit's head, then `git revert --abort`), say
in the report that the unit reverts as a suffix and why, and do not contort sorted files such as
`upgrade/namespace-map-2.0.csv` to get around it.

## Practices worth repeating

Recorded as deliberately as the mistakes, because each of these demonstrably stopped an error.

- **Survey first, then one commit per line item.** `implement-dcb-read-path-hygiene` wrote its missing-table audit
  table (`✓`/`→`/`~` per call site) before touching code; `implement-no-nullable-services` worked a pre-written list
  of sites. Both landed single-purpose commits with no walk-backs, and both mapped 1:1 onto the list. This is the
  default shape for any "sweep the codebase for X" unit.
- **Review in the order a newcomer reads, rank by size, name the files.**
  `docs/superpowers/specs/2026-09-28-dcb-readability-review.md` read attributes → `EventStore` → modules → flows →
  collaborators → tests, ranked each of its ten proposals S/M/L, and named exactly which files each one touches. Six
  were implemented in the same branch; the four touching public `Api/` or maintainer-decided names were deferred
  explicitly rather than rushed in. Both halves are the desired behaviour.
- **Control the host's noise before believing a number.** `docs/superpowers/specs/2026-09-29-dcb-fold-cost.md` §1.2
  runs each configuration in its own process, shuffles the order of (shape, ablation) pairs within every pass so a
  slow period of the shared compose host cannot land on one configuration, wires null controls that change nothing,
  states the resulting noise floor per shape (±2% to ±43%), and declines to conclude anything at all for the two
  shapes whose floor is too coarse. Any performance claim measured on this host follows it.
- **A measurement pass finds defects, not just numbers.** `4f6d6e22b` measured three *proposed* read optimisations
  against the shipped code and found two real defects on the way — a `tableExists` probe run on every call, and an
  empty extra page whenever an aggregate's event count is an exact multiple of the load batch size — both cheaper to
  fix than any of the three optimisations were to build.
- **Answer a genuinely ambiguous rule once, in writing, named as a decision.** `823dd41c3`, `c48e1b7c7`,
  `6ef6e48df`, `24edcf663` and `bd255a13a` each cite a dated maintainer call instead of folding a guess into the
  diff, so the same question was not answered differently by the next implementer.
- **Write the commit message so the defect can be reconstructed without the diff.** `050e760f1`, `9ae4e3fd3` and
  `36783f980` are the standard: what broke, why, and what changed. It is what made a six-branch, seventy-commit
  retrospective possible in a single pass.
- **Tabulate the error surface in the report.** The expression-failure-context unit listed every producer's exact
  exception message beside the named test asserting it, which made verification mechanical instead of a re-derivation.
  The shape for any unit that changes an error surface.

---

