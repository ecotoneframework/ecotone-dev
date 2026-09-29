# Event-sourced aggregates as decision models — design

*File kept at its original name (`…-dcb-aggregate-full-tag-design.md`) so the launch record's trail holds.*

**Revision 2 — maintainer redirect, 2026-09-28.** Revision 1 proposed the "full tag": index rows in
`ecotone_tagged_events` for every event of an event-sourced aggregate, so the aggregate became loadable through the
tag index. The maintainer's answer:

> there should be simpler solution, we already have the events in event stream. Therefore DecisionModel backed by
> aggregate type attribute plus ability to point what matches aggregate id would be sufficient to build the model,
> and then existing aggregate tags would do the trick. we dont need to backfill or populate aggregate type
> identifier on daily basics

That is right, and it is smaller than revision 1 by an order of magnitude. Revision 1's analysis is kept in Part 5,
because its cost arithmetic is now the *justification* for not building it.

---

## Part 0 — The answer in one paragraph

The consistency boundary for an event-sourced aggregate **already shipped**: `aggregate_<AggregateType>` /
`<aggregateId>` is a counter in `ecotone_tag_versions`, bumped by every save, and `EventCriteria::aggregate()`
already captures it into a handler's `AppendCondition`. And the aggregate's events are **already queryable** by the
pair the boundary is keyed on: `EventStore::loadAggregateEvents($stream, $type, $id, $fromVersion, $count,
$eventNames)` reads them from the stream's own `(aggregate_type, aggregate_id, no)` index, in `aggregate_version`
order, with the event-name filter pushed into SQL. So nothing needs to be indexed, backfilled, or written on save:
what is missing is only a **declaration** that lets a decision model say *"my events are `Wallet`'s events"* and
*"here is which property of the message is the wallet id"*. That is one new argument on `#[DecisionModel]`, one new
loader class beside the existing one, and one extra fold pass in `DecisionModelBatchLoader`. The counter capture the
batch loader already performs for `#[Fetch]`-ed aggregates is reused verbatim as the guard, so the boundary is not
extended at all — only read from. **No schema change, no index rows, no backfill, no per-save cost, no
configuration, no `EventCriteria` change, no `TagResolver` change, no reader change.**

```php
#[DecisionModel(aggregate: Wallet::class)]        // criterion: Wallet's events for <walletId>, of the handled types
final class WalletBalance
{
    private int $balance = 0;

    #[EventSourcingHandler] public function credited(WalletCredited $e): void { $this->balance += $e->amount; }
    #[EventSourcingHandler] public function debited(WalletDebited $e): void   { $this->balance -= $e->amount; }

    public function canCover(int $amount): bool { return $this->balance >= $amount; }
}

final class Payouts
{
    #[CommandHandler]
    public function payOut(RequestPayout $command, WalletBalance $wallet, PayoutsToday $today): array
    {
        if (! $wallet->canCover($command->amount))                  { throw new InsufficientFunds(); }
        if ($today->total() + $command->amount > self::DAILY_LIMIT) { throw new DailyLimitExceeded(); }

        return [new PayoutRequested($command->walletId, $command->amount)];
    }
}
```

`WalletCredited` and `WalletDebited` carry **no `#[EventTag]`** and never will. `RequestPayout` needs nothing but a
property named like `Wallet`'s identifier. `PayoutsToday` is an ordinary tag-scoped model. Both models are captured
in one statement, both are in the handler's one `AppendCondition`, and a save of `Wallet w-1` by anyone else while
the handler thinks fails the append.

---

## Part 1 — Why nothing needs to be stored

### 1.1 The boundary is already there

Shipped, per §4.11 of the main spec and Part 6 of `2026-09-28-dcb-fetched-aggregates-design.md`:

| Piece | Where | State |
|---|---|---|
| a counter per aggregate instance | `ecotone_tag_versions`, `aggregate_<AggregateType>` / `<AggregateIdString>` | shipped |
| bumped by every event-sourced save | `TagResolver::counterTagOfSavedAggregate()` → `AppendedTags` → the guarded `UPDATE` | shipped |
| bumped by every state-stored save | `StateStoredRepositoryAdapter` → `AggregateCounter` → `GuardedTagBump` | shipped |
| captured before invocation, into the handler's `AppendCondition` | `FetchedAggregateCounterCapture` → `EventCriteria::aggregate()` → the batch's one capture `SELECT` | shipped |
| conflicts rendered as the aggregate | `DecisionModelConcurrencyException` | shipped |

**So the guard for "I read `Wallet w-1`, then wrote something" is complete and in production on this branch.** The
only thing a decision model cannot do today is *read the events that guard protects* — because
`TagResolver::eventsMatching()` resolves an event's tags from `#[EventTag]`, and an aggregate's events declare none.

### 1.2 The events are already queryable, by exactly the right key

`EventStore::loadAggregateEvents()` (core interface, `DbalEventStore` and `InMemoryEventStore` implementing it) takes
`($streamName, $aggregateType, $aggregateId, $fromVersion = 1, $count = null, $eventNames = [], $deserialize = true)`
and, on the Dbal side, renders:

```sql
SELECT no, event_name, payload, metadata FROM <stream>
WHERE <aggregate_type expr> = ? AND <aggregate_id expr> = ? AND <aggregate_version expr> >= ?
  AND event_name IN (?, ?)            -- only when $eventNames is given
  AND no >= ?
ORDER BY no ASC LIMIT <batch>
```

served by the stream's own index on `(aggregate_type, aggregate_id, no)`, batched, and ordered by `no` — which for
one aggregate in one stream **is** `aggregate_version` order. Three properties matter and none of them is available
through the tag index:

- **`$eventNames` is pushed into SQL.** A model that handles 2 of an aggregate's 12 event types reads 2, not 12.
  `DbalTaggedEventReader` cannot do this — it loads every flagged event and filters by name in PHP afterwards
  (revision 1 §4.4 flagged this as a defect; on this path it simply does not arise).
- **`$fromVersion` is pushed into SQL**, so a decision-model snapshot is a later configuration change, not a new
  mechanism (§4.4).
- **No `IN (:nos)` list.** One range scan, whatever the history's length.

### 1.3 The declaration is the only thing missing

Two facts, both of which the framework already holds:

| Fact | Already available from |
|---|---|
| which aggregate type, and therefore which counter tag and which `aggregate_type` value | `#[AggregateType]`, **mandatory on every aggregate once DCB is registered** (D2) — `AggregateTypeMapping`, `AggregateCounterTags` |
| which stream holds it | `#[Stream]` on the aggregate class, else the default stream — `AggregateStreamMapping`; core resolves the same thing by reflection in `DecisionModelStreamResolver` |
| which message property is the aggregate id | the aggregate's `#[Identifier]` / `#[IdentifierMethod]` names, and `#[Fetch]` as the explicit escape — `AggregateDefinitionRegistry`, `FetchAggregateConverter::identifiersFrom()` |
| the identifier *string* the stream stores | `AggregateIdString::from()` (6.2 item 4 of the fetched-aggregates design made this the one identifier string) |

`#[DecisionModel(aggregate: Wallet::class)]` names the class; everything above is derived from it. This is what the
maintainer's "backed by aggregate type attribute" resolves to in the shipped code: the attribute is where the type
name comes from, and naming the class rather than the type string is what makes the stream, the identifier names and
the counter tag derivable and checkable at bootstrap instead of being three more strings to keep in sync.

---

## Part 2 — The design

### 2.1 The rule

> **A `#[DecisionModel(aggregate: X::class)]` is scoped by one instance of the event-sourced aggregate `X`: its
> events, of the types the model handles, read from `X`'s own stream by `X`'s own columns, in
> `aggregate_version` order. Its boundary is `X`'s counter tag, captured before the read and guarded on the
> append — the counter that already exists and is already bumped by every save of `X`.**

A model declares `aggregate:` **or** `tags:`, never both: ANDing an aggregate's identity with a user tag would
require every folded event to carry both, which no event does, and ORing them would ask two questions in one class,
which the design has refused since revision 2 of §4.4 ("Two questions are two models").

### 2.2 How the identifier is resolved

By the rule `#[Fetch]`-ed aggregates already follow, with one convenience added — in order:

1. **`#[Fetch]` on the parameter**, for the explicit case and for composite identifiers (an expression returning a
   map, exactly as for aggregates):
   ```php
   #[CommandHandler]
   public function transfer(
       TransferMoney $command,
       #[Fetch('payload.fromWalletId')] WalletBalance $from,
       #[Fetch('payload.toWalletId')]   WalletBalance $to,
   ): array
   ```
   The same model class twice with different values — the case the convention cannot resolve, and the same shape
   §4.4 already documents for tag-scoped models.
2. **By convention**, when no `#[Fetch]` is given: a message property named like the aggregate's identifier
   (`walletId` for `#[Identifier] private string $walletId`). This is what `#[Fetch]` on an aggregate parameter
   deliberately does *not* offer today, and it is worth offering here because it removes the
   `symfony/expression-language` requirement that 6.2 item 9 of the fetched-aggregates design ran into.
3. **Unresolvable** → a bootstrap `ConfigurationException` when the handler's message is a concrete class with no
   matching property, mirroring `DecisionModelTagResolvabilityGuard`; otherwise a runtime exception naming the
   model, the aggregate and the message. A nullable parameter (`?WalletBalance $wallet`) receives `null`, contributes
   nothing to the boundary, and the handler still appends — the rule already applied to models and fetched
   aggregates.

The resolved identifiers go through `AggregateIdString::from()` twice over, for the two things that must agree: the
`aggregate_id` value handed to `loadAggregateEvents()`, and the counter tag value handed to
`EventCriteria::aggregate()`. One function, so they cannot disagree.

### 2.3 How it runs, statement by statement

For a handler injecting `WalletBalance` (aggregate-backed) and `PayoutsToday` (tag-scoped):

| # | Step | Statement |
|---|---|---|
| 1 | **capture everything first** — `DecisionModelBatchLoader` ORs the tag-scoped models' criteria, the fetched aggregates' capture leaves, the `#[DecisionBoundary]` criteria **and now the aggregate-backed models' `EventCriteria::aggregate()` leaves** into the one `loadByCriteria()` it already issues | one `SELECT` on `ecotone_tag_versions` for `{aggregate_Wallet:w-1, wallet:w-1}` |
| 2 | read the tag index for the tag branches (the aggregate leaf matches nothing, by design — unchanged) | one `SELECT` on `ecotone_tagged_events`, then one per stream |
| 3 | **read the aggregate-backed models' events** | one `loadAggregateEvents()` per distinct `(aggregate type, id)`, narrowed to the union of the handled event names of the models on that instance |
| 4 | fold | tag-scoped models via `TagResolver::eventsMatching()` as today; aggregate-backed models from their own result, in query order, through the same `EventSourcingHandlerExecutor::fill($events, null)` |
| 5 | invoke, append under the one `AppendCondition`, publish | unchanged |

**Capture-before-read holds** (§4.5's load-bearing rule): step 1 precedes step 3, so a save committing in between
makes the events newer than the captured version and the guarded `UPDATE` fails — a spurious retry, in the safe
direction. Getting this backwards would pair a newer version with older events and miss the conflict.

**On "one load per handler".** The guarantee becomes *one tag load plus one stream read per distinct aggregate
instance in the boundary*. That is the same query count the user pays today for `#[Fetch]`-ing those aggregates, so
nothing regresses; and two models on the same instance share one query. Revision 1 would have folded step 3 into
step 2 at the cost of an index row per event forever — see Part 5.

### 2.4 The walkthrough, with rows

`Wallet w-1` (`#[AggregateType('Wallet')]`, stream `_wallet`, a 1.x table) has 6 events, `aggregate_version` 1–6,
and its counter is at 6. `PayoutsToday` for `wallet:w-1` is at 7. Anna requests a 60 payout; the wallet holds 100.

**a. capture** — one statement, both pairs:

```sql
SELECT tag_name, tag_value, version FROM ecotone_tag_versions
WHERE (tag_name = 'aggregate_Wallet' AND tag_value = 'w-1')
   OR (tag_name = 'wallet'           AND tag_value = 'w-1');
```

| tag_name | tag_value | version |
|---|---|---|
| aggregate_Wallet | w-1 | 6 |
| wallet | w-1 | 7 |

**b. read, tag side** — the index query for `wallet:w-1`; the `aggregate_Wallet` pair matches nothing, exactly as
it does today.

**c. read, aggregate side** — `loadAggregateEvents('_wallet', 'Wallet', 'w-1', 1, null, [WalletCredited::class,
WalletDebited::class])` → 6 events in `no` order. **No index row was consulted and none exists.**

**d. decide** — balance 100 ≥ 60, today's total under the limit → returns `PayoutRequested('w-1', 60)`, carrying
`#[EventTag('wallet')]`.

**e. guard**, one sorted pass over `{aggregate_Wallet:w-1, wallet:w-1}`:

```sql
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = 'aggregate_Wallet' AND tag_value = 'w-1' AND version = 6;   -- 1 row → 7
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = 'wallet' AND tag_value = 'w-1' AND version = 7;             -- 1 row → 8
```

**f. events** — `INSERT INTO ecotone_event_stream …` for `PayoutRequested`, aggregate columns null.

**g. index** — one row, for the `wallet` tag only: `wallet / w-1 / ecotone_event_stream / 41 / 8`. **No
`aggregate_Wallet` row, on this or any other append.**

**h. COMMIT.**

**The competing save.** A `CreditWallet` command against `Wallet w-1` captured `aggregate_Wallet:w-1` = 6 at load.
Its save runs `UPDATE … WHERE version = 6`: if it arrives after step e it blocks on Anna's row lock, then finds 7 →
**0 rows** → `DecisionModelConcurrencyException`, *"Wallet w-1 changed since it was loaded"*, nothing written, retry —
which re-reads the wallet's 7 events and decides on current state. If it commits first, Anna's step e fails instead.
Exactly one of the two decisions is taken on current state.

**Every row and every statement above except step c is verbatim what ships today.** Step c is a `SELECT` on an index
the stream has always had.

### 2.5 Consistency notes

- **The guard granularity is per aggregate instance**, which is exactly the question the model asks. A save
  recording an event type the model ignores still bumps the counter and still invalidates the decision — a spurious
  retry, conservative, and the same trade the design already makes for a mixed tag scope (§4.3).
- **The aggregate need not exist.** No events → the model folds from nothing; the counter is captured at 0; the
  guarded path for 0 is `INSERT … ON CONFLICT DO NOTHING`, which conflicts exactly when someone else created the
  aggregate in between. Deciding on the *absence* of an aggregate is safe, as it already is for a fetched one.
- **Ordering.** An aggregate-backed model's events come ordered by `no` from one query; they carry no `tag_sequence`
  and need none, because models are folded independently, each in its own order (§4.5a). `MatchedEvents` is not
  involved on this path.
- **The unique index on `(aggregate_type, aggregate_id, aggregate_version)` is untouched** and remains the
  aggregate's own guard for its own event sequence.
- **One connection.** `loadAggregateEvents()` resolves the connection from the stream name, so an aggregate whose
  `#[Stream]` is on another connection cannot be in the boundary — and here the check is **exact**, because the
  aggregate's stream is statically known. `CrossConnectionDecisionModelGuard` gains the certainty it currently has
  only for events it can trace.
- **Transactions.** Unchanged. The read adds no requirement; the guarded append already requires a transaction, and
  every aggregate save already does (D10).

### 2.6 Bootstrap guards

All in `DecisionModelDefinitionBuilder`, beside the checks it already performs:

- `aggregate:` together with `tags:` → `ConfigurationException`; one scope per model.
- `aggregate:` naming a class that is not an `#[EventSourcingAggregate]`:
  - a **state-stored** `#[Aggregate]` → names it and says it records no events, so it cannot back a model; fetch it
    with `#[Fetch]` instead, which is already guarded by its counter;
  - a `#[Saga]` / `#[EventSourcingSaga]` → rejected, as fetching a saga into a decision-model handler already is;
  - anything else → rejected.
- a handled event class that the named aggregate **does not record** (traced from the aggregate's own
  `#[EventSourcingHandler]`s, the trace §4.5a already performs) → `ConfigurationException` naming the model, the
  event and the aggregate. This replaces the tag-name rule on this path and is strictly stronger: it is the exact
  statement of "this model could never receive that event".
- the identifier unresolvable from a concrete message class → `ConfigurationException`, mirroring
  `DecisionModelTagResolvabilityGuard`.
- the aggregate's stream on a different connection than the handler's write stream → `ConfigurationException`
  naming both.

And one message improvement that makes the feature discoverable: the existing *"DecisionModel %s is scoped by no tag
name…"* exception — which is exactly what a user hits today when they write a model over an aggregate's untagged
events — gains a third remedy: *"…or, if these are one aggregate's own events, scope the model with
`#[DecisionModel(aggregate: Wallet::class)]`."*

---

## Part 3 — What changes, names only

Core (`packages/Ecotone`), and nothing outside it:

| Class | Change |
|---|---|
| `Api\Attribute\DecisionModel` | one argument: `aggregate: ?string` (a class name) |
| `DecisionModelDefinition` | carries either tag names or the aggregate class; `toCriteria()` unchanged for the tag kind |
| `AggregateBackedDecisionModelDefinition` *(new, or a second shape on the existing one)* | aggregate class, resolved `#[AggregateType]`, stream name, identifier names, handled event classes |
| `DecisionModelDefinitionBuilder` | the `aggregate:` branch and the six guards of §2.6 |
| `AggregateBackedDecisionModelLoader` *(new)* | mirrors `DecisionModelParameterLoader`: resolves identifiers (`#[Fetch]` or convention), yields the capture leaf `EventCriteria::aggregate()`, folds from `loadAggregateEvents()` |
| `DecisionModelBatchLoader` | the aggregate-backed loaders' capture leaves join the one `loadByCriteria()`; one extra fold pass reading their events |
| `DecisionModelModule` / `DecisionModelHandlers` | one more thing the single handler scan (readability item 6, shipped as step 0) produces per handler |
| `CrossConnectionDecisionModelGuard` | the exact stream/connection check for an aggregate-backed model |

**Unchanged, and this is the measure of the design:** `EventCriteria`, `AppendCondition`, `LoadedEvents`,
`TagResolver`, `AppendedTags`, `EventsTags`, `DbalTaggedEventReader`, `DbalTagIndex`, `DbalTagConditionalAppender`,
`DbalTagVersionRegister`, `DbalTagBackfiller`, `InMemoryTagIndex`, `EnterpriseInMemoryTagCollaborator`,
`EventStore`, `EventSourcingRepository`, `EventSourcedRepositoryAdapter`, `StateStoredRepositoryAdapter`,
`TagSchemaVerifier`, both console commands, `DynamicConsistencyBoundaryConfiguration`, and every table.

**`PdoEventSourcing`: no change at all.** Nothing new is written, read differently, migrated or verified.

---

## Part 4 — The questions the brief asked, answered on this design

### 4.1 Opt-in or always?

**Neither — there is nothing to switch on.** Revision 1 needed an opt-in list because index rows cost storage and a
backfill. Here the cost of an aggregate that no model reads is exactly zero: no row, no statement, no column. The
feature is "write the model", and the only gate is the one already in place — DCB registered, Enterprise licence.
`DynamicConsistencyBoundaryConfiguration` gains no option.

### 4.2 Backfill and migration?

**None, and nothing to verify.** The stream's `aggregate_type` / `aggregate_id` / `aggregate_version` columns are
the source of truth; they have been written by 1.x and by 2.0 alike since the beginning, for every aggregate event
that exists. An aggregate with ten years of history is foldable by a model on the first command after the upgrade.

Three consequences worth stating as plainly as revision 1 had to state their opposites:

- `ecotone:event-store:backfill-tags` is **not involved**. No new console command.
- `ecotone:event-store:verify-schema` needs **no coverage check**; there is no coverage to be incomplete.
- There is **no rolling-deploy hazard**. Revision 1's worst problem — a node on the previous release saving without
  writing index rows, leaving a hole in the middle of a history that a fold would silently accept — cannot occur,
  because the fold reads the events themselves. Revision 1 needed a `1..n` gapless-version assertion to make that
  window safe; here the assertion would have nothing to detect. The one operator rule that remains is the one
  already shipped for the counter: during a rolling deploy nodes on the previous release still bump the counter,
  because the counter shipped before this feature.

The 1.x note that *does* apply is an existing one: an event-sourced aggregate with existing history must declare the
`#[AggregateType]` its stream already stores (`upgrade-2.0.md`, D2) — otherwise neither its own load nor a model
over it finds anything. That is already documented and already a bootstrap requirement.

### 4.3 Snapshots and performance?

**Nothing is lost, because the aggregate's own load path is the path this uses.** Revision 1's central problem — a
criteria read has no `fromVersion`, so it cannot start from a snapshot and must read the whole history every time —
does not arise: `loadAggregateEvents()` is the same call `EventSourcingRepository::findBy()` makes, with the same
push-downs.

| | `#[Fetch]`-ed aggregate | `#[DecisionModel(aggregate: …)]` | revision 1's tag-index read |
|---|---|---|---|
| statements | 1 | 1 | 2 (index, then events) |
| access path | the stream's `(type, id, no)` index | the same | `(tag_name, tag_value)` then `no IN (…)` |
| event-type narrowing | none — the whole aggregate is rebuilt | **SQL-side** (`event_name IN`) | PHP-side, after loading everything |
| `fromVersion` | used, for snapshots | available | impossible |
| state held | the whole aggregate | only what the decision needs | only what the decision needs |

So an aggregate-backed model is **cheaper than fetching the aggregate** whenever it handles fewer event types than
the aggregate does, and never more expensive.

**Which to use when**

| Situation | Path |
|---|---|
| the aggregate's own `#[CommandHandler]` | `loadAggregateEvents()` + snapshot, exactly as today |
| a decision needs the whole aggregate's behaviour (methods, invariants) | `#[Fetch]` — unchanged, read-only, guarded by its counter |
| a decision needs one question about the aggregate's past | `#[DecisionModel(aggregate: …)]` |
| a question spanning several aggregates or non-aggregate events | a tag-scoped `#[DecisionModel]` with `#[EventTag]`, as today |

### 4.4 Snapshots for decision models become straightforward

§4.10 of the main spec lists decision-model snapshots as a follow-up, and revision 1 could only offer *per-tag*
snapshots — a new mechanism, requiring `tag_sequence` as a resumable position. For an aggregate-backed model the
resumable position already exists and is already a SQL argument: store the folded model plus the
`aggregate_version` it covers, resume with `loadAggregateEvents(..., fromVersion: covered + 1)`. The document store
and the `aggregate_snapshots_` collection convention are already in place for aggregates
(`EventSourcedRepositoryAdapter::SNAPSHOT_COLLECTION`). Deliberately out of scope here; recorded because this
design makes it a configuration change rather than a design.

### 4.5 What this does *not* do, that revision 1 would have

One capability, and it is out of DCB's scope:

**A merged history for an aggregate whose events are split across two streams** — the 1.x → 2.0 stream-migration
shape (old events in `_<sha1('Wallet')>`, new ones in `ecotone_event_stream`), or a `#[Stream]` declared on one
handler method. `loadAggregateEvents()` takes one stream, so a model backed by `Wallet` reads exactly what `Wallet`
itself reads — no better, no worse. Revision 1's index would have merged them, ordered by `tag_sequence`.

That is not a reason to build the index. An aggregate whose history is split cannot be *loaded* today either, so the
model has the same reach as the aggregate and there is no new limitation; and the tool for a split history is a
stream copy (§4 of the upgrade guide already tells users to consolidate), not a second read path that has to be
populated and backfilled forever to paper over it.

The other thing revision 1 advertised — an `EventCriteria` branch that returns an aggregate's events, for the raw
gateway and for `#[DecisionBoundary]` — is **not delivered**, and `EventStore::loadAggregateEvents()` is the public
read that covers it. A gateway user who wants events *and* the matching condition makes two calls today. See OQ 2.

---

## Part 5 — Why the index rows are not worth it (revision 1, kept for the record)

Revision 1's proposal was: the aggregate's counter tag also becomes a *carried* tag of its events, so
`AppendedTags::sequencedAfterBump()` writes one `ecotone_tagged_events` row per event with
`tag_sequence` = the bumped counter. Mechanically it was small — two changes in `TagResolver`, nothing else on the
write path. The costs were not.

| | revision 1 (index rows) | revision 2 (this design) |
|---|---|---|
| storage | +1 index row per aggregate event: **~2.2 GB per 10 M events** on PostgreSQL, ~1.1 GB on InnoDB (§4.2's own arithmetic). The index of an aggregate-heavy application roughly **doubles** | **0** |
| per-save cost | one extra `INSERT` statement and `n` extra rows, on **every save, forever** — the "populate on daily basics" the maintainer declined | **0** |
| backfill before it can be trusted | mandatory, full scan of every stream, per tenant, with a counter raise to `MAX(aggregate_version)` and a `tag_sequence` rule to invent | **none** |
| deploy gate | a new `verify-schema` coverage check | none needed |
| rolling-deploy hazard | a hole mid-history that a fold accepts silently; needed a `1..n` gapless assertion to become safe | cannot occur |
| configuration | an opt-in list per aggregate class, and a rollout order to get right | none |
| event-type narrowing | PHP-side, after loading the whole branch | **SQL-side** |
| snapshots | impossible without per-tag snapshots (a new mechanism) | `fromVersion` is already an argument |
| `EventCriteria` / `LoadedEvents` / readers / schema | all touched | **all untouched** |
| new console command | yes | no |
| buys | one merged cross-stream history (§4.5); one saved round trip | — |

The two things it bought were a saved round trip and a merged split history. The round trip is the query the user
already pays for `#[Fetch]`, and the split history is a migration artefact with a migration fix. Neither is worth a
permanent per-save write, a mandatory backfill and a doubled index.

**Recorded so the question is not reopened from scratch** (as OQ 5 of the fetched-aggregates design asked for):
the blocker on readable aggregate *tags* is not the mechanism — it is that the events are already readable by a
better key, so the index rows would be a second copy of an index the stream already has.

---

## Part 6 — Licence, gating, parity, docs, tests

**Licence and gating: nothing new.** `#[DecisionModel]` is Enterprise and `DynamicConsistencyBoundaryConfiguration`
is the single switch, both unchanged. `EventStore::loadAggregateEvents()` is open-core
(`licence Apache-2.0`) and stays so — it is reached here from an Enterprise loader, which is the same split
`EventSourcingRepository` (open-core) already has when it carries a decision model's condition. With DCB
unregistered, `#[DecisionModel(aggregate: …)]` is the same bootstrap `ConfigurationException` any decision model is,
and the append path is byte-for-byte today's single `INSERT`.

**In-memory parity is free.** `InMemoryEventStore::loadAggregateEvents()` already implements the contract, and
`EcotoneLite::bootstrapFlowTesting()` routes event-sourced aggregates through `EventStoreEventSourcedRepository`
over it, so `FlowTestSupport::withEventsFor()` seeds exactly the rows a model reads. `InMemoryTagIndex` and
`InMemoryTagVersionRegister` are untouched. The change is entirely in core classes shared by both stores, so there is
no Dbal-only behaviour to mirror.

**Docs**

- `upgrade-2.0.md`, in the DCB section after "`#[Fetch]` in a decision-model handler": a bullet — a decision model
  can be backed by an event-sourced aggregate with `#[DecisionModel(aggregate: Wallet::class)]`; its events come
  from the aggregate's own stream, narrowed to the types it handles; the boundary is the aggregate's counter, which
  already exists; no `#[EventTag]`, no migration, no configuration; `aggregate:` and `tags:` are exclusive; the
  aggregate must be event-sourced and on the handler's connection.
- Main spec §4.4: a paragraph after the criterion table — a model is scoped either by tag names or by one
  aggregate; §4.11: a pointer here. §4.10's "Decision-model snapshots" row gains the note from §4.4 above.
- `docs.ecotone.tech` DCB page: the `WalletBalance` example, and the sentence that makes the feature findable —
  *"an aggregate's events need no `#[EventTag]` to be folded by a decision model."*
- `2026-09-28-dcb-fetched-aggregates-design.md`: OQ 5 is answered "no, and here is why" with a pointer to Part 5.

**Test plan — black box, four engines plus in-memory.** Every test through `EcotoneLite` and userland APIs; no
reflection, no direct SQL against Ecotone tables.

*Flow tests (`packages/Ecotone/tests/Modelling/DecisionModel/`, `InMemoryEventStore`)*

1. A model backed by an event-sourced aggregate, with **no `#[EventTag]` on any event class**, folds the
   aggregate's history seeded by `withEventsFor()` and decides correctly.
2. Only the handled event types reach the model; an event type it does not handle is not folded (and, on Dbal, not
   read — asserted through behaviour, not statement counts).
3. A competing save of that aggregate, injected through a service called during the handler, fails the append with
   `DecisionModelConcurrencyException` rendered as the aggregate; the retry decides on current state. The existing
   `DecisionModelRetryTest` pattern.
4. One handler injecting an aggregate-backed model **and** a tag-scoped model: both captured in one statement, both
   in one `AppendCondition`; a competing write to either fails the append.
5. Two instances of the same aggregate-backed model in one handler via `#[Fetch]` (the transfer shape); both
   expectations enforced, opposite parameter order in a second handler still commits.
6. Identifier by convention (a message property named like the aggregate's identifier) and by `#[Fetch]`; a
   composite identifier through a map.
7. `?WalletBalance $wallet` receiving `null` contributes nothing and the handler still appends.
8. An aggregate that does not exist yet: the model folds empty, and a concurrent creation fails the append.
9. Bootstrap guards, one test each: `aggregate:` with `tags:`; a state-stored aggregate; a saga; a non-aggregate
   class; a handled event the aggregate does not record; an unresolvable identifier.
10. The improved "scoped by no tag name" message names the `aggregate:` remedy.
11. DCB disabled: `#[DecisionModel(aggregate: …)]` is the same bootstrap exception any model is; a `#[Fetch]`
    handler behaves as on `main`.

*Dbal integration (`packages/PdoEventSourcing/tests/Integration/Tagging/`, PostgreSQL / MySQL / MariaDB / SQLite)*

12. The §2.4 walkthrough end to end, asserted through the handler's outcome and `EventStore`.
13. An aggregate on a **legacy-shaped stream** (`#[Stream]`, 1.x table) folded by a model — the case that needs no
    migration and is the main reason to use this.
14. An aggregate with existing history and **no tag row anywhere**: the model folds it and the boundary holds from
    the first command (the no-backfill proof).
15. Two connections, the `DbalTaggedContentionTest` pattern: a second connection's aggregate save blocks on the
    counter row, then loses with `DecisionModelConcurrencyException`; and the mirror case where the blocker rolls
    back and the waiter proceeds.
16. InnoDB `REPEATABLE READ`: a model folded before a competing commit fails its append.
17. An aggregate whose `#[Stream]` is on another connection → bootstrap `ConfigurationException`.
18. A model backed by an aggregate with a configured snapshot: the aggregate's own command handler still loads
    through its snapshot; the model reads the full history (pinning that the two paths are independent).
19. No new rows: `ecotone_tagged_events` is unchanged by an aggregate save, asserted through
    `loadByCriteria(EventCriteria::aggregate(...))` returning no events — i.e. the shipped capture-only behaviour is
    still exactly that.

---

## Part 7 — Open questions, each with a recommended answer

**OQ 1 — `aggregate: Wallet::class`, or the type string on the model?**
Naming the class derives the `#[AggregateType]`, the stream, the identifier names and the counter tag, and all four
are checkable at bootstrap. Naming the type string (`#[DecisionModel] #[AggregateType('Wallet')]` on the model, plus
`#[Stream]`) would let a model be written for a type whose aggregate class does not exist in this service — a
read-side or distributed deployment.
*Recommended:* the class, now. The string form is additive if a real case appears, and until then it is three
strings to keep in sync with a class that is usually right there.

**OQ 2 — should `loadByCriteria()` learn to fulfil an aggregate branch, so the gateway and `#[DecisionBoundary]`
get the read too?**
It would make `loadByCriteria(EventCriteria::aggregate(Wallet::class, 'w-1'))` return events **and** the condition in
one call, which is the original brief's "a criterion branch that actually returns events" — delivered from the
aggregate's own columns rather than from index rows. The blocker is that `LoadedEvents` returns one flat `Event[]`
and each model re-filters it by tag; aggregate events carry no tag, so fulfilling aggregate branches inside
`loadByCriteria()` requires per-branch grouping in `LoadedEvents`, which is a userland-visible `Api/` shape, and a
capture-only form for `FetchedAggregateCounterCapture` so a fetched aggregate is not read twice.
*Recommended:* not in this change. Ship the loader-side read (§2.3 step 3), which needs none of that, and revisit
`LoadedEvents` when readability item 3 ("give the design's nouns types") opens those value objects anyway — that is
the right moment to decide whether a branch carries its own results.

**OQ 3 — identifier resolution by convention, or `#[Fetch]` only?**
`#[Fetch]`-ed *aggregates* require an expression today, and an expression string requires
`symfony/expression-language` (6.2 item 9). A convention — a message property named like the aggregate's identifier —
removes that for the common case and matches what tag-scoped models already do.
*Recommended:* both, convention first and `#[Fetch]` as the escape, with a bootstrap error when neither resolves. It
is the same two-tier rule §4.4 documents for tag values, so it is not a new concept.

**OQ 4 — should the aggregate backing a model be inferable?**
A model whose handled events are all recorded by exactly one aggregate and carry no `#[EventTag]` is, today, a
bootstrap error. Inferring `aggregate:` there would make it simply work, in the spirit of the intersection default
for tags. It is ambiguous when two aggregates record the same event class.
*Recommended:* do not infer; instead extend the existing error message with the `aggregate:` remedy (§2.6). The
error is the discovery mechanism, and it names the fix.

**OQ 5 — a model spanning an aggregate's events *and* tagged events, in one order?**
Not possible under either revision: a model is one criterion with AND-ed tags, and no event carries both an
aggregate's identity and a user tag. The existing answer stands and is documented in §4.3 of the main spec — put
`#[EventTag]` on the aggregate's event, which makes it foldable by a tag-scoped model.
*Recommended:* keep the rule, and say so in the docs bullet, because "back it with the aggregate" will be the first
thing a user reaches for when they actually want a shared tag.

**OQ 6 — is the per-instance guard too coarse?**
A save recording an event type the model ignores still invalidates the decision. Narrowing would need a counter per
event type, which is a second counter and a new schema.
*Recommended:* accept it. It is conservative, it costs at most a retry, and it is the trade already accepted for
mixed tag scopes.

---

## Part 8 — Summary

| # | Recommendation |
|---|---|
| 1 | `#[DecisionModel(aggregate: Wallet::class)]` — a model scoped by one instance of an event-sourced aggregate, folded from the aggregate's own stream by its own columns, narrowed to the handled event types in SQL |
| 2 | The boundary is the **already shipped** `aggregate_<AggregateType>` counter, captured by the batch loader's existing capture leaf and guarded by the existing append. Nothing about the boundary changes |
| 3 | **No index rows, no backfill, no per-save cost, no configuration, no schema change, no `PdoEventSourcing` change.** The change is one attribute argument, one loader class, one fold pass and six bootstrap guards, all in core |
| 4 | Identifier resolution reuses the aggregate's own vocabulary: convention on the identifier name, `#[Fetch]` as the escape, `AggregateIdString` for the one identifier string |
| 5 | `aggregate:` and `tags:` are exclusive; the aggregate must be event-sourced, must record every handled event, and must be on the handler's connection — all checked at bootstrap |
| 6 | The aggregate keeps its own load path and its snapshots; `#[Fetch]` is unchanged; decision-model snapshots become a configuration change because `fromVersion` is already an argument |
| 7 | Revision 1's index rows are declined: ~2.2 GB per 10 M events, a permanent per-save write and a mandatory backfill, to buy a round trip the user already pays and a split-stream history that a stream copy fixes |

---

## Part 9 — Outcome (2026-09-29)

Revision 2 was approved by the maintainer on 2026-09-29 with every recommended answer to Part 7's open questions
(OQ 1 the class name; OQ 2 not now; OQ 3 convention plus `#[Fetch]`; OQ 4 no inference, extend the error; OQ 5 keep
the rule and document it; OQ 6 accept the per-instance guard) and implemented in
`implement-dcb-aggregate-backed-models`. Part 2's rule, run order, walkthrough, consistency notes and six guards
shipped as written; Part 3's "what changes" held — **core only, and `PdoEventSourcing` gained no production code**.

### 9.1 What shipped

| Class | Change |
|---|---|
| `Api\Attribute\DecisionModel` | `aggregate: ?string` beside `tags` |
| `AggregateBackedDecisionModelDefinition` *(new)* | aggregate class, resolved `#[AggregateType]`, stream name, identifier names, handled event classes |
| `AggregateBackedDecisionModelInstance` *(new)* | one resolved instance: its capture leaf `EventCriteria::aggregate()`, its `AggregateIdString` and the key the batch groups reads by |
| `DecisionModelDefinitionBuilder` | the `aggregate:` branch and §2.6's guards; the "scoped by no tag name" message gained the `aggregate:` remedy |
| `DecisionModelDefinitionRegistry` | `getAggregateBacked()` beside `get()`; the raw shape carries an optional `aggregate` key |
| `AggregateBackedDecisionModelLoader` *(new)* | mirrors `DecisionModelParameterLoader`: resolves identifiers by `#[Fetch]` or convention, yields the instance, folds through `EventSourcingHandlerExecutor::fill` |
| `MessageAggregateIdentifierResolver` *(new)* | the `name` / `nameId` / `name_id` convention, for an aggregate identifier rather than a tag name |
| `DecisionModelBatchLoader` | capture leaves join the one `loadByCriteria()` first; then one `loadAggregateEvents()` per distinct `(stream, type, id)`, narrowed to the union of the handled event names on that instance |
| `DecisionModelConverterBuilder` / `DecisionModelHandlers` / `DecisionModelHandler` / `DecisionModelModule` | one more loader list per handler, chosen by `DecisionModelReflection::backingAggregateOf()` |
| `DecisionModelTagResolvabilityGuard` | the identifier-resolvability guard, beside the tag one |
| `CrossConnectionDecisionModelGuard` | the exact stream/connection check for an aggregate-backed model |

Unchanged, as Part 3 promised: `EventCriteria`, `AppendCondition`, `LoadedEvents`, `TagResolver`, `AppendedTags`,
every reader, every index, every table, `DynamicConsistencyBoundaryConfiguration`, and all of `PdoEventSourcing`.
In-memory parity was free: `InMemoryEventStore::loadAggregateEvents()` already implemented the contract, so the
flow tests and the Dbal tests exercise the same core classes.

### 9.2 What did not survive contact with the code

1. **Three guards could not live in `DecisionModelDefinitionBuilder`**, which §2.6 assumed. The builder sees one
   model class, not the handlers that inject it, so the identifier-resolvability guard went into
   `DecisionModelTagResolvabilityGuard` and the connection guard into `CrossConnectionDecisionModelGuard` — the two
   places that already scan handlers and messages. Only the four model-local guards are in the builder.
2. **`AggregateIdString::from()` agrees with the stream only for a single-identifier aggregate.** §2.2 said the same
   function feeds both the `aggregate_id` passed to `loadAggregateEvents()` and the counter tag value, "so they
   cannot disagree". That holds for one identifier. For several, `SaveAggregateServiceTemplate::enrichAggregateEvents()`
   writes the identifier *array* into `_aggregate_id` while `AggregateIdString` renders a JSON map — but such an
   aggregate cannot be loaded by its own repository either (`EventSourcingRepository::findBy()` keys on
   `reset($identifiers)`), so a model over it has exactly the reach the aggregate has, and no new limitation was
   introduced. A `#[Fetch]` expression returning a map remains the supported composite shape: it names *which*
   identifier a value is, which is what §6's test 6 exercises.
3. **`aggregate:` and `tags:` are exclusive, so `assertNoModelScopedOnlyByFilterOnlyTags` had to learn to skip
   aggregate-backed models** — an empty tag-name list trivially satisfies "scoped only by filter-only tags".
4. **`$eventNames` is matched against the stored `event_name`.** `loadAggregateEvents()` receives the model's handled
   event *classes*, exactly as `EventCriteria::ofTypes()` already does on the tag path, so a custom event-name
   mapping narrows nothing on either path. Same assumption, no regression.
5. **No SQLite pass for `PdoEventSourcing`.** §6 asked for four engines. The repository's CI deliberately skips
   SQLite for this package ("SQLite event store not supported"), and the two-connection contention tests do lock the
   file. The new Dbal tests run on PostgreSQL, MySQL and MariaDB, and skip their multi-connection cases on SQLite
   so that the rest of the suite is clean there too.
6. **The cross-connection guard test lives in `PdoEventSourcing`.** `Ecotone\Api\EventSourcing\Stream` is not
   autoloadable from `packages/Ecotone`, so the guard's test sits beside the existing
   `CrossConnectionDecisionModelTest` rather than in the core validation test, which skips it.

### 9.3 Tests

*`packages/Ecotone/tests/Modelling/DecisionModel/`* — `AggregateBackedDecisionModelValidationTest` (the six guards
and the improved message), `AggregateBackedDecisionModelTest` (folding, event-type narrowing, aggregate-version
order, identifier by convention and by `#[Fetch]` including a map, the same model class twice, a nullable parameter,
an absent aggregate, two models sharing one instance, an aggregate-backed and a tag-scoped model in one handler),
`AggregateBackedDecisionModelConcurrencyTest` (a competing save fails the append naming the aggregate, the retry
decides on current state, a save of an ignored event type still invalidates, a concurrent creation of an absent
aggregate conflicts, a competing write to the tag-scoped model in the same handler conflicts, DCB disabled is the
usual bootstrap `ConfigurationException`).

*`packages/PdoEventSourcing/tests/Integration/Tagging/`* — `AggregateBackedDecisionModelDbalTest` (the §2.4
walkthrough, event-type narrowing, no `ecotone_tagged_events` row for the counter, a wallet written with no tag row
at all folded and guarded from the first command, a save on a second connection losing the append, a rolled-back
competing save letting the decision commit, the InnoDB `REPEATABLE READ` case, and a snapshotted aggregate keeping
its own load path while the model reads the full history), `AggregateBackedDecisionModelLegacyStreamDbalTest` (a
1.x `_<sha1>` stream folded through `StreamTableRegistry`, and contended), and two more cases in
`CrossConnectionDecisionModelTest`.
