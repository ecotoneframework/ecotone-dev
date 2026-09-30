# DCB friction consolidation — what landed where

Consolidates `docs/superpowers/research/dcb-friction-{a,b,c}/report.md` (956 lines, 20 ranked findings between
them) into `AGENTS.md`, `docs/coding-conventions.md` and `docs/dev-workflow.md`. Documentation only: no code was
changed, and the code problems the reports found are recorded in §5 for someone else to pick up.

Three workers mined the same effort independently, so corroboration is signal. Every row below says how many of the
three reports found the finding on their own. Where two or three did, that is stated in the guide itself, because a
rule is easier to accept when the reader knows it was not one person's hunch.

---

## 1. Rules strengthened versus rules added

**Strengthened: 7. Added: 1.** The ratio is the useful result — almost everything the three reports found was
already a written rule that nobody applied while writing the code. The gap was rarely knowledge.

| Rule | Strengthened or added | Reports | What changed |
|---|---|---|---|
| Conventions **1a** — refuse at bootstrap | strengthened | **2 of 3** (a, b) | Was "ten commits moved a failure earlier", with ten SHAs. Now carries the cost: `implement-dcb-edge-cases` is a whole worktree, 20 commits, 18 of them `fix(dcb):`, every one the same shape — a rule the spec already stated with no enforcing code. Adds the requirement that the guard and the message-content assertion ship in the commit that states the rule, and routes the six wrong-message commits to rule 1. Two more SHAs cited (`b984c8d41`, `24edcf663`) |
| Conventions **2** — no nullable service dependency | strengthened | 1 (a) | Adds the cost of the retroactive sweep: 6 commits, four classes, one dedicated worktree, deleting defaults every real call site already satisfied. The rule predated the code it corrected |
| Conventions **3** — stateless services | strengthened | **2 of 3** (a, c) | Adds: a mechanism that remembers anything between calls ships its leak test as the RED commit. `424220979` is the test that should have gated the tracking `ca8a1b0fc` deleted, and it landed two units downstream, so the cost fell on somebody else's schedule |
| Conventions **7** — Enterprise in separate files | strengthened, twice | 1 (b) | Reframed "extracting Enterprise logic is a normal refactor here" as the expensive path, with what it cost: the whole DCB tag protocol inline in two Apache-2.0 stores, unpicked into 5–6 classes per store family, found only by review. Second addition: **split the implementation, never the interface** — `TaggedEventStore`/`AggregateEventStore` were split by licence and folded back (`983db0c5a`…`bd255a13a`) after the split had reached every call site |
| Conventions **9** — in-memory implementation | strengthened | 1 (b), 3 occurrences | Rule 9 required the in-memory class and never required proving it equivalent. Adds the conformance-suite requirement and the three divergences, each found by a different unit doing something else (`d59bd5989`, `ade73e6c5`, `503d70bc7`) |
| Conventions **10** — userland-level tests | strengthened | **2 of 3** (a, c) | "Fifteen commits" → the real unit: 22 of `implement-blackbox-tests`'s 22 own commits, no new coverage in any, two of them walking back the rewrite's own over-correction. Also reconciles group c's query-count proposal against the rule: `6b30eb5cd` added the statement-counting test and `04e831b55` deleted it again — the cost claim is review-protected, the behaviour is rule 10a's |
| Conventions **10a** — a guarantee is proved on every path | **added** | **2 of 3** (a, b), 5 occurrences | The one genuinely new rule. Merges group a's findings 4 and 5 with group b's finding 5: five times, a fix covered one entry point and looked finished — aggregate save versus DCB handler (`c09bac1e0`), live append versus backfill (`0548c4130`), gateway wrapper versus class-routed `CommandBus::send()` (`57c076084` → `9ae4e3fd3`), interface method versus gateway registration (`ade73e6c5` → `99daa69ba`) |
| Conventions **19** — keep the documents current | strengthened | 1 (a) | Adds: a rename updates the spec in the same commit. `eec9625d5` found the designated first-read document still describing a table and a column the shipped code never had, plus a documented guarantee implemented nowhere; that review cost seven commits and left four items open |

Nothing was renumbered. `10a` is a sub-rule for that reason — `AGENTS.md`, the skills and the specs all cite these
rules by number.

### Rule 11 — reversed by maintainer decision, mid-task

The brief asked me to fix `.claude/skills/ecotone-testing/references/testing-patterns.md:104`, which stated
"use inline anonymous classes", by pointing it at conventions rule 11, which had corrected it toward named
fixtures. Mid-task the maintainer reversed that: **inline anonymous classes are the rule**, and the skill line was
right all along.

What I changed to make inline the rule:

- **Rule 11's worked example is now an anonymous aggregate.** It was a named `#[Aggregate] final class Basket`
  below the `TestCase` — the single most-copied artifact in the document, teaching the form being retired. It is
  now `new #[Aggregate] class () { … }` registered with `[$basket::class]`, modelled line for line on
  `packages/Ecotone/tests/Modelling/Unit/MessageBusTest.php:452`, a committed and passing test with the same
  private `#[Identifier]` property, the same static `#[CommandHandler]` factory and the same `#[QueryHandler]`. I
  did not invent the shape, because I could not run it: this worktree has no `vendor/` and no containers up, and
  booting the compose stack for a documentation change was not worth it when a passing test already demonstrates
  every line.
- **The fixture bullet no longer presents two co-equal forms.** Anonymous is the rule, aggregates included, with
  the two exceptions enumerated beneath it as limits rather than alternatives.
- **The 92-of-141 figure is reframed as drift.** It read "the dominant form in 2.0 tests"; it now reads "a large
  share of the existing tests do not follow this … drift to be reduced, not a convention to copy".

**The final exception list is two items, not three.** Each is a limit of PHP, not a preference:

1. **A class used as a type declaration** — every command, event and query, and anything a handler,
   `#[EventSourcingHandler]` or converter names in a parameter or return type. PHP has no syntax for naming an
   anonymous class in a type. Evidence:
   `packages/Ecotone/tests/Modelling/Unit/Causation/AggregateNotFoundCausationTest.php` is the shape — `OrderCreated`
   is named below the `TestCase` while both aggregates (lines 25 and 48) and the event handler between them are
   anonymous.
2. **A class named inside another fixture's attribute argument** — an attribute argument is a constant expression,
   so `$fixture::class` cannot appear in one. Evidence:
   `#[FromAggregateStream(EventSourcedBasket::class)]` at
   `packages/JmsConverter/tests/Integration/InterfaceTypedPayloadTest.php:68`, where the projection *carrying* the
   attribute is itself anonymous, and `#[DecisionModel(aggregate: WalletForAggregateBackedTest::class)]` at
   `packages/Ecotone/tests/Modelling/DecisionModel/AggregateBackedDecisionModelTest.php:370`.

**Three of the four items the correction offered are not exceptions, with evidence:**

| Claimed to force a named class | Verdict | Evidence |
|---|---|---|
| `#[AggregateType]` | **No.** Its only argument is a string name, not a class-string | `packages/Ecotone/Api/Attribute/AggregateType.php` — `__construct(string $name)`. Anonymous aggregates carry it |
| `EventCriteria` | **No.** It takes class-strings at runtime, where `$basket::class` works | `packages/Ecotone/Api/EventSourcing/EventCriteria.php:50,78` — `aggregate(string $aggregateClass, …)`, `ofTypes(string ...$eventTypes)`. The event types it names are already named under exception 1, never for its sake |
| An asserted exception message containing the class name | **No — avoidable.** The test holds the instance, so interpolate `$fixture::class` | Three files already do, in five places: `packages/Dbal/tests/Integration/MultiTenant/WithTenantResolverPlacementValidationTest.php:47,65,82`, `WithTenantResolverLicensingTest.php:38`, and `AggregateNotFoundCausationTest.php:60`, which builds an entire expected message from `$wallet::class` and `$walletCharging::class`. `WithoutOptimisticLockTest.php:81` uses the named form by choice; it could interpolate, or assert only the instruction half of the message |
| Aggregates and sagas generally | **No.** An anonymous class can be an aggregate | `MessageBusTest.php:452,506,562` and `AggregateNotFoundCausationTest.php:25,48` — six anonymous `#[Aggregate]` fixtures in the tree. The only thing that would block it is a private constructor, which a fixture does not need |

So the rule is stronger than the correction assumed: the exception list is two items long, and neither of them is
about aggregates.

**Where the two-forms framing was echoed and had to be corrected:**

| File | What it said |
|---|---|
| `docs/coding-conventions.md` rule 11 | the two co-equal forms, the named worked example, and the 92-of-141 figure as precedent |
| `AGENTS.md` § Testing Guidelines | "an anonymous class when it only holds handler methods, named classes below the `TestCase` … the dominant form in 2.0" |
| `AGENTS.md` § Code Conventions, rule 11 row | "fixtures in the test file" — now says inline anonymous, named only where PHP forces it |
| `docs/superpowers/specs/2026-09-30-agents-coding-conventions-report.md` | the report of the unit that made the original call. Its §1 row and its §2 row both argue for the named form off the 92-of-141 count. Left as the record of that decision with a **Superseded in part** note at the top pointing at rule 11, rather than rewritten — rule 19 wants the decision and its reversal both readable |
| `.claude/skills/ecotone-testing/references/testing-patterns.md:104` | already correct. Kept, and pointed at rule 11 so there is one statement of the rule rather than two |

---

## 2. Where each non-coding finding landed, and why there

All of it went to `docs/dev-workflow.md`, in two new sections, with `AGENTS.md` carrying a 22-line summary and the
links. `AGENTS.md` is the entry point and was already 320 lines; a process finding with its evidence runs 15–30
lines, and four of them inline would have pushed the file past the point where anybody finishes it.
`docs/dev-workflow.md` already held the mechanics of a unit of work, so its intro now says it holds the process too.

| Finding | Landed in | Reports | Why there |
|---|---|---|---|
| Design before checking what exists (a tag index and a daily backfill proposed before checking the event stream's own index; the replacement was an order of magnitude smaller) | dev-workflow § Design, briefs and review → *A design justifies new storage against the read paths that already exist* | 1 (c), plus a corroborating second instance inside c's own scope | It governs how a design task starts, before any code exists. Nothing in `coding-conventions.md` applies to a document that has not been implemented yet |
| Promises shipped unverified (the design promised one batched read; the code shipped one read per model) + guarantees with no test proving them (five review commits adding only tests) + a capability proved 313 lines after it shipped | **merged** into dev-workflow § *A design document's claims are the unit's acceptance criteria* | **3 of 3** (b4, c2, c3; a7's "documented guarantee implemented nowhere" is the same family) | They are one rule, as the brief suspected: a claim in a design document is an acceptance criterion, not prose. It sits with the design process because the trigger is the document, and it cross-references conventions 10 and 10a rather than restating either |
| Brief-scope ambiguity — 3 of 7 units needed a blocking round-trip | dev-workflow § *What a task brief has to settle before the work starts* | 1 (a) | It is about how work is dispatched. Four concrete items a brief must state, including which conventions rules the unit will be checked against — the answer to why rules 2, 10 and 10a were each swept after the fact |
| Numbered review findings whose numbered list was never committed (`(M1)`, `(N9)`, `(B1)`) | dev-workflow § *A review that numbers its findings commits the numbered list* | **2 of 3** (b, and c flagged it for this consolidation rather than proposing an edit alone) | Group c explicitly deferred it pending corroboration from another group. Group b corroborates it, so it is in |
| MySQL/MariaDB verification — two whole worktrees weeks apart | dev-workflow, PR checklist step 7, strengthened with both worktrees | 1 (b) | The checklist already said it. It now says which engines, why (rule 16's implicit commit on DDL) and what the omission has already cost |
| What went right — survey-first sweeps, the ranked newcomer-order review, noise-controlled performance research, measurement-as-defect-discovery, recorded maintainer decisions, reconstructable commit messages, error-surface tables | dev-workflow § **Practices worth repeating** (7 items), summarized in `AGENTS.md` | a, b and c each named some; the ranked review and the deferral discipline were named by **2 of 3** | The brief asked for these to be recorded as deliberately as the mistakes. Each is stated with what it prevented and the document or commit that demonstrates it |
| Laravel fixture scaffolding (`storage/logs/.gitignore`, `bootstrap/cache/.gitkeep`) | one line in dev-workflow § Running tests | **2 of 3** (a6, c4) | The repo fix is a code change and is left in §5 below. The documentation line costs one sentence and stops the next branch rediscovering it in the meantime |

---

## 3. Findings not carried across, and why

| Not carried | Why |
|---|---|
| Group c's finding 2 **as written** — "add a DBAL query-count assertion as part of the feature" | It contradicts a settled rule and the tree already settled it the other way. `6b30eb5cd` wrote exactly that test; `04e831b55` deleted it, subject: "one-load-per-handler is review-protected", and neither file exists now. Carried in the corrected form instead: the N>1 *behaviour* gets a test (rule 10a), the cost claim is held by review (rule 10), and both commits are cited so the next person does not rebuild the deleted test |
| The `symfony/expression-language` `suggest` gap, and the Laravel fixture `.gitignore`/`.gitkeep` repo fix | Code changes. The brief says record, do not action — §5 |
| Every "rejected candidate" in all three reports: limit-based pagination's extra page, SQLite's RETURNING floor, the stacked-worktree archaeology cost, the research documents' own revision-2 pivots, decision-model snapshot shape-invalidation, and the four readability proposals deferred for touching public `Api/` | All three workers judged these inherent to the problem rather than fixable by an instruction, and each gave reasoning I could not improve on. Deliberate deferral of an `Api/`-surface change is the behaviour the guides already want, not friction |
| Group a's finding 3 as a separate rule (a stateful locking mechanism built and reverted) | It is rule 3 and rule 8, both of which already cite `ca8a1b0fc`. Folded into rule 3 as the test-first requirement rather than restated |
| Group c §5's three cross-references (statelessness, one-word-per-concept, CQS) | Group c checked the conventions doc first and found them already documented against the same commits. Nothing to add; rule 3 gained only the corroboration count |
| `#[ServiceActivator]` code examples across eight skill files | Out of scope, and not a conventions correction — it is a removed attribute (`upgrade-2.0.md` §7a), so it needs the API rewrite recorded in §5, not a pointer to a rule |

---

## 4. Final line counts

| Guide | Before | After |
|---|---|---|
| `AGENTS.md` | 320 | 356 |
| `docs/coding-conventions.md` | 718 | 839 |
| `docs/dev-workflow.md` | 186 | 315 |

`CLAUDE.md` is untouched and still a symlink to `AGENTS.md`.

`AGENTS.md` grew by 36 lines net (46 added, 10 replaced): 24 of them the new process section and its links, the rest
spread across the rule table and the testing list. Every process finding's evidence sits in `docs/dev-workflow.md`,
which took 133 of the 345 lines added across the three guides.

---

## 5. Code follow-ups recorded, not actioned

1. **`symfony/expression-language` is not in `suggest`.** `packages/Ecotone/composer.json` lists it under
   `require-dev` only, and `suggest` lists just `symfony/console`. A production install that writes a `#[Fetch]` or
   `#[EventTag]` string expression gets a bare `\InvalidArgumentException` per message with no package named. Two
   parts: the `suggest` line, and an exception at the failure path that names the missing package. Found by group c
   (`6ca72c2d1`), still present.
2. **Laravel test fixture scaffolding.** `DcbSmoke` and `DbalConnectionRequirement` needed `f575d7c60` and
   `ff8dab584` to get `storage/logs/.gitignore` and `bootstrap/cache/.gitkeep` right, after a test run had already
   committed a 177-line `laravel.log`. Found independently by groups a and c. The durable fix is a scaffolding
   script (`bin/new-laravel-fixture.sh`) or a template directory, so a new fixture cannot start without them.
3. **Eight skill files use `#[ServiceActivator]`, which no longer exists.** `upgrade-2.0.md` §7a records its
   removal — `#[InternalHandler]` is the only name, and the second positional argument changed meaning, so the
   examples are not a rename away from correct. Affected: `ecotone-handler` (SKILL.md and both references),
   `ecotone-business-interface` (SKILL.md and usage examples), `ecotone-resiliency` (SKILL.md and usage examples),
   `ecotone-workflow` and `ecotone-metadata` (references), plus
   `ecotone-module-creator/references/module-anatomy.md`'s `registerServiceActivator`. Not a conventions
   correction and outside this brief's scope, so untouched.
4. **Four readability-review proposals remain open** (`docs/superpowers/specs/2026-09-28-dcb-readability-review.md`
   proposals 3–6): type the design's nouns instead of arrays, collapse `AppendStrategy` into the tag collaborator,
   stop passing the store into its own collaborators, and name the DCB-handler concept. Deferred deliberately for
   touching public `Api/` or maintainer-decided names — listed here so they stay findable, not as friction.
