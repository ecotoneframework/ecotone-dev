# Full tags for event-sourced aggregates — design

*Written 2026-09-28 in `design-dcb-aggregate-full-tag`, on `dgafka/ecotone-2-0-dcb-design` @ `156cbd10`.
Proposal for the maintainer's approval. No production code or test was changed.*

**The question (maintainer, 2026-09-28).** The counter-only aggregate boundary has shipped: every aggregate keeps
one row in `ecotone_tag_versions` under `aggregate_<AggregateType>` / `<aggregateId>`, bumped by every save,
captured for fetched aggregates and boundaries, and **never indexed** — `loadByCriteria()` on it returns no events.
Propose the **full tag**: index rows in `ecotone_tagged_events` for an event-sourced aggregate's own events under
that same tag, so the aggregate becomes **loadable by `EventCriteria`** — injectable and foldable like a decision
model, ordered with other tags by `tag_sequence`, and usable as a criterion branch that actually returns events.

**The answer in one paragraph.** Full tagging is a two-line change in `TagResolver` and nothing else on the write
path: the aggregate's counter tag, which is already captured and bumped, additionally joins every appended event's
carried tags, so the existing index insert stamps one row per event with `tag_sequence` = the bumped counter. That
makes `loadByCriteria(EventCriteria::aggregateEvents(Wallet::class, 'w-1'))` return the wallet's whole history in
commit order, a `#[DecisionModel(aggregate: Wallet::class)]` fold it without any `#[EventTag]` on the event classes,
and a `#[DecisionBoundary]` branch return real events. It should be **opt-in per aggregate class**, because it
roughly doubles the index of an aggregate-heavy application and, unlike the counter, it **cannot be trusted until a
backfill has run** — the one promise ("no backfill") the shipped boundary makes loudest. The backfill is far cheaper
than the `#[EventTag]` one: the tag is derivable from `_aggregate_type`/`_aggregate_id`/`_aggregate_version` in SQL,
so it is a set-based `INSERT … SELECT` with no payload deserialisation, with `tag_sequence = aggregate_version`. The
aggregate keeps its own load path — `loadAggregateEvents()` plus snapshots — because a criteria load cannot start
from a snapshot and has no `fromVersion`; full tag adds a second, read-only path for everyone *else*. And because an
aggregate's history has a known shape, the rolling-deploy hazard that the `#[EventTag]` index could only document
becomes a free, exact runtime assertion here: the folded events' `_aggregate_version` must run `1..n` without gaps,
or the fold throws and names the backfill command instead of deciding on half a history.

---

## Part 1 — What "full tag" adds, precisely

### 1.1 The one fact everything else follows from

The shipped code already distinguishes two roles a tag can play in an append, and the aggregate counter plays only
the first:

| Role | Where it lives in the code | What it produces |
|---|---|---|
| **condition tag** — captured, then bumped with the guarded `UPDATE` | `AppendedTags::$involved`, fed by `TagResolver::counterTagOfSavedAggregate()` | one row touched in `ecotone_tag_versions` |
| **carried tag** — the event publishes this value under this name | `EventsTags::$perEvent`, fed by `TagResolver::tagsCarriedBy()` from `#[EventTag]` | one row per (event, tag) in `ecotone_tagged_events`, stamped with `tag_sequence` |

`AppendedTags::sequencedAfterBump()` builds the index rows from `EventsTags` alone. The aggregate counter tag
reaches `$involved` but never `$perEvent`, which is exactly why it is guarded and never indexed.

**Full tag = the aggregate's counter tag also becomes a carried tag of every event of that aggregate's append.**

`AppendedTags` keys `$involved` by `TagKey`, so the tag arriving from both sides collapses to one entry: one
capture, one guarded bump, unchanged. `EventsTags::sequencedBy()` then stamps every event of the append with
`expectedVersion + 1` — the bumped counter, which is what the question asks for.

### 1.2 The rows an event-sourced save writes

`Wallet w-1` is at counter 4. A command records two events, `PayoutApproved` (which also carries
`#[EventTag('payout')]`) and `WalletDebited` (no `#[EventTag]` at all). The stream is `ecotone_event_stream`,
next `no` is 41.

**Today (counter only)**

| table | rows |
|---|---|
| `ecotone_tag_versions` | `aggregate_Wallet` / `w-1` : 4 → **5** (guarded `UPDATE … WHERE version = 4`); `payout` / `p-9` : 0 → **1** |
| `ecotone_event_stream` | `no` 41 `PayoutApproved`, `no` 42 `WalletDebited`, both with `_aggregate_type = Wallet`, `_aggregate_id = w-1`, `_aggregate_version` 5 and 6 |
| `ecotone_tagged_events` | `payout` / `p-9` / `ecotone_event_stream` / 41 / **1** |

**With full tag on `Wallet`** — the first two tables are byte-for-byte identical; the third gains two rows:

| tag_name | tag_value | stream_name | event_no | tag_sequence |
|---|---|---|---|---|
| aggregate_Wallet | w-1 | ecotone_event_stream | 41 | **5** |
| aggregate_Wallet | w-1 | ecotone_event_stream | 42 | **5** |
| payout | p-9 | ecotone_event_stream | 41 | 1 |

Note what the two new rows are *not*: they are not a second counter, not a second guard, and not a change to the
stream table or its unique index. They are the same shape as the `payout` row next to them, and they are written by
the same `DbalTagIndex::insertRows()` statement in the same transaction.

**Both events of one append share `tag_sequence` 5.** That is the shipped rule for every tag (`EventsTags::sequencedBy()`
uses one version-after-bump per tag key, not per event), and it is correct: one append goes to one stream, so `no`
orders within it, and `MatchedEvents::inSequenceOrder()` sorts by `(tag_sequence, event_no)`.

### 1.3 What `loadByCriteria()` then returns, and in which order

```php
$loaded = $eventStore->loadByCriteria(EventCriteria::aggregateEvents(Wallet::class, 'w-1'));
```

returns `LoadedEvents` holding

- **the events**: every event of `Wallet w-1` that has an index row, deserialized, with their metadata — including
  `_aggregate_version`, which is how the fold can be checked (§1.6) and how a version can be recovered;
- **the `AppendCondition`**: the captured version of `aggregate_Wallet` / `w-1`, exactly as the capture-only leaf
  produces today, so the guard story of the shipped design is untouched.

**The order is `(tag_sequence, event_no)`**, which for one aggregate is commit order of its saves, then position
within a save — i.e. **`aggregate_version` order**, the same sequence `loadAggregateEvents()` returns. Two
independent mechanisms produce that agreement: the counter is bumped under its own row lock before any event row is
written (§4.5 "counters first, events second", so for one tag `no` order equals commit order), and the stream's
unique index on `(aggregate_type, aggregate_id, aggregate_version)` refuses to let two saves claim one version.

**Cross-stream ordering becomes real, and it is worth more than it first looks.** `loadAggregateEvents()` takes one
`$streamName` and `EventSourcingRepository` resolves exactly one stream per aggregate class, so an aggregate whose
history is split across two tables **cannot be loaded at all today**. That is not a hypothetical: it is the shape of
a 1.x → 2.0 stream migration (old events in `_<sha1('Wallet')>`, new ones in `ecotone_event_stream`) and of a
`#[Stream]` declared on one handler method. With full tags and both tables backfilled, the criteria load returns the
merged history in one ordered sequence, because `tag_sequence` is assigned per tag and not per table. This is the
single most concrete capability full tag adds that nothing else in 2.0 offers.

### 1.4 The three read surfaces, and what the user writes

**(a) The store gateway — no class at all.**

```php
$loaded = $eventStore->loadByCriteria(EventCriteria::aggregateEvents(Wallet::class, $walletId));
foreach ($loaded->events as $event) { /* … */ }
$eventStore->appendTo('ecotone_event_stream', [new PayoutRequested(...)], $loaded->appendCondition);
```

`EventCriteria::aggregate()` and `EventCriteria::aggregateEvents()` name the same tag pair and differ in one
property: whether the branch is read. See §1.5 — keeping them apart is not a nicety, it is what stops the shipped
aggregate-only boundary from silently turning into a full-history scan.

**(b) A decision model scoped on the aggregate** — "injectable and foldable like a decision model", with the
type hint still doing the assembly:

```php
#[DecisionModel(aggregate: Wallet::class)]           // criterion: aggregate_Wallet:<walletId> ∧ handled types
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
        if (! $wallet->canCover($command->amount))                      { throw new InsufficientFunds(); }
        if ($today->total() + $command->amount > self::DAILY_LIMIT)     { throw new DailyLimitExceeded(); }

        return [new PayoutRequested($command->walletId, $command->amount)];
    }
}
```

`WalletCredited` and `WalletDebited` need **no `#[EventTag]`** — that is the point. They are indexed because the
aggregate that recorded them is loadable by criteria. The model's tag *name* comes from `Wallet`'s
`#[AggregateType]`, so nothing in userland spells `'aggregate_Wallet'`; the tag *value* is resolved from the message
the way every model's is (a property named `walletId`, or `#[Fetch('payload.walletId')]` when the convention cannot
decide). The handler gets one pre-invocation `loadByCriteria()` for `WalletBalance` and `PayoutsToday` together, one
`AppendCondition` covering both, and one guarded pass.

Against the shipped alternative — `#[Fetch('payload.walletId')] Wallet $wallet` — this buys: a model that holds only
the state the decision needs rather than the whole aggregate; reuse of that question across handlers; and one read
instead of two (the capture branch and the aggregate's own query become the same statement). It costs the snapshot
(§4). Both stay available; they are not exclusive, and a handler may inject one of each.

**(c) A `#[DecisionBoundary]` branch that returns events.**

```php
#[DecisionBoundary]
public static function boundary(SettleOrder $command): EventCriteria
{
    return EventCriteria::aggregateEvents(Wallet::class, $command->walletId)
        ->or(EventCriteria::tag('coupon', $command->couponCode));
}
```

Both branches are ORed into the one pre-invocation load and both are guarded. Each branch is ordered by *its own*
primary tag's `tag_sequence` — the shipped `DbalTaggedEventReader::matchingEvents()` already works per branch — so
an aggregate branch and a tag branch sort independently and correctly even though their counters are unrelated.

### 1.5 `EventCriteria::aggregate()` must stay capture-only

The shipped `EventCriteria::aggregate()` exists for the aggregate-only boundary (D6): it names the counter so the
handler captures and guards it, and it matches no events *because there are no index rows*. Once `Wallet` is
indexed, that same call would load the wallet's entire history on every command and throw it away — the batch loader
folds only model criteria, so boundary events are discarded. For a wallet with 50 000 events that is a 50 000-row
index read plus a 50 000-row `IN (…)` fetch per command, silently introduced by enabling an unrelated option.

So the read intent belongs in the criterion, not in the configuration:

| Factory | Captures the counter | Reads events |
|---|---|---|
| `EventCriteria::aggregate(Wallet::class, $id)` | yes | **no** (unchanged from what shipped) |
| `EventCriteria::aggregateEvents(Wallet::class, $id)` | yes | yes |

Mechanically: an `EventCriteria` branch answers `readsEvents()`, and `TagResolver::tagsOfCriteria()` splits into the
tags to **capture** (every branch, as today) and the tags to **read** (reading branches only). The Dbal reader passes
the read set to `DbalTagIndex::flagsFor()` and skips non-reading branches in `matchingEvents()`; the in-memory index
does the same in `eventsMatching()`. `FetchedAggregateCounterCapture` keeps building the capture-only leaf, so a
`#[Fetch]`-ed aggregate costs exactly what it costs today, indexed or not.

### 1.6 The read side has to learn the tag too — and gets a free integrity check

`TagResolver::eventsMatching()` decides which loaded events belong to which model by **re-resolving each event's
tags from its payload** through `tagsCarriedBy()`, which reads `#[EventTag]` and nothing else. An aggregate-scoped
model's criterion requires `aggregate_Wallet:w-1`; `WalletDebited` declares no attribute, so without a change the
model would match nothing and fold an empty history — a silently wrong decision, the failure mode this design
refuses everywhere.

The fix keeps one source of truth: `tagsCarriedBy()` derives the counter tag from the **event's own aggregate
metadata** (`_aggregate_type`, `_aggregate_id`) when that type is loadable by criteria, which is the same pair the
write side derived it from. It is a property of the event, resolvable both from the object being appended (via the
`AppendCondition`'s aggregate part) and from the `Event` being read (via its metadata), and the two agree by
construction.

That metadata carries one more thing no user tag has: `_aggregate_version`. An aggregate's history has a **known
shape** — versions `1..n`, gapless — so a fold over an aggregate branch can assert it at zero cost, on data already
in memory:

> **`AggregateHistoryCompleteness`** — when events matched for an aggregate branch do not form a gapless
> `1..n` run of `_aggregate_version`, throw, naming the aggregate, the first missing version, and
> `ecotone:event-store:backfill-aggregate-tags`.

This is what turns the backfill from an operator rule into a loud failure, and it is available **only** for
aggregates: for `#[EventTag]` there is nothing to compare against, which is why §4.8 of the main spec had to decline
a coverage guard. It catches a missing backfill, an interrupted backfill, a rolling deploy where an older node
appended without index rows, and a stream whose index rows were dropped by `EventStore::delete()`. It does not catch
a *type-narrowed* read — see §4.4, where the narrowing happens in PHP after the full branch is loaded, which is
precisely why the check still holds.

### 1.7 What does *not* change

A claim worth stating precisely, because it is the measure of whether this design is the right size:

| Class | Change |
|---|---|
| `AppendedTags` | **none** — the tag collapses by `TagKey`; one capture, one guarded bump |
| `DbalTagConditionalAppender`, `DbalTagIndex`, `DbalTagVersionRegister` | **none** on the write path |
| `InMemoryTagIndex`, `EnterpriseInMemoryTagCollaborator` | **none** |
| `EventStore`, `AppendCondition`, `EventSourcedRepository`, `StateStoredRepository` | **none** |
| `ecotone_tagged_events`, `ecotone_tag_versions`, every stream table | **no schema change** |
| the aggregate's unique index and `#[Version]` | untouched |
| state-stored aggregates | untouched — they record no events, so there is nothing to index (a state-stored class opted in is a bootstrap `ConfigurationException`) |

The write-side change is confined to `TagResolver::resolveAppend()`; the read-side change to
`TagResolver::tagsCarriedBy()`, `TagResolver::tagsOfCriteria()` and the two readers' branch filtering.

---

## Part 2 — Opt-in or always

### 2.1 The cost, with the spec's own arithmetic

§4.2 of the main spec sizes an index row at **~220 bytes on PostgreSQL** (≈110 B heap plus as much again in the
primary key) and **roughly half that on InnoDB**, which clusters the table on its primary key. Full tag writes
**exactly one row per aggregate event** — no more, no fewer, regardless of how many `#[EventTag]`s the event carries.

| Application | Aggregate events | Index today | Index with full tag on every aggregate |
|---|---|---|---|
| 1.x app newly on 2.0, no `#[EventTag]` yet | 10 M | **0** | ~2.2 GB (PG) / ~1.1 GB (InnoDB) |
| DCB app, two user tags on half its events | 10 M aggregate + 2 M decision-model events | ~2.6 GB (PG) | ~4.8 GB (PG) |

The second row is the honest headline: **for an aggregate-heavy application the index roughly doubles.** For the
first row it goes from nothing to a table the size of a small event store.

Three further costs, in decreasing order of how much they matter:

1. **The backfill is mandatory before the read can be trusted.** The shipped boundary's loudest promise is "No
   backfill" (§4.9 of the fetched-aggregates design, repeated in `upgrade-2.0.md`): a missing counter row is version
   0 and the guarded insert conflicts correctly. An index row has no such property — a missing row is an event the
   fold does not see. Turning full tag on for every aggregate means every application enabling DCB inherits a
   full-scan migration of every stream before any aggregate-scoped model is safe.
2. **Write amplification is small but not nil.** An aggregate save whose events carry no `#[EventTag]` today issues
   no index statement at all; with full tag it issues one, inserting one row per event. Measured against the append
   it already performs (counter `UPDATE` + event `INSERT`, inside a transaction that is now mandatory), that is one
   extra statement per save and `n` extra rows.
3. **`stream_name` is the fattest column** (§4.2) — about a fifth of a row for `ecotone_event_stream`, a third for a
   41-character legacy `_<sha1>` name. Legacy 1.x aggregate streams are exactly where the aggregate rows land, so
   full tag is the workload that would most benefit from the stream-name lookup table §4.2 deliberately deferred.

On the other side of the ledger, the shape of the aggregate tag is kind to the index: the primary key is
`(tag_name, tag_value, stream_name, event_no)`, so one aggregate type's rows cluster together and one instance's rows
are contiguous and appended in order — good read locality, and no single insert hot spot, because the hot point is
per aggregate id.

### 2.2 The recommendation: opt in, per aggregate class, on the extension object

```php
#[ServiceContext]
public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
{
    return DynamicConsistencyBoundaryConfiguration::createWithDefaults()
        ->withAggregatesLoadableByCriteria([Wallet::class, Order::class]);
}
```

**Why opt-in rather than always.** The costs above are not paid for a benefit — they are paid per aggregate, and the
benefit is only collected for aggregates something actually reads by criteria, which in any real application is a
handful. Doubling the index of every aggregate so that two of them can be folded is the wrong default, and the
mandatory backfill makes "always" a breaking operational change to an upgrade path whose selling point is that it
has none.

**Why the extension object rather than an attribute on the aggregate class.** The previous design answered the
mirror question the other way (OQ 2: derive membership from handler signatures, do not declare it) and was right to:
*boundary membership* is a consistency decision, and the accident it guards against is one we want to happen
automatically. This is a different kind of decision. Indexing is a **storage decision with a migration attached** —
adding a class to the list means a table grows and a backfill must run before the next release relies on it. That
belongs where an operator can grep for it, next to `withFilterOnlyTags()`, on the one object that already decides
whether DCB runs at all, and not scattered across aggregate classes where a merge can enable it without anyone
noticing. It is also the level at which it is *reversible*: removing a class stops new index rows; the old ones are
dead weight until `EventStore::delete()` or a manual `DELETE`.

**Why per class rather than one global switch.** So the backfill is per stream and can be run, verified and rolled
out one aggregate at a time — which is what makes the operator runbook in §3.5 short.

The name says what it buys rather than how it is stored: an aggregate on this list is **loadable by
`EventCriteria`**. `withAggregatesLoadableByCriteria()` reads as the capability; `withIndexedAggregates()` would read
as the mechanism.

**Bootstrap guards** (`AggregateCounterTagGuard`, which already owns the counter-name rules):

- a class in the list that is not an `#[Aggregate]`/`#[EventSourcingAggregate]` → `ConfigurationException`;
- a **state-stored** aggregate in the list → `ConfigurationException` naming it and saying that a state-stored
  aggregate records no events, so there is nothing to index (its counter still works, and that is the boundary it
  has);
- a `#[Saga]`/`#[EventSourcingSaga]` in the list → `ConfigurationException`, for the same reason sagas are exempt
  from the counter;
- `#[DecisionModel(aggregate: X::class)]` where `X` is not in the list → `ConfigurationException` naming both the
  model and the `with*` call that would fix it. This is the check that makes the whole opt-in safe: a model can
  never be written against an aggregate whose events are not indexed.

---

## Part 3 — Backfill and migration

### 3.1 Why this backfill is a different animal

`ecotone:event-store:backfill-tags` must **deserialize every event** to ask its class for `#[EventTag]` values —
"hours on tens of millions of rows", a full scan through PHP, with `--skip-undeserializable` for payloads that no
longer decode.

The aggregate tag needs none of that. Everything it requires is in columns the store already indexes:

| Index column | Source |
|---|---|
| `tag_name` | `'aggregate_' ‖ _aggregate_type` |
| `tag_value` | `_aggregate_id` |
| `stream_name` | the table being scanned |
| `event_no` | `no` |
| `tag_sequence` | `_aggregate_version` (§3.3) |

`EventStreamSchema::metadataFieldExpression()` already renders each of those per engine — real generated columns on
MySQL/MariaDB, `metadata->>'…'` on PostgreSQL, `json_extract()` on SQLite — and the stream carries an index on
`(aggregate_type, aggregate_id, no)`. So the backfill is a **set-based `INSERT … SELECT`**, one statement per batch,
no PHP deserialisation, no `--skip-undeserializable`, and it reads only rows that have aggregate metadata.

That is why it deserves its own command rather than a flag on the existing one: the mechanics share nothing but the
destination table.

```
ecotone:event-store:backfill-aggregate-tags
    [--aggregate=Wallet]      # one #[AggregateType]; default: every aggregate on the loadable-by-criteria list
    [--stream=]               # default: the aggregate's own stream; repeat for a split history (§1.3)
    [--batch-size=1000]
    [--from-no=]
    [--dry-run]
```

`--dry-run` reports what it would write without writing; the report mirrors `TagBackfillReport` (last `no`
processed, rows scanned, index rows written, counters raised).

### 3.2 The two statements, per batch, in one transaction

**Counters first, as everywhere else in this design.** For every `(aggregate_type, aggregate_id)` in the batch, raise
the counter to at least the aggregate's highest version:

```sql
-- missing row
INSERT INTO ecotone_tag_versions (tag_name, tag_value, version) VALUES (:k, :v, :maxVersion)
ON CONFLICT DO NOTHING;                             -- MySQL/MariaDB: INSERT IGNORE

-- existing row: raise, never lower
UPDATE ecotone_tag_versions SET version = :maxVersion
WHERE tag_name = :k AND tag_value = :v AND version < :maxVersion;
```

Then the index rows, idempotent on the primary key:

```sql
INSERT INTO ecotone_tagged_events (tag_name, tag_value, stream_name, event_no, tag_sequence)
SELECT 'aggregate_' || <type expr>, <id expr>, :streamName, no, <version expr>
FROM <stream>
WHERE no >= :fromNo AND no < :toNo AND <type expr> = :aggregateType
ON CONFLICT (tag_name, tag_value, stream_name, event_no) DO NOTHING;
```

`ON CONFLICT DO NOTHING` / `INSERT IGNORE` makes the whole command **re-runnable**, which is the same property
`DbalTagIndex::insertRowsIdempotently()` gives the `#[EventTag]` backfill — but here it falls out of the primary key
without the sentinel "was the first tagged event of this batch already indexed?" probe that `DbalTagBackfiller` needs.

### 3.3 `tag_sequence` for historical events: `aggregate_version`

Three candidates, and the arithmetic settles it.

| Rule | Orders correctly | Derivable in SQL | Agrees with live appends |
|---|---|---|---|
| **`aggregate_version`** | yes — it *is* the aggregate's order | yes, one column | yes, once the counter is raised to `MAX(aggregate_version)` |
| a counter bumped once per backfilled *event* | yes | no — needs a read-back per row | drifts: the counter would end far above any save count |
| the shipped live rule, one bump per *append* | there is no recorded append boundary in history | no | — |

The third is not available: history does not record which events were written together, so the live rule cannot be
reconstructed. The second costs a round trip per row and throws away a number already in the table. The first is
exact, gapless, monotonic, free, and — the property that matters — **it is the same order the live rule produces**,
because a live append stamps all of its events with the bumped counter and the counter is raised to
`MAX(aggregate_version)` first. Concretely, for `Wallet w-1` with 6 historical events and then a two-event save:

| event | `_aggregate_version` | `tag_sequence` | assigned by |
|---|---|---|---|
| 1 … 6 | 1 … 6 | 1 … 6 | backfill |
| 7 | 7 | **7** | live append (counter 6 → 7) |
| 8 | 8 | **7** | the same live append |

Sorting by `(tag_sequence, event_no)` yields `1,2,3,4,5,6,7,8` — aggregate-version order, which is what
`loadAggregateEvents()` returns. The counter's absolute value changes meaning slightly (it counted saves; after a
backfill it counts at least as high as events), which is harmless: §4.9 of the fetched-aggregates design already
establishes that only "did it move" is ever read, and raising it can only cause a spurious conflict, never miss one.

### 3.4 Raising the counter is a write — and that is the right behaviour

A save committing while the backfill runs is the interesting case. The counter raise happens **before** the index
insert and under the counter's row lock, so:

- a save that captured the counter at 4 and reaches its guarded `UPDATE … WHERE version = 4` after the backfill set
  it to 6 affects **0 rows** → `DecisionModelConcurrencyException` → nothing written → the configured retry re-reads
  and succeeds against the raised counter. A spurious conflict, in the safe direction.
- a save that commits *first* raises `MAX(aggregate_version)` by one; the backfill's `WHERE version < :maxVersion`
  then leaves the higher value alone, and its `INSERT … SELECT` picks up the new rows too, or the next batch does.

So the backfill is **online-safe**: it costs some retries on aggregates it is passing through, bounded to the
duration of the run, and it never produces a wrong order. Applications that cannot absorb retries run it in a quiet
window, per aggregate — which the `--aggregate=` scoping is for. Both facts belong in the runbook, not in a caveat.

Two operational notes inherited unchanged from the shipped design: a tagged write **requires an active transaction**,
so the backfill runs under `DbalConfiguration::withTransactionOnConsoleCommands()`, and on a multi-tenant setup the
`tenant` header selects the connection, so the command is run once per tenant (`--header "tenant:a"`) and fails
loudly with no header.

### 3.5 The operator runbook

Per aggregate, and the order is the whole point:

| # | Step | Why this order |
|---|---|---|
| 1 | Deploy 2.0 with the aggregate boundary (already done — this is the shipped baseline) | counters exist and are bumped |
| 2 | Release adding `->withAggregatesLoadableByCriteria([Wallet::class])`, **to every node** | from here on, every `Wallet` save writes index rows. Until every node is on it, some saves do not — which is exactly what step 4 detects and step 3 repairs |
| 3 | `ecotone:event-store:backfill-aggregate-tags --aggregate=Wallet`, and **wait for it to finish**; repeat per stream if the history is split (§1.3), and per tenant | re-runnable; run it again after step 2 has fully rolled out to sweep up anything a lagging node missed |
| 4 | `ecotone:event-store:verify-schema` — the coverage check (§3.6) reports `Wallet` complete | the deploy gate |
| 5 | Release adding `#[DecisionModel(aggregate: Wallet::class)]` / `EventCriteria::aggregateEvents()` | nothing reads the index before it is complete |

**Rollback.** Removing the class from the list at step 2 stops new index rows immediately; the rows already written
are inert (nothing queries that tag) and can be deleted at leisure. Nothing in the stream tables, the counters or
the aggregate's own load path changed, so there is no state to undo. This is a materially better rollback story
than the `#[EventTag]` index has, and it is a direct consequence of the aggregate keeping its own load path (§4).

**The residual hazard** is the window in step 2 — a node on the previous release saves a `Wallet` without writing
index rows, leaving a hole in the middle of the history. The shipped design could only document the equivalent
hazard for `#[EventTag]`. Here step 3-after-rollout closes it, and `AggregateHistoryCompleteness` (§1.6) catches it
at read time if it was not. That combination is why I am comfortable recommending full tag at all.

### 3.6 `verify-schema` gains a coverage check

`TagSchemaVerifier` today checks *structure* — the tag tables' primary keys and collations, and nullability on
streams. Coverage is a different kind of question, and for aggregates it is answerable exactly and cheaply, which it
is not for `#[EventTag]`. Per aggregate on the list, two grouped counts against one stream:

```sql
SELECT count(*) FROM <stream> WHERE <type expr> = :aggregateType;
SELECT count(*) FROM ecotone_tagged_events WHERE tag_name = :counterName AND stream_name = :streamName;
```

Equal → complete. Unequal → the command prints the shortfall and the exact
`ecotone:event-store:backfill-aggregate-tags --aggregate=… --stream=…` to run. It is reported as a *data* check,
visually separated from the schema checks, and it is the CI/deploy gate for step 4. Both queries are index-only on
every engine.

---

## Part 4 — Snapshots and performance

### 4.1 The two read paths, priced

| | `loadAggregateEvents()` (today) | `loadByCriteria(aggregateEvents(...))` |
|---|---|---|
| statements | **1** | **2** — index flags, then events per stream |
| access path | range scan on the stream's own `(aggregate_type, aggregate_id, no)` index | index seek on `(tag_name, tag_value)`, then `no IN (…)` on the stream |
| `fromVersion` push-down | **yes** (`WHERE aggregate_version >= :from`) | **no** — the criterion has no version vocabulary |
| snapshots | **yes** — `EventSourcedRepositoryAdapter` loads the snapshot and asks for `version + 1` onwards | **no** — there is nothing to start from |
| event-type narrowing | `$eventNames` in the query | **no** — `DbalTaggedEventReader` filters by type in PHP *after* loading every flagged event (§4.4) |
| crosses streams | **no** | **yes** (§1.3) |
| cost for a 1 000-event aggregate with a snapshot at 900 | 100 rows, 1 query | 1 000 index rows + 1 000 event rows, 2 queries, `IN` list of 1 000 |

### 4.2 What is lost, and the recommendation

An aggregate loaded by criteria bypasses the two things that make a long-lived aggregate affordable: the snapshot
and `fromVersion`. For an aggregate with a configured snapshot, the criteria path is not marginally worse — it is
the whole history versus the tail, every time.

**Recommendation: the aggregate keeps its own load path, unconditionally.** `EventSourcingRepository::findBy()`,
`EventSourcedRepositoryAdapter`'s snapshot handling and `#[Fetch]`'s `FetchAggregateConverter` are untouched by this
proposal. Full tag adds a **second, read-only** path, used by `loadByCriteria()`, by `#[DecisionBoundary]` branches
and by aggregate-scoped decision models — never by the aggregate instance itself.

This is the answer to the brief's either/or: **other models gain the ability to fold an aggregate's events; the
aggregate does not change how it loads.** Three reasons, in order of weight:

1. the snapshot arithmetic above;
2. it keeps the change additive and the rollback trivial (§3.5) — the aggregate's correctness never depends on the
   index being complete;
3. folding a `#[Fetch]`-ed aggregate from the batch buys one round trip and closes a *spurious*-retry window (today
   the capture and the aggregate's own read are two statements; a commit in between makes the instance newer than
   the capture, which fails the guard and retries — safe, just wasteful). One round trip is not worth the snapshot.

**When to load which way**

| Situation | Path |
|---|---|
| the aggregate's own `#[CommandHandler]` | `loadAggregateEvents()` — always, snapshots included |
| `#[Fetch]`-ed aggregate in a decision-model handler | `loadAggregateEvents()` + the capture-only counter leaf, exactly as shipped |
| a question about the aggregate's past, in a handler that appends | `#[DecisionModel(aggregate: …)]` — folds only the event types it handles, holds only the state it needs |
| an aggregate whose history is split across streams (1.x migration, `#[Stream]` on a method) | `loadByCriteria(aggregateEvents(…))` — the only path that returns it merged and ordered |
| ad-hoc read in a `#[QueryHandler]`, no projection wanted | `loadByCriteria(aggregateEvents(…))` |

### 4.3 Per-tag snapshots are the thing that would change this answer

§4.10 of the main spec lists per-tag snapshots as a follow-up, and OQ 5 of the fetched-aggregates design already
recorded that they are what makes a readable aggregate tag "a rename rather than a new mechanism". The shape is
now clearer, and full tag is its **prerequisite**, not the other way round: a snapshot keyed
`(tag_name, tag_value)` storing a folded state plus the `tag_sequence` it covers lets the index read start at
`WHERE tag_sequence > :covered`, which is the `fromVersion` push-down the criteria path lacks — and it works for
decision models too, which have no aggregate columns to fall back on. Deliberately out of scope here; recorded so
the question is not reopened from scratch.

### 4.4 Two read-path inefficiencies this exposes

Neither is caused by full tag, but full tag is what makes them bite, so they belong in the record:

1. **`ofTypes()` does not narrow the read.** `DbalTaggedEventReader::loadByCriteria()` calls
   `eventsReferencedBy()` on *every* flagged event and filters by event name in PHP. §4.5 of the main spec sketched
   `AND event_name IN (:types)` in statement 3; the shipped code does not do it. For a decision model over a tag
   with a handful of events that is invisible; for an aggregate-scoped model that handles 2 of an aggregate's 12
   event types it means loading six times what it folds. Worth a separate work item — and note that
   `AggregateHistoryCompleteness` (§1.6) depends on the current behaviour, so if the narrowing lands, the check
   must move to the flags (which carry `event_no` for the unnarrowed branch) rather than to the folded events.
2. **The `IN (…)` list is unbounded.** A 50 000-event aggregate produces a 50 000-element `IN` list. The existing
   code has the same exposure for a hot tag; an aggregate makes long histories ordinary. Batching
   `loadEventsByNumbers()`, or reading by `no` range when the flags are contiguous (which for one aggregate in one
   stream they very often are), is the obvious follow-up. Also a separate work item.

---

## Part 5 — Consistency semantics

### 5.1 The claim

With index rows, the aggregate's events participate in cross-stream `tag_sequence` ordering — **and the guard does
not change at all**, because the counter was already bumped once per save and the index row merely copies the
value it was bumped to. The unique index remains the aggregate's own guard for its own event sequence.

### 5.2 Walkthrough — a coupon and a wallet, one handler

`Wallet` is `#[EventSourcingAggregate]`, on the legacy stream `_wallet` (a 1.x table), and is on the
loadable-by-criteria list. `CouponRedemptions` is a `#[DecisionModel]` scoped on the user tag `coupon`, folding
`CouponIssued` and `OrderPlaced` from `ecotone_event_stream`. `WalletBalance` is
`#[DecisionModel(aggregate: Wallet::class)]`.

```php
#[CommandHandler]
public function settle(
    SettleOrder $command,
    WalletBalance $wallet,           // aggregate_Wallet : w-1
    CouponRedemptions $coupon,       // coupon : SUMMER24
): array
```

**Starting state.** `Wallet w-1` has three events, backfilled: `_aggregate_version` 1–3, `tag_sequence` 1–3, counter
`aggregate_Wallet:w-1` = 3. The coupon has one event, `tag_sequence` 1, counter `coupon:SUMMER24` = 1.

`ecotone_tagged_events`

| tag_name | tag_value | stream_name | event_no | tag_sequence |
|---|---|---|---|---|
| aggregate_Wallet | w-1 | _wallet | 1 | 1 |
| aggregate_Wallet | w-1 | _wallet | 2 | 2 |
| aggregate_Wallet | w-1 | _wallet | 3 | 3 |
| coupon | SUMMER24 | ecotone_event_stream | 1 | 1 |

**① A wallet command runs first**, recording `WalletCredited` — an ordinary aggregate save, no decision model
involved:

| step | statement | result |
|---|---|---|
| capture (in-transaction) | `SELECT … WHERE tag_name='aggregate_Wallet' AND tag_value='w-1'` | 3 |
| guard | `UPDATE … SET version = version+1 WHERE … AND version = 3` | 1 row → **4** |
| event | `INSERT INTO _wallet …`, `_aggregate_version = 4` | `no` = 4; the unique index on `(Wallet, w-1, 4)` is checked here, as today |
| index | one row: `aggregate_Wallet / w-1 / _wallet / 4 / 4` | **new with full tag** |
| COMMIT | | |

**② The handler runs.** Nothing is locked while it thinks.

| step | statement | result |
|---|---|---|
| a. capture — one statement, both pairs | `SELECT … WHERE (tag_name,tag_value) IN (('aggregate_Wallet','w-1'),('coupon','SUMMER24'))` | `aggregate_Wallet:w-1` = **4**, `coupon:SUMMER24` = **1** |
| b. read index — one statement, both pairs | `… FROM ecotone_tagged_events WHERE … GROUP BY stream_name, event_no` | 5 rows: four `_wallet` events (sequences 1–4), one `ecotone_event_stream` event (sequence 1) |
| c. read events — one statement **per stream** | `SELECT … FROM _wallet WHERE no IN (1,2,3,4)`; `SELECT … FROM ecotone_event_stream WHERE no IN (1)` | |
| d. fold | `WalletBalance` gets the four wallet events in `(tag_sequence, event_no)` = 1,2,3,4; `CouponRedemptions` gets the coupon event. Each branch folds in **its own** primary tag's sequence | |
| e. decide | returns `OrderSettled(…)`, carrying `#[EventTag('coupon')]` | |

The two models were fed from **two different tables** by one index query, each in its own exact order, and neither
declared which stream it reads — §4.5a's promise, now extended to aggregate events.

| step | statement | result |
|---|---|---|
| f. guard, one sorted pass over `{aggregate_Wallet:w-1, coupon:SUMMER24}` | `UPDATE … 'aggregate_Wallet','w-1' … AND version = 4` | 1 row → **5** |
| | `UPDATE … 'coupon','SUMMER24' … AND version = 1` | 1 row → **2** |
| g. event | `INSERT INTO ecotone_event_stream …`, aggregate columns null | `no` = 2 |
| h. index | `coupon / SUMMER24 / ecotone_event_stream / 2 / 2`. **No `aggregate_Wallet` row** — the handler is not a `Wallet` save, so the wallet tag is a condition tag here, exactly as it is today | |
| i. COMMIT | | |

**③ A competing wallet save, racing step ②.** It captures `aggregate_Wallet:w-1` = 4 at load and reaches its guarded
`UPDATE … WHERE version = 4`. If it arrives after step f it blocks on the row lock, then finds 5 → **0 rows** →
`DecisionModelConcurrencyException` rendered as *"Wallet w-1 changed since it was loaded"*; nothing written, no `no`
burned, retry. If it commits first, step f fails instead. Exactly one of the two decisions is taken on current
state — and this is **verbatim the shipped behaviour**: the index rows played no part in it.

### 5.3 What the guard sees, restated

- The aggregate counter is bumped **once per append**, by the same sorted guarded pass, whether or not index rows
  are written. `AppendedTags` keys `$involved` by `TagKey`, so the tag arriving both as a carried tag and as the
  condition's aggregate tag collapses to one entry and one `UPDATE`. There is **no double bump** and no new
  statement.
- `tag_sequence` is copied, never compared or incremented (§4.2). It is an order stamp. Adding rows that carry it
  cannot change any conflict decision.
- The **unique index on `(aggregate_type, aggregate_id, aggregate_version)` remains** and remains the aggregate's
  own guard: two concurrent saves of one aggregate still collide there first on `READ COMMITTED`, as a plain
  `ConcurrencyException` with the database's message; on `REPEATABLE READ` the counter catches it and names the
  aggregate (D4, kept literally).
- The `ecotone_tagged_events` primary key `(tag_name, tag_value, stream_name, event_no)` remains, and it is what
  makes the backfill idempotent (§3.2). A duplicate index row for one aggregate event is impossible.
- Ordering across branches is per branch, by that branch's primary tag sequence — so a wallet branch and a coupon
  branch, whose counters are unrelated, never interleave by accident.
- One boundary still lives on one connection (§4.5a). An aggregate on another connection has its index rows in
  *that* connection's tag tables, and the existing cross-connection guards apply unchanged.

---

## Part 6 — Parity, gating, licence, docs, tests

### 6.1 In-memory parity is free

The write-side change is entirely inside `TagResolver::resolveAppend()`, which is core and shared:
`EnterpriseInMemoryTagCollaborator::appendEventsWithTagCondition()` already calls it and already passes
`sequencedAfterBump()` to `InMemoryTagIndex::record()`. The read-side change is in `TagResolver::tagsCarriedBy()` and
`tagsOfCriteria()`, likewise shared; `InMemoryTagIndex::eventsMatching()` needs the same "skip non-reading branches"
line the Dbal reader gets. **`InMemoryTagIndex` and `InMemoryTagVersionRegister` are otherwise unchanged.**

Two in-memory specifics worth checking during implementation:

- `EcotoneLite::bootstrapFlowTesting()` routes event-sourced aggregates through `EventStoreEventSourcedRepository`
  over `InMemoryEventStore`, and `FlowTestSupport::withEventsFor()` seeds history through the save flow, so seeded
  events get index rows exactly as real saves do. **`withEvents()` writes to the default stream with no aggregate
  metadata**, so it produces no aggregate index rows — which is correct, and which is the seam a test uses to
  simulate a missing backfill.
- There is **nothing to backfill in memory**, mirroring the table in §4.4 of the main spec: in-memory events are
  never written without their tags. The backfill is Dbal-only, and `AggregateHistoryCompleteness` is the only part
  of §3 that in-memory tests can exercise (via `withEvents()`).

### 6.2 Gating and licence — nothing new

`DynamicConsistencyBoundaryConfiguration` remains the single switch and gains one `with*` method. The licence split
is unchanged: the aggregate counter tag is a tag, so it is the Enterprise half of `AppendCondition` (§4.9 of the main
spec), and `DynamicConsistencyBoundaryConfiguration` is `licence Enterprise`. `EventCriteria` is already Enterprise.

**DCB off, or the aggregate not on the list: byte-for-byte unchanged.** `TagResolver` is only reached through the
Enterprise collaborators; with DCB off the open-core append is today's single `INSERT`. With DCB on and `Wallet` off
the list, `resolveAppend()` takes the branch it takes today and writes no index row. `EventCriteria::aggregate()`
stays capture-only in every configuration, so the shipped aggregate-only boundary behaves identically whether or not
anything is indexed. This is the invariant the test plan pins first.

### 6.3 Docs

- **`upgrade-2.0.md`**, in the DCB section, after "Aggregates are inside the boundary": a sub-bullet
  *"Making an aggregate loadable by `EventCriteria`"* — the `with*` call, that it writes one index row per event, that
  it requires the backfill and the step order of §3.5, that `EventCriteria::aggregate()` stays capture-only while
  `EventCriteria::aggregateEvents()` reads, and that the aggregate's own load and snapshots are unchanged.
- **Main spec §4.11**: a paragraph recording that the counter-only rule is the baseline and full tag is the opt-in
  extension, with a pointer here; §4.10's "Decision-model snapshots" row gains the note that per-tag snapshots are
  what would let an aggregate load *by* criteria (§4.3 here).
- **`docs.ecotone.tech` DCB page**: the aggregate-scoped decision model example from §1.4(b), and the split-stream
  migration case from §1.3, which is the most likely reason a reader turns this on.
- **Fetched-aggregates design**: OQ 5 ("should the identity tag also be readable, later?") is answered by this
  document; its §6.3 follow-up list gets the cross-reference.

### 6.4 Test plan — black box, four engines plus in-memory

Every test through `EcotoneLite` and userland APIs only; no reflection, no direct SQL against Ecotone tables.
Coverage is asserted through `EventStore::loadByCriteria()`, handler outcomes and exceptions.

*Flow tests (`packages/Ecotone/tests/Modelling/DecisionModel/`, `InMemoryEventStore`)*

1. **The pin test, first.** With `Wallet` **not** on the list, `loadByCriteria(EventCriteria::aggregateEvents(...))`
   returns no events and `EventCriteria::aggregate(...)` still captures — i.e. everything on `main` behaves as on
   `main`. Repeated with DCB disabled.
2. `EventCriteria::aggregate()` on a **listed** aggregate returns no events (capture-only is a property of the
   criterion, not of the configuration) while `aggregateEvents()` on the same aggregate returns them all.
3. A listed aggregate's saves are readable by `aggregateEvents()` in aggregate-version order, including a command
   that records several events in one save (they share a `tag_sequence`, and `event_no` orders them).
4. `#[DecisionModel(aggregate: Wallet::class)]` folds the aggregate's events with **no `#[EventTag]` anywhere** and
   decides correctly; a competing `Wallet` save injected through a service during the handler makes the append fail
   with `DecisionModelConcurrencyException` — the existing `DecisionModelRetryTest` pattern.
5. One handler injecting an aggregate-scoped model **and** a tag-scoped model: both folded from one load, both in
   the handler's condition, a competing write to either tag fails the append.
6. A `#[DecisionBoundary]` returning `aggregateEvents(...)->or(tag(...))`: both branches guarded; each branch's
   events ordered by its own tag's sequence.
7. `AggregateHistoryCompleteness`: history seeded with `withEvents()` so the index has a hole → the fold throws,
   naming the aggregate, the first missing version and the backfill command.
8. Bootstrap guards: a state-stored aggregate on the list; a saga on the list; a non-aggregate class on the list;
   `#[DecisionModel(aggregate: X::class)]` where `X` is not on the list. Each names the class and the fix.
9. A `#[Fetch]`-ed aggregate in a decision-model handler behaves exactly as shipped whether or not it is listed —
   same instance, same counter leaf, no extra events loaded. (The non-regression test for §1.5.)

*Dbal integration (`packages/PdoEventSourcing/tests/Integration/Tagging/`, PostgreSQL / MySQL / MariaDB / SQLite)*

10. The §5.2 walkthrough end to end, asserted through `EventStore` and the handler's outcome.
11. **Split history**: the same aggregate's events in a legacy stream and in `ecotone_event_stream`, both
    backfilled; `aggregateEvents()` returns one merged, correctly ordered history, which `loadAggregateEvents()`
    cannot. (The capability test for §1.3.)
12. `backfill-aggregate-tags` over a stream with existing history: coverage complete afterwards, `--dry-run` writes
    nothing, a second run writes nothing new (idempotence), `--from-no` resumes, `--aggregate=` scopes.
13. The backfill raises the counter to `MAX(aggregate_version)`, and the first live save afterwards is readable in
    order after the historical events (the §3.3 table, asserted as the order `loadByCriteria()` returns).
14. A save committing during the backfill: one of the two fails with `DecisionModelConcurrencyException` and the
    retry succeeds; the resulting history is complete and ordered either way. Two connections, the
    `DbalTaggedContentionTest` / `DeduplicationModuleTest.php:141` pattern.
15. `verify-schema` reports a shortfall before the backfill and completeness after, and prints the exact command.
16. `EventStore::delete($stream)` removes the aggregate's index rows with the rest; a subsequent fold raises the
    completeness error rather than deciding on nothing.
17. Multi-tenant: `--header "tenant:a"` backfills tenant `a` only; no header fails with the existing tenant-context
    error.
18. Snapshots: a listed aggregate with a configured snapshot still loads through its snapshot in its own command
    handler (the §4.2 recommendation, pinned).

---

## Part 7 — Open questions, each with a recommended answer

**OQ 1 — `EventCriteria::aggregate()` capture-only, or reading once the aggregate is listed?**
Making the shipped factory read would need no new name, and "a criterion branch that actually returns events" is how
the question was phrased. It would also turn every aggregate-only `#[DecisionBoundary]` into a full-history scan the
moment an unrelated option is enabled, silently and proportionally to history length.
*Recommended:* keep `aggregate()` capture-only, add `aggregateEvents()`. Two factories, one extra `readsEvents()`
predicate on the branch, and the shipped path is provably unchanged (test 9).

**OQ 2 — opt-in on the extension object, an attribute on the aggregate, or always on?**
*Recommended:* `DynamicConsistencyBoundaryConfiguration::withAggregatesLoadableByCriteria([...])`. Always-on doubles
the index of every aggregate-heavy application and converts the boundary's "no backfill" promise into a mandatory
full-scan migration. A class attribute hides a storage-and-migration decision in a place a merge can flip without an
operator noticing. The extension object is where DCB is already configured, it is greppable, and it scopes the
backfill per class. (Note this answers the mirror of OQ 2 in the fetched-aggregates design in the opposite
direction, deliberately: boundary *membership* should be derived, storage *format* should be declared.)

**OQ 3 — how does a decision model name an aggregate?**
`#[DecisionModel(tags: ['aggregate_Wallet'])]` works today with no framework change, and is stringly typed, couples
userland to the naming scheme, and cannot be checked against `#[AggregateType]`.
*Recommended:* `#[DecisionModel(aggregate: Wallet::class)]`. The tag name is derived from the class's
`#[AggregateType]`; the value is resolved from the message exactly as every other model's is; and handled-event
validation becomes stronger and clearer than the tag-name rule — every `#[EventSourcingHandler]`'s event class must
be one the aggregate itself records, traced from the aggregate's own `#[EventSourcingHandler]`s, which is the trace
§4.5a already performs. A model may declare `aggregate:` **or** `tags:`, not both: ANDing an aggregate tag with a
user tag would require every event to carry both, which no event does.

**OQ 4 — `tag_sequence` for backfilled aggregate events.**
*Recommended:* `aggregate_version`, with the counter raised to `MAX(aggregate_version)` before the index rows are
written. It is the only candidate that is derivable in SQL, exact, and continuous with the live rule (§3.3). The
side effect — the counter's absolute value jumps from "saves since DCB was enabled" to "at least the event count" —
is harmless, because only movement is ever read, and raising can only cause a spurious conflict.

**OQ 5 — should the completeness assertion exist, given that a backfill-coverage guard was declined?**
The maintainer declined a runtime coverage guard for `#[EventTag]` on 2026-09-23, and rightly: there is nothing to
compare a tag's coverage against, so any guard would have been a tracking table. An aggregate is different — its
history is `1..n` by construction and the versions are already in the events being folded.
*Recommended:* accept it. It costs no query and no state, it is the difference between a silently wrong decision and
a named error during exactly the window §3.5 cannot fully close, and it is what makes the opt-in safe enough to
recommend at all. If it must be optional, it is a `with*` on the same extension object — but I would not ship it
off by default.

**OQ 6 — should a `#[Fetch]`-ed aggregate fold from the batch instead of `loadAggregateEvents()`?**
This is the literal reading of "injectable and foldable like a decision model", and it buys one round trip plus a
closed spurious-retry window.
*Recommended:* not now. It costs the snapshot and `fromVersion` (§4.1), makes the aggregate's own correctness depend
on index completeness, and turns a trivially reversible option into one that cannot be rolled back while a handler
depends on it. Revisit when per-tag snapshots ship (§4.3) — at that point it is a configuration flag, not a new
mechanism. An aggregate-scoped `#[DecisionModel]` already gives a user who wants a folded read exactly that, with
less state and without touching the aggregate.

**OQ 7 — the two read-path inefficiencies (§4.4): in scope, or separate?**
`ofTypes()` not narrowing the SQL read, and an unbounded `IN (…)` list, both predate this work and both get worse
when a branch routinely matches thousands of rows.
*Recommended:* separate work items, sequenced **before** full tag ships to users, because an aggregate-scoped model
that folds 2 of 12 event types is the first workload that makes the first one visible. If the narrowing lands,
`AggregateHistoryCompleteness` must read versions from the flags rather than from the folded events (§4.4).

**OQ 8 — the `tag_value` budget for composite identifiers.**
`AggregateIdString::from()` renders a composite identifier as a JSON map, which can exceed the 255-character
`tag_value` column. The counter path has this exposure today and it fails at insert time with a database error
rather than a named exception; full tag lands the same value in `ecotone_tagged_events` as well, doubling the
surface. `TagResolver` does not run counter values through `EventTagValueNormalizer`, which every user tag value
goes through.
*Recommended:* normalize and validate the counter value on the same path as user tag values, so an over-long or
invalid identifier fails naming the aggregate and its identifiers. Small, independent of this proposal, and worth
doing whether or not full tag is approved.

---

## Part 8 — Collaborators that change, names only

Core (`packages/Ecotone`):

| Class | Change |
|---|---|
| `DynamicConsistencyBoundaryConfiguration` | `withAggregatesLoadableByCriteria(array $aggregateClasses)` |
| `DynamicConsistencyBoundary` (Config) | exposes the resolved set of aggregate types loadable by criteria |
| `AggregateCounterTags` | answers `isLoadableByCriteria(string $aggregateType)`; the set is built beside the existing aggregate-type scan |
| `AggregateCounterTagGuard` | the four new bootstrap guards of §2.2 |
| `TagResolver::resolveAppend()` | when the condition's aggregate type is loadable by criteria, the counter tag joins every event's carried tags |
| `TagResolver::tagsCarriedBy()` | derives the counter tag from an `Event`'s `_aggregate_type` / `_aggregate_id` for the read side |
| `TagResolver::tagsOfCriteria()` | splits into tags to capture (all branches) and tags to read (reading branches) |
| `EventCriteria` | `aggregateEvents()`; branches answer `readsEvents()` |
| `DecisionModelDefinitionBuilder` / `DecisionModelDefinition` | `#[DecisionModel(aggregate: X::class)]`: tag name from `#[AggregateType]`, handled events validated against the aggregate's own `#[EventSourcingHandler]`s |
| `AggregateHistoryCompleteness` *(new)* | the gapless-`_aggregate_version` assertion over an aggregate branch's events |

`PdoEventSourcing`:

| Class | Change |
|---|---|
| `DbalTaggedEventReader` | skips non-reading branches; passes only the read set to `flagsFor()` |
| `DbalAggregateTagBackfiller` *(new)* | the set-based `INSERT … SELECT` and the counter raise of §3.2 |
| `AggregateTagBackfillConsoleCommand` *(new)* | `ecotone:event-store:backfill-aggregate-tags` |
| `TagSchemaVerifier` / `verify-schema` | the per-aggregate coverage check of §3.6, reported as a data check |
| `EventStreamSchema` implementations | the aggregate-metadata expressions already exist; the backfill reuses them |

In-memory: `InMemoryTagIndex::eventsMatching()` skips non-reading branches. Nothing else.

**No schema change**: same two tables, same columns, same keys, same `event_tags` setup feature.

---

## Part 9 — Summary of recommendations

| # | Recommendation |
|---|---|
| 1 | The aggregate's counter tag also becomes a carried tag of its events — one index row per event, `tag_sequence` = the bumped counter. Confined to `TagResolver`; no schema, appender, index or store change |
| 2 | **Opt in per aggregate class**, on `DynamicConsistencyBoundaryConfiguration::withAggregatesLoadableByCriteria()`. Always-on doubles the index and imposes a mandatory backfill on every DCB application |
| 3 | `EventCriteria::aggregate()` stays capture-only; `EventCriteria::aggregateEvents()` reads. The shipped aggregate-only boundary is provably unchanged |
| 4 | `#[DecisionModel(aggregate: Wallet::class)]` is the injectable-and-foldable surface — no `#[EventTag]` on the aggregate's events, no stringly-typed tag name |
| 5 | **The aggregate keeps its own load path**, snapshots included. Full tag is a second, read-only path for everyone else. `#[Fetch]` is unchanged |
| 6 | `ecotone:event-store:backfill-aggregate-tags`: set-based `INSERT … SELECT`, no deserialisation, `tag_sequence = aggregate_version`, counter raised to `MAX(aggregate_version)` first, idempotent on the primary key, online-safe at the cost of some retries |
| 7 | `AggregateHistoryCompleteness` — gapless `_aggregate_version` or a named error. Free, exact, and what closes the rolling-deploy window |
| 8 | `verify-schema` gains a per-aggregate coverage check as the deploy gate |
| 9 | The read-path inefficiencies of §4.4 are separate work items, sequenced before this ships |
| 10 | Licence, gating and in-memory parity are unchanged; DCB-off and not-listed are byte-for-byte today's behaviour |
