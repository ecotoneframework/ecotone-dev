# Reading the aggregate decision model in the same statement as the event tags

*Research. Revision 1, 2026-09-29, base `dgafka/ecotone-2-0-dcb-design` @ `8839179a`. **Revision 2, 2026-09-29**,
base `dgafka/research-dcb-single-read-snapshot` @ `55824913`. Proposal for approval; nothing is implemented.*

**The maintainer's question, verbatim (revision 1):** *"Can we ensure that aggregate decision model is loaded via
same sql as event tags, just to ensure that we snapshot at the same time. Let's research: is it possible and how
would it look like."*

**The maintainer's push-back, verbatim (revision 2):** *"What about folding fetch? What if we would introduce
snapshots for decision models too — meaning the loading flow would allow for snapshotting a decision model up to a
given point, and we would fetch only the rest and apply. This way fetching an aggregate or a decision model would
collapse into the same flow. Push back to the research session to investigate that approach."*

**How to read this document.** Parts 0–8 are revision 1 and stand unchanged except where a *(revision 2)* note says
otherwise. Parts 9–13 are revision 2: what a decision-model snapshot is (9), the one load flow (10), correctness
under snapshots (11), the measured cost (12), and the revised proposal, including the new answer to OQ5 (13).

---

## Part 0 — The answer in one paragraph

Yes, it is possible, and Part 3 gives the SQL for all four engines. But the premise the question protects is already
safe, and on three of the four engines it is already literally true. **MySQL, MariaDB and SQLite already snapshot at
the same time**: InnoDB's REPEATABLE READ pins the transaction's snapshot at its *first consistent read*, which is
the version capture, and every later read in the handler's load — the tag index, the per-stream events, each
`loadAggregateEvents()` — is served from that one snapshot; SQLite's read transaction does the same. **PostgreSQL is
the only engine where the statements can diverge**, because READ COMMITTED takes a fresh snapshot per statement — and
there the divergence is always in the safe direction: later reads can only be *newer* than the capture, and any
commit that made them newer also moved a captured counter, so the guarded `UPDATE` fails and the handler retries. A
diverging read can therefore produce a spurious retry, never a wrong decision. All of this is measured, not argued:
Part 1 has the statement logs from all four engines and Part 2 the two-connection proof. The real finding is a
different one. Today's load costs **seven read statements**, and the two most expensive of them on PostgreSQL and
MySQL are not reads of events at all — they are `information_schema` existence probes, one per
`loadAggregateEvents()` call, at **0.91 ms on PostgreSQL and 0.51 ms on MySQL** against a stream table that the very
next statement reads anyway. Collapsing capture, tag index, per-stream events and the aggregate branches into one
`UNION ALL` statement with a capture CTE (Part 3, option C) takes the read phase from **3.70 ms to 1.28 ms on
PostgreSQL** and **2.53 ms to 0.87 ms on MySQL** under eight-way contention. It does not change the conflict rate at
all once a handler does two milliseconds of work (87.1% vs 86.2%, measured) — because the conflict rate is set by
the number of contenders, not by the shape of the read. **Recommendation: take option C, but take it for the round
trips, and say so honestly in the docs; and delete the existence probes first, because that is two thirds of the win
for a tenth of the work.**

### Part 0 (revision 2) — the answer to the push-back, in one paragraph

**Yes: snapshot the decision model, and the two loads become one shape — but they stay two implementations, and the
reason is the repository, not the read.** A decision-model snapshot needs no new position: for a tag scope the
position already exists and is already stored on every index row — `tag_sequence` *is* the tag's counter version at
the instant that event was appended, every event of one append carries the same value, and the counter and the index
rows are written in one transaction, so `tag_sequence > :covered` is an exact cut at an append boundary and can never
land mid-append (Part 9). For an aggregate scope the position is `aggregate_version` and `fromVersion` has been a SQL
argument all along. Both push down into the option C statement, and on PostgreSQL the snapshot's covered position
reaches the index as an `InitPlan` inside the *same statement*: measured, `Index Cond: (tag_name = … AND tag_value =
… AND tag_sequence > COALESCE($1, 0))` — one statement, capture, snapshot, tail and aggregate branch, no round trip
between them (Part 10.2). The payoff is the one revision 1 could not offer, because it is the only part of the read
that grows with history: for a 1 000-event tag scope snapshotted at 900, the combined read goes from **6.7 ms to
1.5 ms on PostgreSQL, 8.6 ms to 1.5 ms on MySQL and 9.2 ms to 1.2 ms on MariaDB**, and 2 002 rows to 103 — against
option C's flat ~2.4 ms saving, which does not grow at all. **Option C is the constant-factor win; snapshots are the
asymptotic one** (Part 12). Correctness costs nothing: the tail is exact by *position*, so a stale snapshot only
makes the tail longer, and a snapshot that is somehow *ahead* of the events the reader can see is not a wrong
decision but a guaranteed conflict, because the tag whose snapshot ran ahead is in the captured set and its guarded
`UPDATE` cannot match (Part 11.4). **Folding `#[Fetch]` is where the answer is no, and revision 1's reason was the
wrong one.** It is not that repository loading would be reimplemented — with snapshots on both sides the *shape* is
identical (scope → snapshot → tail-by-position → fold). It is that `AllAggregateRepository::findBy()` is a
**dispatch point over user-supplied repositories**: `EventSourcedRepository` is a public interface, state-stored
aggregates have no events at all, and an aggregate's snapshot already lives in the document store keyed by the
user's own `#[Version]` serialization. Folding the read would mean the tag reader deciding, per aggregate class,
whether it is allowed to bypass the user's repository — two load paths wearing one name. What *is* worth doing is a
**hand-off**, not a replacement: the batch loader already resolves the fetched aggregates' identifiers before the
converter runs, so it can fold them in the same statement and leave the instances in `DecisionModelLoadedState` for
`FetchAggregateConverter` to pick up when the aggregate is event-sourced on Ecotone's own repository, falling back
to `AllAggregateRepository` otherwise. The converter stays. **Recommended order: probes deletion → option C →
aggregate-scope snapshots → tag-scope snapshots → the `#[Fetch]` hand-off, gated** (Part 13.6).

---

## Part 1 — Today's statements, precisely

### 1.1 The handler under the microscope

The brief's shape: a handler injecting one tag-scoped model, one aggregate-backed model, one `#[Fetch]`-ed aggregate
and a `#[DecisionBoundary]`.

```php
final class Payouts
{
    #[CommandHandler]
    public function payOut(
        RequestPayout $command,
        WalletBalance $wallet,                          // #[DecisionModel(aggregate: Wallet::class)]
        PayoutsToday $today,                            // #[DecisionModel(tags: ['wallet'])]
        #[Fetch('payload.customerId')] Customer $customer,
    ): array {
        return [new PayoutRequested($command->walletId, $command->amount)];
    }

    #[DecisionBoundary]
    public static function boundary(RequestPayout $command): EventCriteria
    {
        return EventCriteria::tag('region', $command->region);
    }
}
```

Four capture pairs reach `ecotone_tag_versions`: `wallet:w-1` (the tag-scoped model), `aggregate_Wallet:w-1` (the
aggregate-backed model's counter), `aggregate_Customer:c-1` (the fetched aggregate's counter) and `region:eu` (the
boundary).

### 1.2 The statement log, measured

Recorded with a `Doctrine\DBAL\Connection` subclass logging every `executeQuery` / `executeStatement` / transaction
boundary, wired in through `wrapperClass` — a throwaway harness, run and deleted. **The order and the shape below are
identical on PostgreSQL 16, MySQL 8.0, MariaDB 11.4 and SQLite 3.40**; only the identifier quoting and the way the
aggregate columns are addressed differ.

| # | Who issues it | Statement | Notes |
|---|---|---|---|
| — | `DbalTransactionInterceptor` | `BEGIN` | the handler's transaction |
| 1 | `DbalTagVersionRegister::currentVersions` | `SELECT tag_name, tag_value, version FROM ecotone_tag_versions WHERE (tag_name = ? AND tag_value = ?) OR …` | one `OR`-pair per captured tag — **four** here |
| 2 | `DbalTagIndex::flagsFor` | `SELECT stream_name, event_no, MAX(CASE WHEN … THEN tag_sequence END) AS sequence_n, MAX(CASE WHEN … THEN 1 ELSE 0 END) AS has_n … FROM ecotone_tagged_events WHERE … GROUP BY stream_name, event_no` | same four pairs; no event-type filter |
| 3 | `DbalEventStore::loadEventsByNumbers`, via `DbalTagIndex::eventsReferencedBy` | `SELECT no, event_name, payload, metadata FROM <stream> WHERE no IN (?, ?, …)` | **one per distinct `stream_name` in the flags**; no event-type filter |
| 4 | `EventStreamSchema::tableExists` | `SELECT COUNT(*) FROM information_schema.tables WHERE …` (PG/MySQL/MariaDB) or `SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?` | inside `loadAggregateEvents()`, **before every call** |
| 5 | `DbalEventStore::loadAggregateEvents` → `selectEvents` | `SELECT no, event_name, payload, metadata FROM <stream> WHERE <aggregate_type> = ? AND <aggregate_id> = ? AND <aggregate_version> >= ? AND event_name IN (?, ?) AND no >= ? ORDER BY no ASC LIMIT 1000` | the aggregate-backed model; `event_name` narrowing **is** pushed into SQL; loops in `loadBatchSize` (default 1000) pages |
| 6 | `EventStreamSchema::tableExists` | as #4 | for the `#[Fetch]`-ed aggregate |
| 7 | `DbalEventStore::loadAggregateEvents` → `selectEvents` | as #5 **without** the `event_name IN (…)` clause | the whole aggregate is folded, so no narrowing |
| 8 | `DbalTagVersionRegister::bumpGuarded` | `UPDATE ecotone_tag_versions SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = ?` — or, at captured 0, `INSERT … ON CONFLICT DO NOTHING` (PG/SQLite) / plain `INSERT` (MySQL/MariaDB) | one per involved tag, in sorted order |
| 9 | `DbalEventStore::insertEventRows` | `INSERT INTO <stream> (event_id, event_name, payload, metadata, created_at) VALUES …` | |
| 10 | `DbalTagIndex::insertRows` | `INSERT INTO ecotone_tagged_events … SELECT … FROM <stream> s WHERE s.event_id = ?` | |
| — | `DbalTransactionInterceptor` | `COMMIT` | |

**Read-statement count:** `1 + 1 + S + 2A`, where `S` is the number of distinct streams holding matching index rows
and `A` the number of distinct aggregate instances in the boundary (aggregate-backed models plus `#[Fetch]`-ed
aggregates; two models on one instance share one read). For the handler above: **seven**. An aggregate with more than
`loadBatchSize` events adds a statement per page. Snapshots add a document-store read per fetched aggregate.

**Where each runs.** Statements 1–3 are `DbalTaggedEventReader::loadByCriteria`, reached from
`EventStore::loadByCriteria` — one call, for the OR of every model's criteria, the boundary's criteria, the fetched
aggregates' capture leaves and the aggregate-backed models' capture leaves (`DecisionModelBatchLoader::load`).
Statements 4–5 are `DecisionModelBatchLoader::readEventsOfEachAggregateInstance`, still inside `load()`. Statements
6–7 are `FetchAggregateConverter::getArgumentFrom` — the batch loader is registered as a **before** interceptor
(`DecisionModelModule`, `Precedence::SYSTEM_PRECEDENCE_AFTER`), so it has already returned when the handler's
argument converters run. **This matters for Part 2 and the answer is the good one: the fetched aggregate's counter is
captured in statement 1, long before statement 7 reads the aggregate.**

### 1.3 How the aggregate columns are addressed, per engine

`EventStreamSchema::metadataFieldExpression()`:

| Engine | `aggregate_type` | Index used | |
|---|---|---|---|
| PostgreSQL | `metadata->>'_aggregate_type'` | `ix_…_aggregate` on `((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), no)` — a functional index on `JSONB` | |
| MySQL / MariaDB | `` `aggregate_type` `` — a `STORED` generated column | `ix_query_aggregate (aggregate_type, aggregate_id, no)` | |
| SQLite | `json_extract(metadata, '$._aggregate_type')` | expression index on the same three | |

`aggregate_version` is compared as an integer: `CAST(metadata->>'…' AS BIGINT)` on PostgreSQL, the generated
`INT UNSIGNED` column on MySQL/MariaDB, bare `json_extract` on SQLite. The tag tables are plain and identical in
shape on all four: `ecotone_tagged_events` keyed `(tag_name, tag_value, stream_name, event_no)` and
`ecotone_tag_versions` keyed `(tag_name, tag_value)` (PostgreSQL adds `fillfactor = 70` for the update-in-place
counter).

### 1.4 Isolation, and which statements can see a commit the earlier ones did not

Ecotone sets no isolation level anywhere — `grep` for `setTransactionIsolation` across `packages/Dbal` and
`packages/PdoEventSourcing` is empty — so the engine default applies. Measured on the compose stack:

| Engine | Isolation | When the snapshot is taken | Can statement *n* see a commit statement *n−1* did not? |
|---|---|---|---|
| PostgreSQL 16.1 | `read committed` | **per statement** | **Yes** — every one of statements 1–7 has its own snapshot |
| MySQL 8.0.46 (InnoDB) | `REPEATABLE-READ` | at the transaction's **first consistent read**, which is statement 1 | **No** — statements 2–7 are served from the snapshot statement 1 took |
| MariaDB 11.4.12 (InnoDB) | `REPEATABLE-READ` | same | **No** |
| SQLite 3.40.1 | deferred transaction | at the transaction's first read | **No** |

**Measured, two connections.** Connection A opens a transaction and reads a counter; connection B then commits a
counter bump *and* a new event; A re-reads:

```
pgsql    iso=read committed   captured=1  eventsSeenAfterCommit=2  versionReReadInTx=2  guardedUpdateRows=0
mysql    iso=REPEATABLE-READ  captured=1  eventsSeenAfterCommit=1  versionReReadInTx=1  guardedUpdateRows=0
mariadb  iso=REPEATABLE-READ  captured=1  eventsSeenAfterCommit=1  versionReReadInTx=1  guardedUpdateRows=0
```

PostgreSQL sees B's event and B's new counter value; the InnoDB pair see neither. **All three raise the conflict
anyway** — `guardedUpdateRows=0` — because the guarded `UPDATE` is a *current* read, not a snapshot read. That is the
whole design working: the snapshot governs what the handler decided on, the current read governs whether it is
allowed to write.

SQLite, the same experiment against a file database:

```
sqlite/delete  captured=1 writer=blocked: database is locked  eventsSeenAfterCommit=1 guardedUpdateRows=1
sqlite/wal     captured=1 writer=committed                    eventsSeenAfterCommit=1 guardedUpdateRows=SQLITE_BUSY
```

In rollback-journal mode the competing writer cannot commit at all while A holds its read transaction; in WAL mode it
commits, A's reads stay on A's snapshot, and A's write then fails busy — which `TagConcurrencyGuard` already maps.
Either way, one snapshot.

**One caveat on InnoDB, stated for completeness.** The snapshot is pinned by the transaction's first consistent read,
not by the capture specifically. If something in the same transaction read before the batch loader ran —
deduplication, an outer handler, a `#[Before]` interceptor — the snapshot is pinned *earlier* than the capture, so
the capture itself reads a possibly-stale counter. That is still the safe direction: a stale captured version makes
the guarded `UPDATE` fail against the current row. Nothing can make the capture *newer* than the events.

---

## Part 2 — Does it matter for correctness?

### 2.1 The argument, with the counters

Three facts, each checkable in the code:

1. **The capture is the first statement of the load.** `DbalTaggedEventReader::loadByCriteria` calls
   `$this->versions->capture(...)` before `$this->index->flagsFor(...)`, and `DecisionModelBatchLoader::load` calls
   `loadEventsFor(...)` — which is `EventStore::loadByCriteria` — before `foldAggregateBackedInstances(...)`, which
   is what issues `loadAggregateEvents()`. Nothing reads before the capture.
2. **Every tag of every branch is captured.** `TagResolver::tagsOfCriteria` walks `criteria->branches()` and collects
   every `(name, value)` pair, and the batch loader ORs *all four sources* into that one criteria: models, boundary,
   fetched-aggregate capture leaves, aggregate-backed capture leaves. The captured set therefore covers exactly the
   scope the handler read from.
3. **Any commit that touches a captured counter fails the append.** `AppendedTags` unions the condition's tags with
   the tags of the new events; `DbalTagVersionRegister::bumpGuarded` runs
   `UPDATE … SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = :captured` per tag, and a
   zero row count is `DecisionModelConcurrencyException`. At captured 0 it is `INSERT … ON CONFLICT DO NOTHING`
   (plain `INSERT` on MySQL/MariaDB), which conflicts exactly when someone else created the row.

Put together: a read taken at a later instant than the capture can only include events that were committed after the
capture; every such commit bumped a counter that is in the capture set; therefore the guarded `UPDATE` finds a moved
version and the whole transaction is rolled back. **A diverging read can produce a spurious retry. It cannot produce
a missed conflict.** The reverse order — read first, capture second — would pair a newer version with older events
and would miss it, which is why §4.5 of the design makes capture-before-read load-bearing.

### 2.2 The walkthrough, with rows

`Wallet w-1` has 6 events and its counter `aggregate_Wallet:w-1` is at 6; `wallet:w-1` is at 7;
`aggregate_Customer:c-1` is at 3; `region:eu` is filter-free and at 4. Anna requests a 60 payout. Ben's
`CreditWallet w-1` commits somewhere in the middle.

**PostgreSQL — READ COMMITTED, every statement its own snapshot**

| Anna's statement | Snapshot | Reads | |
|---|---|---|---|
| 1 capture | *t₀* | `aggregate_Wallet:w-1 = 6`, `wallet:w-1 = 7`, `aggregate_Customer:c-1 = 3`, `region:eu = 4` | |
| — | | *Ben commits: `aggregate_Wallet:w-1 → 7`, `WalletCredited` #7 into `_wallet`* | |
| 2 index | *t₁* | unchanged — Ben's event carries no `#[EventTag]` | |
| 3 events by `no IN` | *t₂* | unchanged | |
| 5 `loadAggregateEvents` | *t₄* | **7 events — Anna folds a balance Ben already changed** | |
| 7 fetched `Customer` | *t₆* | unchanged | |
| 8 guarded bump | current read | `UPDATE … WHERE tag_name='aggregate_Wallet' AND tag_value='w-1' AND version = 6` → **0 rows** | |

Anna's decision was taken on a wallet state that is *newer* than her capture. She never gets to act on it: the guard
rejects her, nothing is written, the command retries and re-reads. **Spurious retry, safe direction.**

**MySQL / MariaDB — REPEATABLE READ, one snapshot pinned at statement 1**

| Anna's statement | Snapshot | Reads | |
|---|---|---|---|
| 1 capture | pinned here | the same four versions | |
| — | | *Ben commits the same two changes* | |
| 2–7 | the pinned snapshot | **6 events — Ben is invisible throughout** | |
| 8 guarded bump | current read | `… AND version = 6` → **0 rows** | |

Anna folded a coherent, if slightly old, wallet. The guard rejects her all the same. Same outcome, reached without
any divergence to reason about — which is the property the maintainer's question asks for, already in place.

### 2.3 The cases where the answer is not simply "spurious retry"

I looked for every way the claim could fail. Four candidates; three are closed, one is a real (pre-existing) gap.

**(a) A tag that is folded but not captured — cannot happen.** The fold is
`TagResolver::eventsMatching($events, $criteria)` over exactly the events `loadByCriteria` returned for that same
`EventCriteria`, and the capture is `tagsOfCriteria` of that same object. One criteria object, both sides. Closed.

**(b) The `#[Fetch]`-ed aggregate, loaded by the converter after the batch — verified safe.** This was the case worth
checking, because the read genuinely happens outside `loadByCriteria`, several statements later, in
`FetchAggregateConverter::getArgumentFrom`. Its counter is nonetheless captured in statement 1:
`DecisionModelBatchLoader::load` folds `fetchedAggregateCriteriaByParameterName($message)` — one
`FetchedAggregateCounterCapture::resolveCriteria` per fetched parameter, each resolving the aggregate's identifiers
from the same `#[Fetch]` expression the converter will use and returning that instance's counter-tag leaf — into the
combined criteria *before* calling `loadEventsFor(...)`. Statement 1 therefore holds `aggregate_Customer:c-1` before statement 7 reads the customer.
Capture-before-read holds across the interceptor/converter boundary. Closed, and the statement log in Part 1.2
confirms the ordering on all four engines.

**(c) Events read by the aggregate path whose counter was captured — safe by construction.** Every event-sourced
save bumps its own instance counter: `AppendCondition::forAggregate()`'s aggregate part implies the counter tag,
`TagResolver::resolveAppend` adds it to the involved tags, and it is bumped guarded in the same sorted pass (§4.11).
So there is no way to append to `Wallet w-1` without moving `aggregate_Wallet:w-1`. The guard granularity is the
instance, not the event type — a save recording a type the model ignores still invalidates the decision. That is the
conservative direction and §2.5 of the aggregate-full-tag design already accepts it.

**(d) Filter-only tags on a `#[DecisionBoundary]` — a real gap, pre-existing, unrelated to this question.**
`TagResolver::tagsOfCriteria` captures *every* tag of the criteria, filter-only ones included, and they reach the
`AppendCondition`. But `EventsTags::counted()` excludes filter-only tags, so **no unconditional append ever bumps
them**. A competing writer committing an event whose only relevant tag is filter-only moves no counter, and the
guarded bump of that tag (captured at 0 → `INSERT … ON CONFLICT DO NOTHING`, or captured at *n* → `UPDATE … WHERE
version = n` against a row only conditional appends ever touch) does not conflict. The tag guards nothing.

For **models** this is already rejected at bootstrap —
`DecisionModelModule::assertNoModelScopedOnlyByFilterOnlyTags` says so in as many words: *"scoped only by filter-only
tag(s) …, which are never counted, so its handler's append would be guarded by nothing."* For **boundaries** there is
no equivalent check: a `#[DecisionBoundary]` returning `EventCriteria::tag('region', $region)` where `region` is
declared in `DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags()` compiles, runs, and guards nothing,
silently.

This is a pre-existing hole, not something the single-read question creates or fixes, and it is the only place I
found where "reads at different instants can only produce a spurious retry" is not the whole truth — there, reads at
different instants produce *different decisions with no conflict detection at all*. **Recommendation: extend the
existing guard to boundaries** (Part 7, OQ1). It is a small, self-contained unit and should not ride on this
proposal.

### 2.4 The verdict for Part 2

**A single statement changes nothing about correctness.** On MySQL, MariaDB and SQLite the reads are already one
snapshot and the change is literally a no-op for consistency. On PostgreSQL the reads can diverge, and the divergence
is provably benign. Whatever the case for one statement is, it is not a correctness case — and the document must say
so plainly rather than let "we snapshot at the same time" read as a bug fix.

---

## Part 3 — Is a single statement possible, and how would it look?

Four shapes, measured on a realistic dataset: one stream table with **330 000 events** — 3 000 `Wallet` instances of
100 events each, plus 30 000 tagged service events — `ecotone_tagged_events` with 30 000 index rows, the read tag
matching 10 of them and the read aggregate holding 100 events. Tables and indexes copied verbatim from the
`EventStreamSchema` and `TaggedEventSchema` implementations. Medians of 20 runs, each with a fresh `prepare()`, so
planning cost is included exactly as Doctrine pays it.

### 3.1 Option A — one SELECT per stream, `OR`-ing the index predicate and the aggregate predicate

```sql
SELECT no, event_name, payload, metadata,
       (SELECT MAX(tag_sequence) FROM ecotone_tagged_events t
         WHERE t.stream_name = ? AND t.event_no = s.no AND t.tag_name = ? AND t.tag_value = ?) AS tag_sequence,
       CASE WHEN <aggregate_id> = ? THEN 1 ELSE 0 END AS from_aggregate
FROM <stream> s
WHERE no IN (SELECT event_no FROM ecotone_tagged_events WHERE stream_name = ? AND tag_name = ? AND tag_value = ?)
   OR (<aggregate_type> = ? AND <aggregate_id> = ? AND event_name IN (?, ?))
ORDER BY no
```

**Disqualified, measured.** The `OR` spans two unrelated access paths, and every engine but SQLite gives up and scans:

| Engine | Median | Plan |
|---|---|---|
| PostgreSQL 16 | **229.18 ms** | `Index Scan using <stream>_pkey`, `Rows Removed by Filter: 329 890`, plus a correlated subplan per surviving row |
| MySQL 8.0 | **213.16 ms** | `type=index key=PRIMARY rows=326 858` — a full index scan |
| MariaDB 11.4 | **143.76 ms** | `type=index key=PRIMARY rows=326 543` |
| SQLite 3.40 | 0.17 ms | `MULTI-INDEX OR` — SQLite alone splits the `OR` into two index searches |

Two to three orders of magnitude worse than today's four statements (0.64–0.86 ms combined) on the three engines that
matter for production. The correlated subquery needed to recover `tag_sequence` makes it worse again. Nothing about
this shape is salvageable: it is what the maintainer's phrase "same SQL" most literally suggests, and it is the one
option that must not be built.

### 3.2 Option B — `UNION ALL` of the two reads

Each branch keeps its own access path, and a discriminator column routes the rows in PHP.

```sql
SELECT 'tag' AS branch, s.no, s.event_name, s.payload, s.metadata, f.sequence_0, f.has_0
FROM (SELECT stream_name, event_no,
             MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS sequence_0,
             MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END)     AS has_0
      FROM ecotone_tagged_events
      WHERE (tag_name = ? AND tag_value = ?) OR …
      GROUP BY stream_name, event_no) f
JOIN <stream> s ON s.no = f.event_no
WHERE f.stream_name = ?
UNION ALL
SELECT 'aggregate', s.no, s.event_name, s.payload, s.metadata, NULL, NULL
FROM <stream> s
WHERE <aggregate_type> = ? AND <aggregate_id> = ? AND event_name IN (?, ?)
```

Both branches stay index-driven on every engine. The `GROUP BY stream_name, event_no` sub-select is today's
`flagsFor` query moved inline — which is what keeps the "each event once, however many criteria it matches"
property that §4.5 warns a naive `UNION` would break; the branch is `flags ⋈ stream`, not `index ⋈ stream`.

### 3.3 Option C — option B with the capture folded in as a CTE

```sql
WITH captured AS (
    SELECT tag_name, tag_value, version FROM ecotone_tag_versions
    WHERE (tag_name = ? AND tag_value = ?) OR (tag_name = ? AND tag_value = ?) OR …
), flags AS (
    SELECT stream_name, event_no,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS sequence_0,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END)     AS has_0,
           …
    FROM ecotone_tagged_events
    WHERE (tag_name = ? AND tag_value = ?) OR …
    GROUP BY stream_name, event_no
)
SELECT 'version' AS branch, tag_name, tag_value, version,
       NULL AS no, NULL AS event_name, NULL AS payload, NULL AS metadata, NULL AS sequence_0, NULL AS has_0
FROM captured
UNION ALL
SELECT 'tag', NULL, NULL, NULL, s.no, s.event_name, s.payload, s.metadata, f.sequence_0, f.has_0
FROM flags f JOIN <stream> s ON s.no = f.event_no
WHERE f.stream_name = ?
UNION ALL
SELECT 'aggregate', NULL, NULL, NULL, s.no, s.event_name, s.payload, s.metadata, NULL, NULL
FROM <stream> s
WHERE <aggregate_type> = ? AND <aggregate_id> = ? AND event_name IN (?, ?)
```

Per engine, only the dialect differs:

- **PostgreSQL** — the `NULL` placeholders need casts (`NULL::bigint`, `NULL::varchar`, `NULL::json`, `NULL::jsonb`)
  because `UNION ALL` must unify column types and `json` has no default resolution. Identifiers double-quoted.
  Aggregate predicate on `metadata->>'_aggregate_type'` / `metadata->>'_aggregate_id'`.
- **MySQL 8.0 / MariaDB 10.2+** — CTEs supported; bare `NULL` is fine; identifiers backticked; aggregate predicate on
  the `aggregate_type` / `aggregate_id` generated columns.
- **SQLite 3.8.3+** — CTEs supported; bare `NULL`; identifiers double-quoted; aggregate predicate on
  `json_extract(metadata, '$._aggregate_type')` / `'$._aggregate_id'`.

**Does the capture-in-a-CTE give the same guarantee as capture-first?** Yes, on all four, and on PostgreSQL —
the only engine where the question has teeth — I verified it rather than assumed it. A single statement in READ
COMMITTED takes one snapshot at statement start and uses it for every branch, CTEs included. Demonstration: a
three-branch statement reading the counter, sleeping three seconds, then reading the counter again, while a second
connection commits a change one second in:

```
  first  -> version 100
  sleep  -> version 0
  second -> version 100          (the competing commit set it to 999)
```

Both reads of the same row, three seconds apart inside one statement, return the pre-commit value. On InnoDB and
SQLite the point is moot — the whole transaction is one snapshot already.

### 3.4 Option D — one statement across streams

**Not possible, and the reason is structural rather than dialectal.** Which stream tables hold matching events is not
known until the index has been read: `flagsFor` returns `stream_name` per row, and that is the only discovery
mechanism — nobody declares which streams a model reads (§4.5a), and legacy 1.x `_<sha1(AggregateType)>` tables exist
in the data without being in `StreamTableRegistry`. A single statement would have to `UNION ALL` over a set of tables
it cannot name in advance.

What is available: `StreamTableRegistry::declaredStreamNames()` knows the streams declared through `#[Stream]` on a
class or a handler method, and the aggregate-backed branches' stream tables are statically known (the aggregate's
`#[Stream]`). So three shapes are possible and one is honest:

- *Union over every declared stream* — correct only if no legacy table holds matching rows, and it reads tables the
  handler has nothing to do with. Rejected.
- *Two round trips: flags first, then one combined statement per discovered stream.* Loses the single-statement
  property for the flags, keeps it per stream.
- *One statement per stream table, each carrying its own copy of the `flags` CTE, plus the capture in the first one.*
  The flags CTE is evaluated once per stream statement — 10 index rows, an index range scan, negligible — and each
  statement filters `WHERE f.stream_name = ?`. The stream list still has to come from somewhere, which brings back
  the discovery read.

**What "same snapshot" can mean in the multi-stream case.** On InnoDB and SQLite: exactly what it means today — one
transaction snapshot covers every statement, however many streams. On PostgreSQL: per statement, so N streams means N
snapshots, and the guarantee degrades to *"capture and the first stream's reads share one snapshot; later streams may
be newer"* — which is precisely today's guarantee, and Part 2 shows it is enough. **The multi-stream case is where
the single-statement idea stops scaling, and the honest guarantee to write down is per stream table, not per
handler.** In practice `S = 1` for the overwhelming majority of handlers.

### 3.5 Measured: plan quality and cost

Same dataset, same machine, medians of 20 runs.

| | PostgreSQL 16 | MySQL 8.0 | MariaDB 11.4 | SQLite 3.40 |
|---|---|---|---|---|
| 1 capture | 0.07 ms | 0.05 ms | 0.10 ms | 0.02 ms |
| 2 index flags | 0.14 ms | 0.07 ms | 0.13 ms | 0.05 ms |
| 3 events by `no IN (…)` | 0.10 ms | 0.07 ms | 0.13 ms | 0.03 ms |
| 4/6 **`tableExists` probe** | **0.91 ms** | **0.51 ms** | 0.10 ms | 0.02 ms |
| 5/7 `loadAggregateEvents` | 0.33 ms | 0.47 ms | 0.50 ms | 0.20 ms |
| **today, one aggregate instance (1+2+3+probe+load)** | ~1.55 ms | ~1.17 ms | ~0.96 ms | ~0.32 ms |
| **option C, one statement** | **1.52 ms** | **0.91 ms** | **0.82 ms** | 0.34 ms |
| option A | 229.18 ms | 213.16 ms | 143.76 ms | 0.17 ms |

Index usage in option C, from `EXPLAIN`:

- **PostgreSQL** — `Seq Scan on ecotone_tag_versions` (2 rows, 1 buffer: the table is tiny), then
  `GroupAggregate → Index Scan using ecotone_tagged_events_pkey` with the `stream_name` predicate pushed into the
  index condition, then `Nested Loop → Index Scan using <stream>_pkey`, then
  `Index Scan using ix_…_aggregate` for the aggregate branch. 156 shared buffers — the same as the four statements it
  replaces (159).
- **MySQL 8.0** — `zz_versions type=range key=PRIMARY`, `zz_tagged type=ref key=PRIMARY`,
  `s type=eq_ref key=PRIMARY`, aggregate branch `type=ref key=ix_unique_event`. All four index-driven.
- **MariaDB 11.4** — identical, with `Using index condition` on the aggregate branch.
- **SQLite** — `MATERIALIZE flags` then `SEARCH … USING INDEX`, both branches.

One plan note worth carrying into implementation: on MySQL the aggregate branch picks `ix_unique_event`
`(aggregate_type, aggregate_id, aggregate_version)` rather than `ix_query_aggregate`
`(aggregate_type, aggregate_id, no)`, and today's standalone `loadAggregateEvents` therefore pays a **filesort** for
its `ORDER BY no` (`Extra=Using index condition; Using where; Using filesort`). MariaDB picks `ix_query_aggregate`
and does not. Dropping the `ORDER BY` from the aggregate branch — which option C does, ordering in PHP instead —
removes the filesort; that is a small free win on MySQL, and Part 4 keeps it.

### 3.6 `ofTypes` narrowing, on both halves

Today the two halves are asymmetric, and the log in Part 1.2 shows it: the **aggregate** half pushes
`event_name IN (?, ?)` into SQL (`loadAggregateEvents`'s `$eventNames`, the union of the handled event names of the
models on that instance), and the **tag** half pushes nothing — neither `flagsFor` nor `loadEventsByNumbers` filters
by type; `EventCriteria::ofTypes()` is applied in PHP, in `DbalTaggedEventReader::matchingEvents` via
`$branch->matchesEventType($event->getEventName())`.

In a `UNION ALL` the tag half can be narrowed too, but only carefully:

- The `flags` CTE must **not** be narrowed. It is shared across branches and its `has_n` columns are what implement
  AND-composed criteria; filtering it by type would drop rows a *different* branch still needs to test.
- The `flags ⋈ stream` join **can** carry `AND s.event_name IN (…)` with the **union of every branch's declared
  types**, and only when *every* branch declares some — a branch with no `ofTypes()` means "any type", which makes
  the union meaningless. That is a safe superset narrowing: it never removes a row PHP would have kept, and the
  per-branch exactness stays in `matchingEvents` where it is today.
- The aggregate half keeps its current per-instance narrowing unchanged.

Expected benefit: small in the common case (the tag branch already returns few rows — 10 here), real for a tag whose
index rows are numerous while the model handles one of many event types. **Recommendation: do it, because it costs
one `implode` and the guard "only when every branch declares types" is one line — but do not claim it as a reason
for the change.**

### 3.7 Ordering, preserved from one result set

Both orders survive because they are computed in PHP from columns the single statement already returns, exactly as
today.

- **Tag branches** order by `tag_sequence`, then `event_no`. The `flags` CTE emits `sequence_n` per tag position;
  `MatchedEvents::consider()` keeps, per `(stream, event_no)`, the *lowest* sequence over all matching branches and
  `inSequenceOrder()` sorts by `(sequence, eventNo)`. The combined statement returns `sequence_n` on every tag row,
  so `MatchedEvents` is fed identically. **No change to `MatchedEvents`.**
- **Aggregate branches** order by `no`. Today `selectEvents` adds `ORDER BY no ASC`; in the combined statement the
  aggregate branch is unordered (which is what removes MySQL's filesort) and the rows are sorted by `no` in PHP
  before folding — they are the events of one instance, tens to hundreds of rows, and `usort` on an integer is free
  at that size. `EventSourcingHandlerExecutor::fill($events, null)` then folds them in query order, as it does now.
- **The two orders never meet.** Models are folded independently, each from its own row set (§4.5a); the
  discriminator column is what keeps them apart. A tag row and an aggregate row for the *same* event cannot collide
  either — an aggregate's events carry no `#[EventTag]` (§4.12 makes `aggregate:` and `tags:` exclusive), so the two
  branches are disjoint by construction. Should they ever overlap, `MatchedEvents` keys on `(stream, event_no)` and
  would keep one.

### 3.8 Statement count, before and after

For the Part 1.1 handler, one stream, two aggregate instances:

| | Today | Option C |
|---|---|---|
| capture | 1 | 0 (folded in) |
| tag index | 1 | 0 (folded in) |
| per-stream events | 1 | **1** (the combined statement) |
| `tableExists` probes | 2 | 0 |
| `loadAggregateEvents` | 2 | 0 for the aggregate-backed model (folded in); **1** for the `#[Fetch]`-ed aggregate |
| **total reads** | **7** | **2** |

The `#[Fetch]`-ed aggregate is the one that stays outside, and deliberately: it is loaded by
`FetchAggregateConverter` through `AllAggregateRepository::findBy()`, which is the ordinary repository path — snapshots,
state-stored repositories, the whole aggregate rather than a handful of event types. Folding it into the decision
read would mean reimplementing aggregate loading inside the tag reader. Its counter is already captured in the
combined statement (Part 2.3b), which is the part that matters.

General form: **`1 + A_fetched` reads per handler**, down from `2 + S + 2·(A_backed + A_fetched)`.

---

## Part 4 — Design proposal

### 4.1 What to take

**Option C, one statement per stream table, with the capture in the first one — and the existence probes deleted
first, as a separate unit that stands on its own.**

> *(revision 2)* Option C acquires a second reason in Part 11.4: once decision models are snapshotted, reading the
> counters and the snapshot's covered position in **one** database snapshot is what makes "a snapshot that ran ahead
> of the events" structurally impossible rather than a runtime check. Revision 1 could only recommend option C for
> round trips; revision 2 can recommend it for a correctness property, and Part 13.6 therefore puts it **before**
> snapshots in the build order.

The probe deletion is worth stating separately because it is most of the measured win: two
`information_schema` round trips per handler, 0.91 ms each on PostgreSQL and 0.51 ms on MySQL, guarding against a
missing table that the very next statement would report anyway. `DbalEventStore::loadAggregateEvents` and `load`
call `$schema->tableExists(...)` and return `[]` when it is false — a "no stream, no events" convenience that the DCB
read path does not need, since a missing stream table on the decision path is already a
`ConfigurationException` telling the user to run setup (the same shape `DbalTagTables::missingTablesException`
produces for the tag tables). Catching `TableNotFoundException` costs nothing when the table exists.

### 4.2 Where it lives — the collaborators

The rule from `launch-execution.md` holds: SQL belongs to the collaborator that owns the table, orchestrating mains
carry no inline SQL, and no query mutates.

- **`DbalTagIndex`** keeps `flagsFor`, `eventsReferencedBy`, `insertRows`, `deleteForStream`. It owns
  `ecotone_tagged_events`, and it contributes the `flags` CTE **text and parameters**, not a statement — a new
  `flagsCte(array $tags): SqlFragment` beside `flagsFor`, with `flagsFor` expressed in terms of it. Still pure, still
  one table.
- **`DbalTagVersionRegister`** keeps `capture`, `currentVersions`, `bumpGuarded`, `bumpForBackfill`. It owns
  `ecotone_tag_versions`, and it likewise contributes `capturedCte(array $tags): SqlFragment`, with
  `currentVersions` expressed in terms of it. No new state, no new mode.
- **`DbalEventStore`** keeps ownership of the stream tables and grows one method,
  `loadTaggedAndAggregateRows(Connection, string $tableName, SqlFragment $capturedCte, SqlFragment $flagsCte, array $aggregateBranches): array`
  — it assembles the `UNION ALL`, because the stream table's columns, quoting and
  `EventStreamSchema::metadataFieldExpression()` are its business, and it returns raw rows plus the discriminator. It
  does not interpret them.
- **A new collaborator, `DbalSingleReadPlan`** (name provisional), owns the *shape*: given the `EventCriteria` and
  the aggregate instances, it decides which stream tables to address, asks the three above for their fragments, and
  hands `DbalTaggedEventReader` a list of statements to run. This is the only genuinely new class, and it holds no
  SQL of its own — it composes fragments. Stateless, one method.
- **`DbalTaggedEventReader::loadByCriteria`** stays the orchestration it is today and stays roughly its current
  length: resolve tags → ask the plan for the statements → run them → route rows by discriminator → build
  `MatchedEvents` for the tag rows, group the aggregate rows by instance, return `LoadedEvents` plus the folded-ready
  aggregate rows.

**No new interface.** `AppendableStore`, `AppendStrategy` and the tag collaborators are untouched; the
open-core/Enterprise split is untouched; `EventCriteria`, `TagResolver`, `AppendedTags`, `MatchedEvents`, the schema
classes and every table's DDL are untouched. Nothing is written, migrated or backfilled.

### 4.3 What `DecisionModelBatchLoader::load()` becomes

It stays an orchestration of named steps, and it gets shorter. Today it resolves criteria, calls `loadEventsFor`,
then calls `readEventsOfEachAggregateInstance` — a second database access it owns directly, which is the one piece of
"how to read" left in a class whose job is "what to read". The aggregate instances move into the criteria the store
is given, and the store returns their events alongside the tag events:

```
load(Message $message): array
    $criteriaByParameterName      = …                       // unchanged
    $aggregateInstances           = …                       // unchanged — identifier resolution, no database
    $loaded = $this->eventStore->loadByCriteria(
        $combinedCriteria,                                  // as today: models + fetched + boundary + aggregate capture leaves
        AggregateBranches::of($aggregateInstances),         // new second argument: (stream, type, id, event names)
    );
    $instances = [
        ...$this->foldInstances($criteriaByParameterName, $loaded->events),
        ...$this->foldAggregateBackedInstances($aggregateInstances, $loaded->eventsByAggregateInstance),
    ];
    return [DecisionModelLoadedState::HEADER_NAME => new DecisionModelLoadedState($instances, $loaded->appendCondition)];
```

`readEventsOfEachAggregateInstance` — the only method in the class that talks to the store a second time —
disappears. The loader keeps one store call and no knowledge of statements. **Capture-within-read replaces
capture-before-read**, and the guarantee gets stronger, not weaker: on PostgreSQL the capture and the reads now share
one snapshot instead of being merely correctly ordered.

`LoadedEvents` grows one field (`eventsByAggregateInstance`), or — cleaner, and worth an open question — a second
value object is returned. `EventStore::loadByCriteria`'s signature gains an optional second parameter. That is a
public-interface change on a gateway method, so Part 7 asks about it.

### 4.4 The in-memory mirror

**Nothing to do, and the design document should say why rather than leave it as an accident.**
`EnterpriseInMemoryTagCollaborator::loadByCriteria` already does its capture and its index walk as a single pass over
PHP arrays inside one call, with no yield point between them:

```php
$captured = $this->versions->capture($this->tagResolver->tagsOfCriteria($criteria));
$events   = $this->index->eventsMatching($eventStore, $criteria);
```

There is no concurrency between those two lines — a single PHP process, no interleaving — so the in-memory store is
**already one snapshot**, and has been since it was written. Folding the aggregate-backed read in (today
`InMemoryEventStore::loadAggregateEvents`, a `MetadataMatcher` filter over the same arrays) changes where the code
lives, not what it observes. The parity table in §4.4 of the design gains no row: the in-memory collaborator holds
the state and reads it in one pass, which is what "one snapshot" means without a database.

### 4.5 The guarantee statement for the docs

Today's §4.5 says *"capture first, then read"*. It would become:

> **One statement per stream table per handler.** A decision-model handler's pre-invocation load issues one statement
> per stream table holding events in its boundary. That statement captures every counted tag's version and reads both
> the tag-matched events and each aggregate-backed model's events, so the capture and every read inside it observe
> one database snapshot. A handler whose boundary touches a single stream — nearly all of them — therefore reads
> once. `#[Fetch]`-ed aggregates keep their own repository load, with their counter captured in that same statement
> beforehand.
>
> Capture-before-read remains the rule wherever a read stays outside: the `#[Fetch]`-ed aggregate, and the second and
> later stream tables of a multi-stream boundary on PostgreSQL. A read taken later than the capture can only make the
> guarded `UPDATE` fail — a spurious retry, never a missed conflict.

### 4.6 The cost and the benefit, honestly

**Correctness: unchanged.** Part 2 shows no case improves. On MySQL, MariaDB and SQLite the reads were already one
snapshot; on PostgreSQL the divergence was already benign. **This is not a bug fix and must not be presented as
one.**

**Statement count: 7 → 2** for the Part 1.1 handler; `2 + S + 2·(A_backed + A_fetched)` → `1 + A_fetched` in general.

**Latency: real, and mostly not where one would guess.** Under eight concurrent workers on one counter:

| | read phase, today | read phase, one statement |
|---|---|---|
| PostgreSQL | **3.70 ms** | **1.28 ms** |
| MySQL | **2.53 ms** | **0.87 ms** |

Of the ~2.4 ms saved on PostgreSQL, about 1.8 ms is the two `information_schema` probes and about 0.6 ms is the three
saved round trips. On MySQL, ~1.0 ms of the ~1.7 ms is the probes. **Deleting the probes alone gets most of the
benefit** — which is why Part 4.1 wants it as its own unit, landing first and standing on its own merits whatever is
decided about the single statement.

**Spurious-retry rate: no change worth claiming.** Measured, eight workers, 240–320 attempts per cell, same tag:

| Engine | Think time | Today | One statement |
|---|---|---|---|
| PostgreSQL | 0 ms | 85.9% conflicts, 45 commits | 78.8% conflicts, 68 commits |
| PostgreSQL | 2 ms | 87.1% | 86.2% |
| PostgreSQL | 10 ms | 87.1% | 87.5% |
| MySQL | 0 ms | 87.1% | 87.5% |
| MySQL | 2 ms | 87.5% | 87.1% |

The only cell that moves is PostgreSQL with a handler that does literally nothing, and even there the result is near
the structural floor: with eight contenders on one counter, at most one in eight can win, so ~87.5% conflicts is what
perfect behaviour looks like. **Add two milliseconds of handler work and the advantage vanishes.** MySQL shows no
movement at all, as Part 1.4 predicts — its reads were never the window. The conflict rate is governed by the number
of contenders and the length of the whole transaction, not by the shape of the read.

> *(revision 2)* **Latency is the axis where this changes.** Option C's ~2.4 ms is a constant: it is round trips, and
> it is the same whether a scope holds ten events or ten thousand. Part 12 measures the other axis — for a
> 1 000-event tag scope snapshotted at 900 the combined read is **6.7 → 1.5 ms on PostgreSQL, 8.6 → 1.5 ms on MySQL,
> 9.2 → 1.2 ms on MariaDB**, 2 002 rows to 103 — and that saving grows with the history. Option C is the
> constant-factor win; snapshots are the asymptotic one, and they compose.

**The honest summary for the maintainer:** the change buys round trips and a cleaner guarantee sentence. It does not
buy correctness, and it does not meaningfully buy throughput under contention. It is worth doing if a shorter,
provable guarantee and five fewer statements per handler are worth a new plan collaborator and a signature change on
`EventStore::loadByCriteria`. If they are not, **deleting the two existence probes is the whole of the measurable
benefit for a fraction of the cost**, and the guarantee sentence stays as it is.

---

## Part 5 — Constraints check

Against the rules carried through this Run (`launch-execution.md`):

| Rule | How the proposal stands |
|---|---|
| **Optimistic concurrency only** | Unchanged. The guard is still `UPDATE … WHERE version = :captured` / `INSERT … ON CONFLICT DO NOTHING`. No new conflict mechanism. |
| **No locking reads** | The combined statement is a plain `SELECT` with CTEs. **No `FOR UPDATE`, no `LOCK IN SHARE MODE`, no `SELECT … FOR SHARE` anywhere** — verified in every SQL in Part 3. Note the single statement makes the temptation smaller, not larger: there is no longer a gap between capture and read to be tempted to lock across. |
| **Stateless services** | `DbalSingleReadPlan` holds no per-execution state; the fragments it composes are function-scoped values. No caches, no snapshots, no message-keyed maps. |
| **CQS** | The combined statement reads and does not write; it creates no tables (the probe deletion moves that failure to the existing "run setup" `ConfigurationException`, which is the CQS fix §4.5's `ensureTagTablesExist` removal already made for the reader). `capturedCte`/`flagsCte` are pure. Nothing returns data *and* mutates. |
| **SQL owned by the table-owning collaborator** | `ecotone_tag_versions` → `DbalTagVersionRegister`; `ecotone_tagged_events` → `DbalTagIndex`; the stream tables → `DbalEventStore`. `DbalSingleReadPlan` composes fragments and writes none. |
| **Orchestrating mains, no inline SQL** | `DecisionModelBatchLoader::load` gets shorter and loses its second store call; `DbalTaggedEventReader::loadByCriteria` stays a short sequence of named steps. |
| **Black-box tests** | Every guarantee below is expressed through userland API and observable behaviour. The statement-count guarantee is **not** testable that way — the maintainer already ruled on this on 2026-09-27 when `DecisionModelLoadQueryCountDbalTest` and `QueryCountingDbalConnection` were deleted — so it stays review-protected, like one-load-per-handler. Part 6 says so explicitly rather than smuggling a counting harness back in. |
| **Four engines plus in-memory parity** | All four SQL dialects given and measured in Part 3; the in-memory store needs no change (Part 4.4) and stays the semantic reference. |
| **Open-core path untouched** | Everything here is on the Enterprise tag path: `DbalTaggedEventReader`, `DbalTagIndex`, `DbalTagVersionRegister`, `DecisionModelBatchLoader` are all `licence Enterprise`. `DbalEventStore` gains one method used only from that path; its open-core `load`/`loadAggregateEvents`/`appendTo` behaviour is unchanged — including, deliberately, the `tableExists` probe on the *public* `load()` and `loadAggregateEvents()`, whose "missing stream returns no events" contract open-core callers rely on. **The probe deletion applies to the decision read path only.** |
| **No nullable service dependencies / no single-implementation interfaces** | `DbalSingleReadPlan` is injected unconditionally into `DbalTaggedEventReader` as a concrete class, not behind an interface with one implementor. `SqlFragment` is a value object, not a service. |
| **No schema or protocol change** | No DDL, no migration, no backfill, no new column, no configuration. *(revision 2: snapshots add one additive table — Part 13.2.)* |

---

## Part 6 — Test plan and docs

### 6.1 Tests — black box, behaviour only

Every one of these is expressible through `EcotoneLite` and the `EventStore` gateway, and every one of them fails on
the current code for a reason other than "the SQL looks different".

1. **The four-source handler decides correctly** — one tag-scoped model, one aggregate-backed model, one `#[Fetch]`-ed
   aggregate, one `#[DecisionBoundary]`; the folded values and the appended events are exactly what they are today.
   Runs on all four engines and in memory. *This is the regression net: the change is behaviour-preserving, so the
   existing 30-plus decision-model tests are the real suite and must stay green unchanged.*
2. **Two models on one aggregate instance still share one read** — observable as both folding the same events.
3. **A competing aggregate save between load and append still conflicts** — `DecisionModelConcurrencyException`
   naming the aggregate, nothing appended. Two connections. Exists today for the aggregate-backed path; must survive.
4. **A competing tagged append between load and append still conflicts** — as above for the tag path.
5. **A multi-stream boundary** — a model folding events from the default stream and from a `#[Stream]`-declared one,
   plus an aggregate-backed model on a third: correct fold, correct order, correct conflict. This is the case Part 3.4
   says is one statement *per stream*, and it is the one most likely to break.
6. **A legacy `_<sha1>` stream table** — the aggregate-backed model over a 1.x table, already covered by
   `AggregateBackedDecisionModelLegacyStreamDbalTest`; must stay green, since Part 3.4's stream discovery touches it.
7. **An aggregate with more events than `loadBatchSize`** — configure `withLoadBatchSize(2)`, give the instance five
   events, assert the fold. Today's paging loop is inside `selectEvents`; the combined statement must not silently
   cap the aggregate branch.
8. **A missing stream table on the decision path** — the `ConfigurationException` naming the setup command, not an
   empty fold. This is the behaviour change the probe deletion introduces and it needs a RED-first test: today a
   missing stream table makes `loadAggregateEvents` return `[]` and the model folds from nothing, which is a silent
   wrong decision of exactly the kind this design refuses everywhere else. **Worth doing whatever is decided about
   the single statement.**
9. **An aggregate-backed model whose instance has no events** — folds from nothing, counter captured at 0, a
   concurrent creation conflicts. Already covered; must stay green.
10. **`ofTypes` narrowing** — a model handling one of several types carried by the same tag folds only its own; and
    the same with two branches where one declares no types (the narrowing must then be skipped).
11. **In-memory parity** — the whole of 1–10 that does not need two connections, run on `InMemoryEventStore`, asserting
    identical folds and identical conflicts.

Not tested, by the 2026-09-27 rule: statement counts. The `1 + A_fetched` guarantee and the one-snapshot guarantee are
review-protected, recorded in the design document, and verified by the plans in Part 3.5 at review time.

> *(revision 2)* Tests 12–21, for decision-model snapshots, are in Part 13.3. Test 18 of
> `2026-09-28-dcb-aggregate-full-tag-design.md` — *"the model reads the full history"* — changes its second clause
> to *"the model reads from its own snapshot"*; its first clause, that the aggregate's own handler still loads
> through the aggregate's document-store snapshot, is unchanged and load-bearing (Part 10.5).

### 6.2 Docs to touch

- `docs/superpowers/specs/2026-09-20-dcb-design.md` — §4.5 "capture first, then read" becomes the Part 4.5 wording;
  §4.12's "Run order" paragraph replaces "then reads one `loadAggregateEvents()` per distinct instance" with the
  single statement; the Part 8 decision log gains this decision.
- `docs/superpowers/specs/2026-09-28-dcb-aggregate-full-tag-design.md` — §2.3's statement table and §2.5's
  "Ordering" note; Part 9 gains an outcome line.
- `upgrade-2.0.md` §4 — only if the probe deletion changes a user-visible failure (it does: a missing stream table on
  the decision path becomes an exception naming the setup command instead of an empty fold). One paragraph.
- No user-facing documentation on docs.ecotone.tech changes: nothing in the public API moves except
  `EventStore::loadByCriteria`'s optional second parameter, which is framework-internal in practice (OQ3).

---

## Part 7 — Open questions, with recommended answers

**OQ1 — Should a `#[DecisionBoundary]` be rejected when its criteria are scoped only by filter-only tags?**
Part 2.3(d): models already are (`DecisionModelModule::assertNoModelScopedOnlyByFilterOnlyTags`), boundaries are not,
and such a boundary guards nothing, silently. *Recommendation: **yes**, the same guard with the boundary's method name
in the message, as its own small unit, independent of this proposal.* It is the only genuine correctness gap this
research found.

**OQ2 — Delete the `tableExists` probes from the decision read path, as a separate unit that lands first?**
*Recommendation: **yes**.* It is ~1.8 ms of the ~2.4 ms saved on PostgreSQL and ~1.0 ms of ~1.7 ms on MySQL, it is a
few lines, it turns a silent wrong decision (missing stream → empty fold) into the `ConfigurationException` the rest
of the design already uses, and it is worth doing whether or not option C is built. Scope it to the decision path:
open-core `EventStore::load()` and `loadAggregateEvents()` keep their "missing stream returns nothing" contract.

**OQ3 — How should the aggregate branches reach the store: a second parameter on `EventStore::loadByCriteria`, or a
new method?** A second parameter changes a gateway-registered public signature for something only the framework
passes; a new method (`loadDecisionScope(EventCriteria, AggregateBranches): LoadedDecisionScope`) leaves
`loadByCriteria` exactly as users know it and keeps the framework path on `EventStore::RAW_REFERENCE`, where §4.4
already says the framework belongs. *Recommendation: **a new method**, with `loadByCriteria` unchanged and
implemented in terms of it with no branches.*

**OQ4 — One statement per stream table, or keep a discovery read and then one combined statement per stream?**
Part 3.4. The stream list cannot be known before the index is read, and a union over every declared stream is wrong
in the presence of legacy tables. *Recommendation: **the flags CTE goes into the first stream's statement and the
stream list comes from it**; when the flags name a second stream, issue one further combined statement per
additional stream, each carrying its own `flags` CTE narrowed by `stream_name`. For `S = 1` — the overwhelming
majority — that is exactly one statement. Document the guarantee per stream table, not per handler.*

**OQ5 — Should the `#[Fetch]`-ed aggregate be folded into the statement too?** ~~It would mean reimplementing
repository loading — snapshots, state-stored aggregates, the full event set — inside the tag reader.~~
**Answered again in revision 2 (Part 13.4), and the revision 1 reason was wrong.** With snapshots on both sides the
two loads are the same steps (Part 10.1), so the mechanism is not the obstacle. What is:
`AllAggregateRepository::findBy()` is a **dispatch point** over user-supplied `EventSourcedRepository`
implementations, state-stored aggregates that have no events at all, cross-connection aggregates and document-store
snapshots — so a folded read would have to decide, per aggregate class, whether it may bypass the user's repository.
*Recommendation: **not a replacement, a hand-off** — the batch loader folds the fetched aggregate in the same
statement and leaves it in `DecisionModelLoadedState`; `FetchAggregateConverter` keeps its signature, gains one guard
clause and falls back to `AllAggregateRepository` otherwise, with eligibility decided at bootstrap. Build it last
(Part 13.6), and only if the round trip is worth the branch.*

**OQ6 — Push `ofTypes` into the tag half's SQL?** Part 3.6: safe only as the union of every branch's types and only
when every branch declares some. *Recommendation: **yes**, as part of the same unit, one line of guard — but do not
present it as a reason for the change.*

**OQ7 — Is the change worth building at all, given Part 4.6?** The measured benefit is round trips and a shorter
guarantee sentence; correctness is unchanged and the conflict rate is unchanged once handlers do any work.
*Recommendation: **build OQ1 and OQ2 regardless — they are small, independent and one of them closes a real hole.**
Build option C if the maintainer wants the `1 + A_fetched` statement count and the stronger guarantee wording on
PostgreSQL; it is a clean, additive, schema-free change with a new plan collaborator and no user-visible behaviour
difference. Do not build it expecting throughput.*

---

## Part 8 — Summary

The aggregate decision model **can** be loaded in the same SQL as the event tags — as a `UNION ALL` with a discriminator
column, with the version capture folded in as a CTE (option C), index-driven on all four engines and measured at
1.52 ms on PostgreSQL, 0.91 ms on MySQL, 0.82 ms on MariaDB and 0.34 ms on SQLite against 330 000 events. The literal
reading of "same SQL" — one `SELECT` `OR`-ing the two predicates — is 150 to 230 times slower on the three engines
that matter and must not be built.

It would not, however, be fixing anything. MySQL, MariaDB and SQLite already read the whole load from one snapshot,
pinned at the capture; PostgreSQL does not, and its divergence is provably benign because the capture comes first and
every counted tag in it guards the append. The one real hole found along the way is unrelated to the question: a
`#[DecisionBoundary]` scoped by a filter-only tag guards nothing and nobody says so, where the identical mistake in a
`#[DecisionModel]` is a bootstrap error.

What the single statement does buy is five fewer round trips per handler and a guarantee that can be stated in one
sentence. Two thirds of the measured latency, though, is not round trips at all — it is two `information_schema`
existence probes, one before each `loadAggregateEvents()`, at 0.91 ms on PostgreSQL, guarding against a missing table
whose absence should be an error rather than an empty fold. **Delete those first.**

> *(revision 2)* Two sentences change. **Added:** snapshots give the decision read the only saving that grows with
> history — 6.7 → 1.5 ms on PostgreSQL, 8.6 → 1.5 ms on MySQL, 9.2 → 1.2 ms on MariaDB for a 1 000-event tag scope
> covered to 900, 2 002 rows to 103 — and nothing new has to be invented to get it: `tag_sequence` already *is* the
> tag's counter version, stamped identically on every event of an append, and `fromVersion` is already a SQL
> argument. **Corrected:** OQ5's reason. Folding `#[Fetch]` is blocked not by the mechanism but by
> `AllAggregateRepository` being a dispatch point over user repositories and state-stored aggregates; the right move
> is a hand-off, not a replacement. Parts 9–13 carry both.

---

# Revision 2 — snapshots for decision models, and one load flow

## Part 9 — What a decision-model snapshot is

### 9.1 Identity: the same key the counter already uses

A snapshot is addressed by three things, and two of them already exist.

| Scope kind | Scope key | The counter it shares the key with |
|---|---|---|
| tag-scoped, one tag | `tag_name` + `tag_value` | `ecotone_tag_versions (tag_name, tag_value)` — the same row |
| tag-scoped, several tags (AND) | the ordered `(name, value)` pairs of the branch | the **position tag**: the first *counted* tag (§9.3) |
| aggregate-backed | `aggregate_type` + `aggregate_id` | `aggregate_<Type>` / `<id>` — `AggregateCounterTags::counterTagOf()` |

Plus the **model class**, because two models can be scoped by the same tag and fold it differently (`PayoutsToday`
and `WalletBalance` may both be `#[DecisionModel(tags: ['wallet'])]`). So the primary key is
`(model_class, scope_key)`, where `scope_key` is built the way `TagKey::of()` and
`AggregateBackedDecisionModelInstance::instanceKey()` already build theirs — no new identity concept enters the
system.

**The `#[Fetch]`-ed aggregate keeps the identity it has**: `aggregate_snapshots_<Class>` in the document store,
document id `AggregateIdString`-shaped (`EventSourcedRepositoryAdapter::getSnapshotDocumentId`). §10.4 says why that
is not merged.

### 9.2 Content: state, position, and a fold shape

```
model_class      VARCHAR(255)   Ecotone\...\PayoutsToday
scope_key        VARCHAR(512)   wallet|w-1        (or  aggregate_Wallet|w-1)
fold_shape       CHAR(40)       sha1 of (class, sorted handled event names, sorted tag names)
state            TEXT           the folded model, JSON
covered_position BIGINT         the tag_sequence / aggregate_version it covers
taken_at         BIGINT         hrtime, for pruning — the column DbalDocumentStore already carries as updated_at
```

**Serialization: the mechanism that ships, with one thing removed.** `DbalDocumentStore::convertToJSONDocument`
runs the model through `ConversionService` from `application/x-php` to `application/json` and stores the class name
in `document_type` so the read can convert back. Aggregate snapshots use exactly that today, and the requirement on
the class is exactly the one aggregate snapshots impose: a registered converter — a `#[MediaTypeConverter]` such as
`BasketMediaTypeConverter` in `packages/PdoEventSourcing/tests/Fixture/Snapshots`, or a serializer package. A
decision model is a plain PHP object with `#[EventSourcingHandler]`s and no identity, which makes it *easier* to
serialize than an aggregate, not harder: there is no identifier property and no version property to preserve.

**And that is the thing to remove.** `EventSourcedRepositoryAdapter::findBy` reads the covered version *out of the
snapshot object*, through a `#[Version]`-annotated property, and asserts it is greater than zero:

```php
$aggregateVersion = $this->getAggregateVersion($aggregate);
Assert::isTrue($aggregateVersion > 0, sprintf('Serialization for snapshot of %s is set incorrectly, it does not serialize aggregate version', $aggregate::class));
```

That assertion exists because the position rides inside the user's own serialization and users forget it. A decision
model has no `#[Version]` property and must not grow one: **`covered_position` is a column of the snapshot record,
written by the framework, never by the user's converter.** The state is opaque to the framework; the position is
opaque to the user.

### 9.3 The position, per scope kind — and why the tail is exact

**Tag scope: the position is the tag's counter version, and it is already written on every index row.**

`AppendedTags::sequencedAfterBump()` computes, per involved tag, `capturedVersion + 1` — the value the counter has
*after* the guarded bump — and hands it to `EventsTags::sequencedBy()`, which stamps it on every event of the append
that carries that tag. `DbalTagIndex::insertRows` then writes one `ecotone_tagged_events` row per (event, tag) with
that `tag_sequence`. Three consequences, each checkable in the code:

1. **`tag_sequence` and `ecotone_tag_versions.version` are the same number space.** After any append, the tag's
   counter equals the maximum `tag_sequence` of its index rows. A snapshot that covers counter version *v* therefore
   has tail `tag_sequence > v`, with no translation.
2. **The values are dense: 1, 2, 3, …** Every counted append bumps by exactly one (`version = version + 1`, or the
   initial `INSERT` at 1) and stamps that one value. A lost append writes nothing at all (§4.4 of the main spec:
   *"counters first, events second … losers fail before they write"*), so it burns no sequence.
3. **All events of one append share one value, so a snapshot cannot be taken mid-append.** `sequencedBy` assigns one
   sequence per tag for the whole batch, and `insertRows` writes every index row of the append in **one**
   `INSERT … SELECT … UNION ALL` statement inside the handler's transaction — after `bumpGuarded`, after
   `insertEventRows`, before `COMMIT`. A reader on another connection sees all of an append's index rows or none of
   them; a reader on the *same* connection is the appending transaction itself, which is not taking snapshots. So
   `> covered` always cuts at an append boundary. There is no arrangement of statements that produces a half-covered
   append, and therefore no "the snapshot folded three of the five events of one commit" hazard.

**Cross-stream folding is fine, and this is where `tag_sequence` earns its existence.** A tag-scoped model may fold
events from several stream tables (§4.5a of the main spec). `event_no` is per stream and says nothing across
streams; `tag_sequence` is per tag and, because the counter row is the serialization point, **is commit order for
that tag across every stream** (§4.4: *"`no` is now allocated under the tag's row lock, so for any one tag, `no`
order equals commit order"*). One scalar covers a multi-stream scope. A per-stream `event_no` watermark map would
also be exact — and would ride the existing primary key prefix, which is tempting (§12.3) — but it needs a vector,
it needs a rule for a stream that appears for the first time after the snapshot, and it buys nothing `tag_sequence`
does not already give. **Recommendation: one scalar, `tag_sequence`.**

**Multi-tag AND scope: still one scalar, not a vector.** A model scoped `tag('wallet', w).andTag('region', r)`
matches only events carrying both. Such an event was appended once, and that append bumped *both* counters in the
same sorted pass, stamping `sequence_wallet` and `sequence_region` from the same transaction. So for every event in
the scope the two predicates agree: an event committed after the snapshot instant has `sequence_wallet > covered_wallet`
**and** `sequence_region > covered_region`; one committed before has both `≤`. Either tag alone is an exact cut, and
the AND-filter (`has_0 AND has_1`, unchanged) does the rest. The vector is redundant.

**One precision the code forces.** `DbalTaggedEventReader::matchingEvents` takes its ordering sequence from
`$branch->tags()[0]` — the first declared tag. A **filter-only** tag is stamped with sequence `0`, not its counter
(`EventsTags::sequencedBy`: `'sequence' => $tag['counted'] ? $versionsAfterBump[$key] : 0`), because filter-only
tags have no counter at all. A model may legitimately mix one counted and one filter-only tag —
`DecisionModelModule::assertNoModelScopedOnlyByFilterOnlyTags` rejects only the all-filter-only case. **So the
position tag is the first *counted* tag of the branch, not `tags()[0]`.** Picking `tags()[0]` blindly would give
`covered = 0` forever and silently disable the snapshot; worse, if a snapshot were ever written against it, `> 0`
would still be a correct (full) tail, so the bug would be invisible. Name the position tag explicitly.

**Aggregate scope: `aggregate_version`, and the push-down already exists.**
`EventStore::loadAggregateEvents($stream, $type, $id, $fromVersion, …)` compiles `$fromVersion` into
`<aggregate_version> >= ?` (`DbalEventStore::loadAggregateEvents`), which is the same argument
`EventSourcingRepository::findBy()` passes for aggregate snapshots. Tail = `fromVersion: covered + 1`. Exact because
`_aggregate_version` is dense per instance and assigned by the save.

### 9.4 Where it lives

Three candidates, and the choice is forced by §10.2 and §11.4.

| | (a) the document store | (b) a table on the **event store's** connection | (c) the document store, moved onto the event store's connection |
|---|---|---|---|
| reuses shipped code | all of it (`DbalDocumentStore`, the `aggregate_snapshots_` convention) | the serializer only | most of it |
| can be a CTE of the option C statement | **no** — `DbalConfiguration::getDocumentStoreConnectionReference()` is independently configurable | **yes** | yes, but only when the user has configured it that way |
| transactional with the append | only if the references coincide (then `CachedConnectionFactory::createFor` hands back the same `Connection`, so it is the same transaction) | **always** | user-dependent |
| key shape | one `document_id` string; a tag scope must be encoded into it | `(model_class, scope_key)`, two columns, indexable | as (a) |
| covered position | would have to ride inside the serialized object (§9.2) | its own column | as (a) |
| measured read | 0.05–0.25 ms (PG), 0.08–0.11 ms (MySQL/MariaDB) — a separate statement | 0.05–0.33 ms standalone, **0 ms as a CTE branch** | — |

**Recommendation: (b), a new `ecotone_decision_snapshots` table on the event store's connection**, created by a
`DbalTableManager` beside `TagTableManager` and covered by `database:setup` / `verify-schema` the same way. It is the
only option that folds into the one statement, it keeps the position out of the user's serialization, and §11.4 shows
it makes the "snapshot ahead of the events" question disappear instead of needing an argument. (a) is not *unsafe* —
§11.4 proves a cross-connection snapshot store is self-guarding — it is merely a second round trip and a worse key.

### 9.5 When it is taken

**Not inside the deciding handler's transaction, and this is the difference from aggregate snapshots.**
`EventSourcedRepositoryAdapter::save()` writes the snapshot inline, in the save path, whenever
`version % threshold === 0` — every Nth command pays a serialize and an upsert before it may commit. For decision
models that is the wrong trade, because the tail is exact by position: **a snapshot that is late is not wrong, it is
only longer.** Nothing depends on it existing, on it being recent, or on it being written at all.

So: after the handler commits, on its own channel, driven by the same threshold arithmetic
(`covered_position` of the current snapshot vs the counter after the append). Inline every-N stays available as an
option for deployments that will not run a consumer, and it is what an in-memory test does. The important property is
that the *default* adds nothing to command latency.

### 9.6 Invalidation and self-healing

**The model class changed → the fold changed → the snapshot is meaningless.** `fold_shape` is a hash over the model
class name, its sorted `#[EventSourcingHandler]` event class names (`handledEventClasses()`, which is also what the
criteria's `ofTypes` carries) and its sorted tag names. It is part of the read predicate, not a post-check: a
deploy that adds a handler simply stops matching, the tail becomes the whole history, and the next write stores a
snapshot under the new shape. No migration, no deploy gate, no rolling-deploy hazard — the two shapes coexist, and
the stale row is pruned by `taken_at`.

**Anything else wrong → full fold.** The pattern is already in the codebase and should be copied verbatim:
`EventSourcedRepositoryAdapter::findBy` catches `DocumentException`, logs *"Snapshot ignored to self-heal system"*,
and loads from version 1; it also checks `$aggregate::class === $aggregateClassName` and discards a snapshot of the
wrong type. Same three cases here — deserialization failure, wrong class, missing row — and the same outcome. **The
completeness of the fold is guaranteed by the position, never by the snapshot**, so discarding one is always
available and always correct.

---

## Part 10 — One load flow

### 10.1 The flow, named once

```
resolve scope        →  tag (name, value) pairs, and aggregate (type, id) pairs      — no database
capture counters     →  ecotone_tag_versions, every counted tag of every scope       ─┐
read snapshots       →  ecotone_decision_snapshots, one row per (model, scope)        │ one
read tails           →  tag_sequence > covered   /   aggregate_version > covered      │ statement
fold                 →  EventSourcingHandlerExecutor::fill($tail, $snapshotState)    ─┘ (then PHP)
hand to the handler  →  DecisionModelLoadedState
```

Every participant runs the same steps; only the *scope resolver* and the *tail predicate* differ, and both are
already per-kind today:

| | tag-scoped model | aggregate-backed model | `#[Fetch]`-ed aggregate |
|---|---|---|---|
| scope | `DecisionModelParameterLoader::resolveCriteria` | `AggregateBackedDecisionModelLoader::resolveInstance` | `FetchedAggregateCounterCapture::resolveCriteria` |
| counter | the tag itself | `aggregate_<Type>:<id>` | `aggregate_<Type>:<id>` |
| tail predicate | `tag_sequence > :covered` | `aggregate_version > :covered` | `aggregate_version > :covered` |
| fold | `fill($tail, $state)` with the model's handlers | `fill($tail, $state)` with the model's handlers | `fillFor($class, $state, $tail)` with the aggregate's |
| executed by | the tag reader | the tag reader | **its repository** (§10.4) |

`EventSourcingHandlerExecutor::fill(array $events, ?object $existingAggregate)` already takes the base state —
`$aggregate = $existingAggregate ?? (new $this->aggregateClassName())`. Folding onto a snapshot is the argument
that is already there, passed instead of `null`. `GroupedEventSourcingExecutor::fillFor` is the same on the
aggregate side, and `EventSourcedRepositoryAdapter` already calls it that way.

### 10.2 The statement, extended — measured, not sketched

Option C with two more branches. The interesting part is the `flags` CTE: its tail predicate has to come from the
snapshot that the *same statement* is reading.

```sql
WITH captured AS (
    SELECT tag_name, tag_value, version FROM ecotone_tag_versions
    WHERE (tag_name = ? AND tag_value = ?) OR …
), snaps AS (
    SELECT model_class, scope_key, state, covered_position FROM ecotone_decision_snapshots
    WHERE (model_class = ? AND scope_key = ? AND fold_shape = ?) OR …
), flags AS (
    SELECT stream_name, event_no,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS sequence_0,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END)     AS has_0,
           …
    FROM ecotone_tagged_events
    WHERE (tag_name = ? AND tag_value = ?
           AND tag_sequence > COALESCE((SELECT covered_position FROM snaps WHERE model_class = ? AND scope_key = ?), 0))
       OR …
    GROUP BY stream_name, event_no
)
SELECT 'version'  AS branch, tag_name, tag_value, version, NULL, …            FROM captured
UNION ALL
SELECT 'snapshot', model_class, scope_key, covered_position, state, …         FROM snaps
UNION ALL
SELECT 'tag',      NULL, NULL, NULL, NULL, s.no, s.event_name, s.payload, s.metadata, f.sequence_0, f.has_0
                                                                              FROM flags f JOIN <stream> s ON s.no = f.event_no
                                                                              WHERE f.stream_name = ?
UNION ALL
SELECT 'aggregate', NULL, NULL, NULL, NULL, s.no, s.event_name, s.payload, s.metadata, NULL, NULL
                                                                              FROM <stream> s
                                                                              WHERE <aggregate_type> = ? AND <aggregate_id> = ?
                                                                                AND <aggregate_version> > ?
```

**PostgreSQL turns the scalar sub-select into an `InitPlan` and pushes it into the index condition.** Measured, the
statement above against the Part 12 dataset:

```
->  HashAggregate
      InitPlan 2 (returns $1)
        ->  CTE Scan on snaps  (rows=1)
      ->  Index Only Scan using ecotone_tagged_events…
            Index Cond: (tag_name = 'wallet' AND tag_value = 'w-1'
                         AND tag_sequence > COALESCE($1, 0) AND stream_name = '…')
            Heap Fetches: 0
```

The snapshot's covered position is evaluated **once** and becomes part of the index range, inside the same statement
that captured the counters. That is the strongest form of the maintainer's original question the mechanism can take:
capture, snapshot position, tail and aggregate branch, one snapshot of the database, one round trip.

**The alternative, and it is nearly as good.** Reading the snapshots as their own statement first and binding
`covered_position` as an ordinary parameter:

| | PostgreSQL 16 | MySQL 8.0 | MariaDB 11.4 |
|---|---|---|---|
| (i) one statement, snapshot as a CTE | 1.37–1.54 ms | 1.52–1.54 ms | 1.07–1.16 ms |
| (ii) snapshot read + option C statement | 0.07–0.33 + 1.19–1.22 = **1.29–1.52 ms** | 0.05–0.21 + 1.11–1.12 = **1.16–1.34 ms** | 0.06–0.08 + 0.72–1.02 = **0.78–1.11 ms** |
| (iii) option C, no snapshot | 4.68–6.72 ms | 7.52–8.57 ms | 9.24–9.42 ms |

*Medians of 20 runs each, two independent runs per engine; 30 900 stream rows, 30 900 index rows, the read tag
holding 1 000 of them, snapshot at 900. (i) and (ii) return 103 and 102 rows; (iii) returns 2 002.*

(i) and (ii) are within noise of each other and both are four to eight times (iii). **Recommendation: (i), the
CTE — but not for the milliseconds.** Take it because the snapshot position is then read in the same database
snapshot as the counters it must be compared against (§11.4), which turns a runtime invariant into a structural one;
and because it keeps the guarantee sentence to one clause. Take (ii) instead if the snapshot store is ever allowed
to live on another connection, where (i) is impossible anyway.

**Ordering is unaffected.** The `flags` CTE still emits `sequence_n`, `MatchedEvents::consider()` still keeps the
lowest sequence per `(stream, event_no)` and `inSequenceOrder()` still sorts by `(sequence, eventNo)` — over a
shorter list. The aggregate branch is still sorted by `no` in PHP. §3.7 of revision 1 stands word for word.

### 10.3 What a model's fold becomes

```
foldInstances(...)
    $snapshot = $loaded->snapshotFor($parameterName);          // state + covered position, or null
    $tail     = $this->tagResolver->eventsMatching($loaded->events, $criteria);
    $instance = $loader->fold($tail, $snapshot?->state);        // fill($tail, $state ?? null)
```

`DecisionModelParameterLoader::fold` and `AggregateBackedDecisionModelLoader::fold` each gain the second argument and
pass it straight to `fill()`. `DecisionModelBatchLoader::load` gains nothing structurally: it already hands criteria
to the store and folds what comes back, and `readEventsOfEachAggregateInstance` — its second store call — was already
leaving in revision 1 (§4.3).

### 10.4 OQ5 revisited: can the `#[Fetch]`-ed aggregate be the same load?

**In shape, yes — completely.** With aggregate-scope snapshots the fetched aggregate's load *is*
scope → snapshot → `aggregate_version > covered` → fold with its own `#[EventSourcingHandler]`s. That is literally
`EventSourcedRepositoryAdapter::findBy` with the document-store lookup swapped for a table read. Revision 1's stated
reason — *"it would mean reimplementing repository loading"* — describes the mechanism, and the mechanism turns out
not to be the obstacle. **Revision 1's OQ5 answer was right for the wrong reason, and the right reason is narrower
and harder to remove.**

**In execution, no, because `findBy` is a dispatch point, not a loader.** `FetchAggregateConverter` calls
`AllAggregateRepository::findBy()`, which walks registered repositories and asks `canHandle()`:

| What it may dispatch to | Can the tag reader's statement replace it? |
|---|---|
| `EventSourcedRepositoryAdapter` wrapping `EventSourcingRepository` (the Dbal event store) | **yes** — same connection, same stream, `loadAggregateEvents` is what both call |
| `EventSourcedRepositoryAdapter` wrapping a **user-supplied** `EventSourcedRepository` | **no.** `EventSourcedRepository` is a public interface (`findBy($class, $ids, $fromVersion): EventStream`) that users implement; its events are not in `ecotone_event_stream` and may not be in a database at all |
| `StateStoredRepositoryAdapter` — document store, Doctrine ORM, Eloquent, user repositories | **no, and never.** State-stored aggregates have **no events**. There is nothing to fold and nothing to tail. Out, permanently |
| an aggregate on another connection | **no** — `CrossConnectionDecisionModelGuard` exists precisely because a read on one connection cannot be guarded by an append on another |
| an aggregate with `withSnapshotsFor(...)` configured | **only by taking its snapshot over**, §10.5 |

So a folded `#[Fetch]` would have to *decide, per aggregate class, whether it is allowed to bypass the user's
repository*, and fall back when it is not. That is two load paths sharing one name — the opposite of one flow — and
the branch condition is invisible to the user until the day they register a repository and the read silently changes
shape.

**What is worth building instead: a hand-off.** `DecisionModelBatchLoader` already resolves each `#[Fetch]`-ed
parameter's identifiers before the converter runs — that is how `FetchedAggregateCounterCapture` puts the counter in
the capture set (§2.3b). It can therefore also fold the aggregate from the same statement and leave the instance in
`DecisionModelLoadedState`, alongside the models. `FetchAggregateConverter::getArgumentFrom` then:

```
$preloaded = DecisionModelLoadedState::fetchedAggregateIn($message, $this->parameterName);
if ($preloaded !== null) { return $preloaded; }
… today's AllAggregateRepository::findBy() …
```

The pre-load is populated only when the aggregate is event-sourced, on Ecotone's own `EventSourcingRepository`, on
the handler's connection and in the handler's stream — a condition the batch loader can evaluate at **bootstrap**,
not at runtime, so the shape of the read is fixed per handler and reviewable. Everything else takes the branch it
takes today, unchanged and untested-against.

**`FetchAggregateConverter` still exists and keeps its signature.** What changes is one guard clause at the top of
`getArgumentFrom` and nothing else: the licence check, the expression evaluation, `identifiersFrom`, the
`AggregateNotFoundException`, the nullable handling and the `ResolvedAggregate` unwrapping are all untouched. It does
*not* gain knowledge of snapshots, streams or SQL.

**The cost of the hand-off, stated plainly:** it removes one round trip per fetched aggregate (revision 1 measured
the whole read phase at 3.70 ms on PostgreSQL, of which the fetched aggregate's `tableExists` + `loadAggregateEvents`
is ~1.24 ms), and it shortens the general form from `1 + A_fetched` to `1`. It also puts the fetched aggregate's read
inside the same database snapshot as its captured counter, which today it is not (§2.3b shows this is safe, not that
it is tidy). It does **not** make the aggregate any more consistent than it is now. It is a legitimate step; it is
the last one.

### 10.5 The two snapshot mechanisms must not diverge

If an aggregate has `withSnapshotsFor(Wallet::class, 100)` **and** a `#[DecisionModel(aggregate: Wallet::class)]`
folds its events, there are now two snapshots of overlapping histories:

| | the aggregate's | the model's |
|---|---|---|
| stores | `aggregate_snapshots_Wallet` in the document store | `ecotone_decision_snapshots` |
| holds | the whole aggregate | the model's projection of it |
| position | `#[Version]` inside the serialized object | `covered_position` column |
| written | inline in `save()`, every Nth event | after commit, every Nth append |
| read by | `EventSourcedRepositoryAdapter::findBy` | the decision read |

**They do not have to agree, and trying to make them agree is the trap.** They snapshot *different objects*: the
aggregate's snapshot is `Wallet`, the model's is `WalletBalance`. Neither is derivable from the other, neither
invalidates the other, and both are exact by their own position. The `2026-09-28-dcb-aggregate-full-tag-design.md`
test 18 already pins this — *"a model backed by an aggregate with a configured snapshot: the aggregate's own command
handler still loads through its snapshot; the model reads the full history (pinning that the two paths are
independent)"*. That test stays; only its second clause changes, to "the model reads from its own snapshot".

**Migrating aggregate snapshots into the new table is out of scope and should stay out.** It is shipped user data,
keyed by a document id the user's converter round-trips, in a store whose connection the user chose. The only
argument for it is aesthetic. **Recommendation: two stores, one *position concept* (`aggregate_version`), and one
sentence in the documentation saying they are independent.**

---

## Part 11 — Correctness under snapshots

### 11.1 Capture-before-read still holds, and gets stronger

The capture is inside the statement (§3.3) and the snapshot is a CTE of the same statement (§10.2), so the order is
not "capture, then read" but "capture *and* read", one database snapshot. What has to be shown is the new claim:
**the tail is complete relative to the captured counter.**

The proof is two lines, and it needs the single statement.

1. The guarded bump, the event rows and the index rows of any append are written in **one transaction**
   (`DbalTagConditionalAppender::appendEventsWithTagCondition`: `bumpGuarded` → `insertEventRows` →
   `index->insertRows`, all inside the handler's `BEGIN … COMMIT`). So for a reader, `counter = c` and
   "all index rows with `tag_sequence ≤ c` are present" are the same fact.
2. If the reader observes the counter and the index rows in one database snapshot, then reading `tag_sequence > covered`
   returns **exactly** the appends numbered `covered+1 … c`, where `c` is the captured version. Nothing is missing and
   nothing is from the future.

With the reads split across statements on PostgreSQL's READ COMMITTED, (2) weakens to "the tail may contain appends
numbered above `c`" — which is revision 1's benign divergence, now with a snapshot in front of it: the extra appends
are still folded, the decision is still taken on data newer than the capture, and the guarded `UPDATE` still fails.
**Spurious retry, never a missed conflict.** The single statement removes that window; it does not create a new one.

### 11.2 With rows: a competing commit between capture and read

`wallet:w-1` is at 1 000. `PayoutsToday` has a snapshot covering 900. Anna decides; Ben commits a `PayoutMade`
carrying `wallet:w-1` in between.

**PostgreSQL, one statement (option C + snapshot CTE)**

| Anna | Snapshot | Reads |
|---|---|---|
| the statement | *t₀* | `captured wallet:w-1 = 1000`; `snaps.covered_position = 900`; `flags` where `tag_sequence > 900` → sequences 901…1000, 100 rows |
| — | | *Ben commits: `wallet:w-1 → 1001`, one index row at `tag_sequence = 1001`* |
| fold | — | snapshot state + 100 events. Ben is not in it, and Ben's counter is not in the capture |
| guarded bump | current read | `UPDATE … AND version = 1000` → **1 row**. Anna commits at 1001… |

…and Ben's own bump, which ran first, took the counter to 1001, so Anna's `version = 1000` finds **0 rows**. Anna
retries. The order is whichever commits first; the loser retries. Exactly today's behaviour, with a shorter read.

**PostgreSQL, reads split (the multi-stream case, §3.4)**

| Anna | Snapshot | Reads |
|---|---|---|
| capture + snapshot | *t₀* | `1000`, covered `900` |
| — | | *Ben commits, counter → 1001* |
| tail, second stream | *t₁* | `tag_sequence > 900` → **901…1001** — Ben's event is folded |
| guarded bump | current read | `… AND version = 1000` → **0 rows** |

Anna folded one event more than her capture covers, and is rejected for it. **The snapshot changes nothing here:**
the divergence window is between the capture and the read, and the snapshot sits before both.

**MySQL / MariaDB / SQLite** — one transaction snapshot, pinned at the statement: Ben is invisible throughout, the
tail is 901…1000, the guarded bump is a current read and fails. As in §2.2.

### 11.3 Multi-tag AND scope, and the aggregate-backed case

**AND scope.** `PayoutsToday` scoped `wallet:w-1` AND `region:eu`, counters at 1 000 and 4 000, snapshot covering
`(wallet: 900)` — the first *counted* tag (§9.3). Events carrying both tags: `#870` at
`(sequence_wallet 870, sequence_region 3 010)` and `#1 000` at `(1 000, 3 980)`.

| Event | `sequence_wallet` | in tail `> 900`? | `has_wallet AND has_region`? | folded? |
|---|---|---|---|---|
| #870 (both tags) | 870 | no | — | no — covered by the snapshot |
| #950 (wallet only) | 950 | yes | no | no — AND-filtered, as today |
| #1000 (both tags) | 1 000 | yes | yes | **yes** |

`#950` bumped `wallet:w-1` without being in the scope, so `tag_sequence` values are *not* a count of matching events.
That is exactly why the cut is correct: `> covered` is a **time** cut at an append boundary, not a count. The
AND-filter runs after it, unchanged, and the snapshot state covers every matching event with `sequence_wallet ≤ 900`
— which is every matching event committed before the snapshot instant, because a matching event carries `wallet:w-1`
and therefore bumps it.

**Aggregate-backed.** `WalletBalance` over `Wallet w-1`, 1 000 events, snapshot covering `aggregate_version 900`,
counter `aggregate_Wallet:w-1` at (say) 640 appends. Tail is `aggregate_version > 900` → versions 901…1 000. Ben
saves `Wallet w-1` mid-decision: he bumps the counter to 641 and writes versions 1 001–1 003. Anna's captured 640
fails the guarded `UPDATE`; Anna retries. Note the counter and `aggregate_version` are *different scales* — one
append of three events moves the counter by one and the version by three — which is fine, because they are used for
different jobs: the version positions the snapshot, the counter guards the decision.

### 11.4 A stale snapshot, and a snapshot that ran ahead

**Stale costs a longer tail and nothing else.** A snapshot covering 500 when the counter is at 1 000 yields a
500-row tail instead of a 100-row one. The fold is identical — `fill()` applies the same handlers to the same events
in the same order, whether they come from the snapshot or from the tail — so the model instance is bit-for-bit what
a full fold produces. Missing entirely is the limiting case: tail = the whole history, which is today's behaviour.

**A snapshot that ran ahead is the case worth proving, and it is safe on every arrangement.** Suppose the snapshot
store is a *different connection* (option (a) of §9.4) and its writer commits `covered_position = 1000` while the
append that took the tag's counter to 1 000 is not yet visible to the reader.

| Reader | observes |
|---|---|
| capture | `wallet:w-1 = 999` |
| snapshot | `covered_position = 1000` — **ahead of the capture** |
| tail | `tag_sequence > 1000` → empty (row 1 000 is invisible anyway) |
| fold | the snapshot state, which includes an event the capture does not cover |
| guarded bump | `UPDATE … AND version = 999` → **0 rows**, because the row *is* at 1 000 |

**It cannot commit.** The reason is structural, not lucky: the snapshot's scope tag is a counted tag of the model, so
it is in the captured set; `AppendCondition::fromCapturedVersions` carries it into the append condition;
`AppendedTags` puts every condition version into `involved`; and each is bumped guarded. Whatever made the snapshot
run ahead necessarily moved that counter, and the guard is a current read. **A cross-connection snapshot store
produces spurious retries, never a wrong decision** — the same class of answer §2.4 gives for the whole question.

That said, a *guaranteed* conflict is not a good outcome: the handler burns a full attempt every time. So:

> **Invariant, cheap and total: discard any snapshot whose `covered_position` exceeds the captured counter version**
> (for a tag scope; for an aggregate scope, whose tail is empty while the stream's `MAX(aggregate_version)` is
> lower). Fall back to the full fold, log it, and let the decision succeed.

With option (b) — the snapshot table on the event store's connection, read in the same statement — the invariant is
**structurally unreachable**: the snapshot writer's transaction either committed before the reader's snapshot, in
which case the counter moved with it, or after, in which case neither is visible. That is the argument for (b) in
§9.4, and it is the strongest correctness reason this document has found for a single statement.

**Corrupt or undeserializable** → §9.6, full fold, log, self-heal. **Written under a different `fold_shape`** → not
matched by the predicate, full fold, no runtime check at all.

### 11.5 In-memory parity

`EnterpriseInMemoryTagCollaborator::loadByCriteria` captures and walks the index in one pass over PHP arrays inside
one call, so it is already one snapshot (§4.4). A snapshot mirror is an array keyed `(model_class, scope_key)`
holding `[state, coveredPosition, foldShape]`, filtered by the same `sequence > covered` predicate in
`InMemoryTagIndex::eventsMatching`. Two things to get right, both already learned in this codebase:

1. **Store a clone, not the instance.** Part 6.2 item 8 of the fetched-aggregates design records that in-memory
   state-stored aggregates are shared instances, so a lost update was observable only on the Dbal document store.
   A folded model handed out by reference would be mutated by the next fold. The Dbal path serializes, which clones
   by construction; the in-memory path must `clone` explicitly or the parity tests will pass while the semantics
   differ.
2. **Serialize in memory too, or do not claim parity on the converter requirement.** A model with no registered
   `#[MediaTypeConverter]` snapshots happily in memory and fails on Dbal. Either the in-memory store round-trips
   through `ConversionService` as well, or the missing-converter failure is a bootstrap guard rather than a runtime
   one. **Recommendation: a bootstrap guard** — when a model is configured for snapshots, assert a converter exists
   for it, with the same message shape `DecisionModelModule` already uses. Then in-memory can clone and the two
   stores agree.

---

## Part 12 — Cost and benefit, measured

### 12.1 The dataset

One stream table, **30 900 events**: 300 `Wallet` instances, the read instance `w-1` holding **1 000** events and the
other 299 holding 100 each; every event tagged `wallet:<id>`, so `ecotone_tagged_events` holds 30 900 rows and the
read tag holds 1 000 of them. Tables, indexes and column expressions copied from `EventStreamSchema`,
`TaggedEventSchema` and `DocumentStoreTableManager`. Snapshot at `tag_sequence` / `aggregate_version` **900**, so the
tail is 100. Medians of 20 runs per statement, two independent runs per engine, on the compose stack. The throwaway
harness was deleted.

### 12.2 Rows read, and time

| | PostgreSQL 16 | MySQL 8.0 | MariaDB 11.4 |
|---|---|---|---|
| **tag scope, today** — flags (1 000 rows) | 1.02–1.54 ms | 1.51–2.01 ms | 1.85–2.00 ms |
| — events by `no IN (…)` (1 000 rows) | 3.00–4.44 ms | 5.48–6.67 ms | 3.50–4.30 ms |
| — PHP decode + fold, 1 000 events | 1.86–2.10 ms | 1.87 ms | 1.71–1.85 ms |
| **total** | **5.9–8.1 ms** | **8.9–10.6 ms** | **7.1–8.2 ms** |
| **tag scope, snapshot at 900** — snapshot read | 0.05–0.25 ms | 0.09–0.10 ms | 0.08–0.11 ms |
| — flags tail, existing index (100 rows) | 0.48–0.75 ms | 0.79–0.94 ms | 0.52–0.85 ms |
| — events tail by `no IN (…)` (100 rows) | 0.54–0.65 ms | 0.72–0.77 ms | 0.59–0.63 ms |
| — PHP snapshot decode + fold, 100 events | 0.19 ms | 0.19 ms | 0.19 ms |
| **total** | **1.3–1.8 ms** | **1.8–2.0 ms** | **1.4–1.8 ms** |
| **aggregate scope, today** — `loadAggregateEvents` from 1 (1 000 rows) | 2.49–2.65 ms | 2.65–3.45 ms | 2.67–2.69 ms |
| — PHP decode + fold | 1.86–2.10 ms | 1.87 ms | 1.71–1.85 ms |
| **aggregate scope, snapshot at 900** — `fromVersion: 901` (100 rows) | 0.92–0.97 ms | 1.48–1.90 ms | 2.16–2.17 ms |
| — snapshot read + decode + fold | 0.25 ms | 0.29 ms | 0.30 ms |
| **combined statement (option C), no snapshot** — 2 002 rows | 4.68–6.72 ms | 7.52–8.57 ms | 9.24–9.42 ms |
| **combined statement, snapshot as a CTE** — 103 rows | **1.37–1.54 ms** | **1.52–1.54 ms** | **1.07–1.16 ms** |
| **snapshot read + combined statement** — 102 rows | **1.29–1.52 ms** | **1.16–1.34 ms** | **0.78–1.11 ms** |

**Rows: 2 002 → 103.** **Time: four to eight times.** And the saving is proportional to what the snapshot covers,
where everything revision 1 measured is a constant.

### 12.3 Statement count, and whether a new index is needed

Statement count does not change: the snapshot is a CTE branch (one statement, as §10.2) or one extra read (two). The
general form of revision 1, `1 + A_fetched`, becomes `1 + A_fetched` or `2 + A_fetched`. **Snapshots are not a
round-trip optimisation and must not be sold as one** — they are a rows-read optimisation, which is the axis that
scales with history.

**A new index is tempting and is not yet warranted.** `ecotone_tagged_events`'s primary key is
`(tag_name, tag_value, stream_name, event_no)`, so `tag_sequence > :covered` is a *filter* inside the tag's range,
not a range bound: the scan still touches all 1 000 of the tag's index entries and groups 100. An index
`(tag_name, tag_value, tag_sequence, stream_name, event_no)` makes the tail an index-only range scan:

| flags tail, 100 of 1 000 | existing PK | with the extra index |
|---|---|---|
| PostgreSQL | 0.48–0.75 ms | 0.52–0.67 ms |
| MySQL | 0.79–0.94 ms | 0.33–0.44 ms |
| MariaDB | 0.52–0.85 ms | 0.50–0.71 ms |

It helps MySQL and is noise on PostgreSQL and MariaDB — **at 1 000 rows**. The win of a snapshot is the stream-table
join, the transfer and the PHP fold (≈ 5.5 ms of the ≈ 7 ms on PostgreSQL), not the index scan (≈ 0.5 ms).
**Recommendation: ship without it**, and revisit when a tag's index rows reach five or six figures — at which point
it is a `database:setup` addition, not a design change.

### 12.4 The write cost, and where it belongs

Serializing a small model and upserting one row is the `snapshot read` column run backwards: **0.05–0.33 ms**, plus
whatever the user's converter costs. Inline in the handler's transaction — the way aggregate snapshots work today —
that is paid by one command in every N, on the critical path, inside the lock window that determines the conflict
rate. Revision 1's §4.6 measured that the conflict rate is governed by *the length of the whole transaction*, so
lengthening it every Nth command is the one change in this document that could make contention measurably worse.

Asynchronously, after commit, it is off the critical path entirely, and correctness does not care (§9.5).
**Recommendation: after commit, by default.**

### 12.5 Storage, and the workloads

**Storage is the honest cost, and it is unlike aggregate snapshots.** `withSnapshotsFor(Ticket::class, 1)` produces
one document per `Ticket`. `#[DecisionModel(tags: ['wallet'])]` with snapshots produces one row **per tag value per
model class** — the cardinality of the tag, times the number of models scoped on it. A tag with a million values and
two models is two million rows, each holding a serialized object. That is a deliberate opt-in with a pruning story
(`taken_at`, a `database:prune` style command, or a bounded threshold), not a default.

| Benefits | Does not benefit |
|---|---|
| a long-lived tag: a wallet, a subscription, an account — hundreds to thousands of events under one value | a short scope: revision 1's own example read **10** index rows, where the snapshot read alone costs more than the fold it saves |
| an aggregate-backed model over a long-lived aggregate | a tag whose value is high-cardinality and short-lived (one order, three events) — all storage, no saving |
| a model folding one of many event types on a busy tag (the tail is small *and* narrow) | a model whose history is naturally bounded by design — which is what §4.5 of the main spec currently advises instead |

**Break-even is low: tens of events.** On PostgreSQL the marginal cost of one covered event is ≈ 4.4 µs of fetch
plus ≈ 2.0 µs of decode-and-fold plus ≈ 1 µs of index scan ≈ **7 µs**; the snapshot read is 50–250 µs, so it pays
for itself somewhere around **20–35 covered events**. On MySQL, ≈ 10 µs per event against a 90–100 µs read: **around
10**. Ignoring the write cost, which is why the guidance should be "long-lived scopes", not "always on".

---

## Part 13 — The revised proposal

### 13.1 Part 4 revised — the collaborators

Revision 1's §4.2 stands; three additions, and one rule that decides their shape.

- **`DbalDecisionSnapshotStore`** (new, and the only new table owner). Owns `ecotone_decision_snapshots`:
  `findFor(Connection, array $scopes): array` and, separately, `store(Connection, ...)`. It contributes a
  `snapshotsCte(array $scopes): SqlFragment` beside `findFor`, exactly as `DbalTagIndex` contributes `flagsCte` and
  `DbalTagVersionRegister` contributes `capturedCte`. One table, one collaborator, no inline SQL anywhere else.
- **`DbalSingleReadPlan`** (revision 1's only new class) gains one more fragment to compose, and still writes no SQL.
- **`DecisionSnapshotWriter`** (new, open question 12 below): decides *whether* to write after a commit, and writes.
  It is a separate service on a separate path, invoked after the handler's transaction, never from the read.

**CQS, stated because this is where it would be broken.** Reading a snapshot must not write one. The read path —
`DbalTaggedEventReader::loadByCriteria` → `DbalSingleReadPlan` → `DbalDecisionSnapshotStore::snapshotsCte` — is
pure: it returns rows and mutates nothing, not even to record a miss. The threshold arithmetic ("is this scope due a
snapshot?") is evaluated on the **write** side, after the append, from the counter the append already produced. The
temptation to write a snapshot at the end of a read because the fold is right there in memory is exactly the
statelessness rule that was removed from `DbalTagVersionRegister` on 2026-09-28 (main spec, decision log), and it
must not come back through this door.

Naming follows the shipped convention: `Dbal*` for the Dbal implementations, the in-memory mirror as
`InMemoryDecisionSnapshotStore` beside `InMemoryTagIndex`/`InMemoryTagVersionRegister`.

`DecisionModelBatchLoader::load` stays the orchestration of named steps it becomes in §4.3 — it gains no knowledge
of snapshots beyond passing `$snapshot?->state` into `fold()`.

### 13.2 Part 5 revised — the constraints

| Rule | How revision 2 stands |
|---|---|
| **Optimistic concurrency only** | Unchanged. §11.4 shows the snapshot never participates in the guard; the guarded `UPDATE`/`INSERT` is still the sole conflict mechanism |
| **No locking reads** | The added branches are plain `SELECT`s. No `FOR UPDATE`, no `FOR SHARE`, in any SQL in Part 10 |
| **Stateless services** | `DbalDecisionSnapshotStore` holds no per-execution state; the snapshot rows are database state, function-scoped in PHP. `DecisionSnapshotWriter` holds none either — its input is the append's outcome |
| **CQS** | §13.1. The read never writes a snapshot |
| **SQL owned by the table-owning collaborator** | `ecotone_decision_snapshots` → `DbalDecisionSnapshotStore`, and nothing else touches it |
| **Black-box tests** | §13.3. Snapshot *presence* is not asserted through the table; it is asserted behaviourally — identical folds with and without, and a decision that is correct after a model class change |
| **Four engines plus in-memory parity** | Parts 10 and 12 measure PostgreSQL, MySQL and MariaDB; SQLite needs no new syntax (CTEs since 3.8.3, as §3.3). In-memory mirror per §11.5 |
| **Open-core untouched** | Everything is on the Enterprise tag path. `EventSourcedRepositoryAdapter`, `BaseEventSourcingConfiguration` and the document store are **not modified** (§10.5) |
| **No nullable service dependencies** | `DbalDecisionSnapshotStore` is injected unconditionally; whether a model is snapshotted is a runtime lookup, not a nullable collaborator |
| **No schema or protocol change** | **This one changes.** Revision 1 needed no DDL; revision 2 adds one table. It is additive, created by a `DbalTableManager` like `TagTableManager`, gated by the same `database:setup` / `verify-schema` machinery, and an application that does not opt in never creates it |

### 13.3 Part 6 revised — tests

Revision 1's tests 1–11 stand. Added, each behavioural:

12. **A snapshotted model decides identically to an unsnapshotted one** — the same events, the same command, the
    same folded value and the same appended events, with snapshots on and off. The regression net.
13. **A snapshot covering part of the history, then a new event** — fold = snapshot + tail; assert the decision, not
    the row count.
14. **A stale snapshot** — write one, append twenty more events, decide: the same answer as a full fold.
15. **A snapshot under a changed model class** — a model that gains an `#[EventSourcingHandler]` after a snapshot was
    taken folds the **whole** history, not the stale projection. Drives `fold_shape`.
16. **A corrupt snapshot** — an unparseable `state` yields a correct decision and a logged warning, not a failure.
    Mirrors `SnapshotsTest`'s self-healing contract for aggregates.
17. **A competing append between the snapshot read and the commit still conflicts** — `DecisionModelConcurrencyException`,
    nothing appended. Two connections.
18. **A snapshot ahead of the captured counter** — forced by writing a snapshot with a `covered_position` above the
    counter: the decision is correct (full fold), not a conflict. Drives §11.4's invariant.
19. **A multi-tag AND model with a filter-only first tag** — the position tag is the first *counted* one; the fold is
    correct. Drives §9.3's precision.
20. **An aggregate-backed model whose aggregate also has `withSnapshotsFor`** — both paths work and neither reads the
    other's snapshot. This is test 18 of `2026-09-28-dcb-aggregate-full-tag-design.md`, updated.
21. **In-memory parity** for 12–20 that do not need two connections, including that a folded model handed out twice
    is not the same mutable instance (§11.5).

Still not tested, by the 2026-09-27 rule: statement counts and rows read. Part 12's numbers are review-protected.

### 13.4 Part 7 revised — OQ5's new answer, and the new questions

**OQ5 (replaces revision 1's answer) — should the `#[Fetch]`-ed aggregate be folded into the statement?**
Revision 1 said no because it would reimplement repository loading. With snapshots on both sides that reason is
wrong: the *shape* is identical (§10.4). The reason that survives is narrower — `AllAggregateRepository::findBy()`
dispatches over **user-supplied** `EventSourcedRepository` implementations, state-stored aggregates with no events at
all, cross-connection aggregates and document-store snapshots, so a folded read would have to decide per class
whether it may bypass the user's repository. *Recommendation: **not a replacement, a hand-off.** The batch loader
folds the fetched aggregate in the same statement and leaves the instance in `DecisionModelLoadedState`;
`FetchAggregateConverter` uses it when present and falls back to `AllAggregateRepository` otherwise, with the
eligibility decided at bootstrap so the read shape is fixed per handler. The converter stays, with one guard clause
added. Build it last (§13.6), and only if the round trip is worth the branch.*

**OQ8 — opt-in per model class, or on the DCB extension object?** Storage is per tag *value* (§12.5), so "on for
everything" is not defensible. Three shapes: a parameter on the attribute
(`#[DecisionModel(tags: ['wallet'], snapshot: 100)]`), a list on `DynamicConsistencyBoundaryConfiguration`
(`->withDecisionModelSnapshots([PayoutsToday::class => 100])`, mirroring `withSnapshotsFor`), or both.
*Recommendation: **the extension object**, mirroring `BaseEventSourcingConfiguration::withSnapshotsFor` exactly —
class, threshold, store reference. It keeps a performance decision out of the domain class, it is the shape users
already know from aggregate snapshots, and it can be changed per environment. Add the attribute parameter later if
users ask; it cannot be removed once added.*

**OQ9 — one snapshot store, or the aggregate's document store reused?** §9.4 and §10.5.
*Recommendation: **a new table on the event store's connection**, and the aggregate's document-store snapshots left
exactly where they are. Two stores, one position concept, one documented sentence that they are independent.*

**OQ10 — the extra index on `(tag_name, tag_value, tag_sequence, …)`?** §12.3: it helps MySQL and is noise elsewhere
at 1 000 rows, and the win is the join and the fold, not the scan. *Recommendation: **no, not in the first cut.***

**OQ11 — snapshot as a CTE of the one statement, or a preceding read?** §10.2: within noise of each other.
*Recommendation: **the CTE**, because it makes §11.4's "snapshot ahead of the capture" structurally impossible
rather than a runtime check — which is the first correctness argument this research has found for the single
statement, and worth more than the milliseconds.*

**OQ12 — when is the snapshot written?** §9.5, §12.4. Inline every-N is what aggregates do and it lengthens the
transaction that governs the conflict rate. *Recommendation: **after commit, off the critical path**, with inline
available as an option. The tail is exact by position, so lateness is only a cost.*

**OQ13 — what does a model class need to be snapshottable?** A registered converter, as aggregates need
(`BasketMediaTypeConverter`). *Recommendation: **a bootstrap guard** when a model is configured for snapshots, so
the failure is a `ConfigurationException` naming the model and the converter, not a runtime log — and so the
in-memory and Dbal stores agree (§11.5).*

**OQ1, OQ2, OQ3, OQ4, OQ6, OQ7 are unchanged.**

### 13.5 Part 8 revised — what the summary now says

Revision 1's summary stands, with one sentence added and one corrected.

*Added:* snapshots give the decision read the only saving that grows with history. At 1 000 events under one tag the
combined read is 6.7 → 1.5 ms on PostgreSQL, 8.6 → 1.5 ms on MySQL, 9.2 → 1.2 ms on MariaDB, and 2 002 rows → 103;
option C's ~2.4 ms is flat. Nothing new has to be invented to get it: `tag_sequence` is already the tag's counter
version, stamped identically on every event of an append, and `fromVersion` is already a SQL argument.

*Corrected:* revision 1's OQ5 gave the wrong reason. Folding `#[Fetch]` is not blocked by the mechanism — with
snapshots the two loads are the same steps — but by `AllAggregateRepository` being a dispatch point over user
repositories and state-stored aggregates. The right move is a hand-off, not a replacement.

### 13.6 Recommended implementation order

| # | Unit | Why here |
|---|---|---|
| 1 | **Delete the `tableExists` probes** from the decision read path (OQ2) | Two thirds of revision 1's measured win, a few lines, independent of everything else, and it turns a silent wrong decision into the `ConfigurationException` the design already uses. Lands whatever else is decided |
| 2 | **Guard a filter-only-scoped `#[DecisionBoundary]`** (OQ1) | The only genuine correctness gap this research found, unrelated to either question, small and self-contained |
| 3 | **Option C** — the combined statement with the capture CTE | Before snapshots, because §11.4's invariant becomes structural rather than a runtime check only when the counters and the snapshot position are read in one database snapshot. It is also the smaller change and it is fully specified by revision 1 |
| 4 | **Aggregate-scope snapshots** | Cheapest first: the position is `aggregate_version`, the push-down is `fromVersion`, both already exist and are already tested. It needs the new table, the writer, the `fold_shape` and the configuration — every piece tag scope will reuse — against the simplest position |
| 5 | **Tag-scope snapshots** | Adds only the position rule (§9.3, including the first-*counted*-tag precision) and the `tag_sequence > :covered` predicate. Everything else is already standing from 4 |
| 6 | **The `#[Fetch]` hand-off** (OQ5), gated | Last, and optional. It buys one round trip per fetched aggregate and costs a bootstrap-decided branch in a converter that is currently branch-free. Build it only if step 3 has shipped and the round trip is still wanted |

**If only one thing is built, build 1.** If only one *design* is built, the ordering above is 3-then-4-then-5 and
not 4-then-3: option C without snapshots is a constant-factor improvement that helps every handler, snapshots without
option C help only long scopes and need the §11.4 check written by hand. **If the maintainer wants the largest
measured effect, it is 4 and 5** — four to eight times on a 1 000-event scope, against option C's flat 2.4 ms — and
the honest way to present that is: option C makes every decision a little faster; snapshots stop long-lived tags and
aggregates from getting slower forever, which is the bound §4.5 of the main spec currently addresses with tag-design
advice.

---

## Part 14 — Outcome (2026-09-29)

Built as `implement-decision-model-snapshots`, on the maintainer's decisions D1–D9 of the same day, which override
this document wherever they differ. Shipped in four commits, one per unit; the main spec records it as §4.13.

### 14.1 What shipped, against what Part 13 proposed

| Part 13 recommendation | What shipped | Why it changed |
|---|---|---|
| §9.4 (b): a new `ecotone_decision_snapshots` table on the event store's connection | **The document store**, collection `decision_model_snapshots_<Model>`, configured by `DynamicConsistencyBoundaryConfiguration::withSnapshotsFor()` exactly as aggregate snapshots are | D1. Reuses a store users already configure and operate; no DDL, no setup feature, no `verify-schema` entry |
| §9.2: `covered_position` as a column | A framework envelope `{state, covered_position, fold_shape}` stored as one document | D2. Same effect — the position stays out of the user's serialization — through the interface the document store already has |
| §9.5 / OQ12: after commit, on its own channel | **Inline after the append**, every `thresholdTrigger` positions since the covered one — the same arithmetic as `EventSourcedRepositoryAdapter::save()` | D4. One mechanism, no consumer to run. CQS kept: the read computes the candidate, the write path stores it |
| OQ11: the snapshot as a CTE of one combined statement | Deferred with the rest of option C | D9 |
| OQ10: no extra index | No extra index | Unchanged |
| OQ13: a bootstrap guard for the converter | **Bootstrap** guard for "not a `#[DecisionModel]`"; **first-use** `ConfigurationException` for the missing conversion and for an unresolvable document store reference | A static check is impossible: `#[MediaTypeConverter]` decides at runtime through `matches()`, and `Configuration` exposes no read-back of registered converters. Confirmed with the maintainer before step 1 |
| §10.5: aggregate snapshots left exactly where they are | Aggregate snapshots gained the **same shape-based invalidation**, through a sibling marker document | D6. The stores stay separate and independent, as §10.5 argues; only the invalidation rule is now shared |

### 14.2 What the design did not anticipate

**One read serves several readers, and a bound alone is not enough.** §10.2 pushes `tag_sequence > covered` into the
index read and stops there. But `DecisionModelBatchLoader` ORs every model, `#[DecisionBoundary]` and `#[Fetch]`
capture of a handler into **one** `loadByCriteria()`, and then re-filters per model in PHP with
`TagResolver::eventsMatching()`, which knows tags and event types — not sequences. So any other branch touching the
same tag (a second model without a snapshot, a boundary, a fetched aggregate's capture) widens the read back to the
whole scope, and the PHP filter re-admits exactly the events the snapshot had already folded. The bound has to be
enforced in *both* places or the snapshotted model double-folds its own history.

What shipped: `EventCriteria::afterTagSequence()` carries the bound per branch, and both readers stamp the tag
sequences an event was matched by onto the event, so the PHP filter can apply the same cut. The stamp is internal —
stripped alongside `DecisionModelLoadedState` and the aggregate keys in `MessageHeaders::unsetAggregateKeys()`, with
a black-box test proving it reaches neither persisted metadata nor a published event. Pushing the bound into SQL is
also only safe for a tag key that *every* branch uses as its position tag: elsewhere the key decides whether an
event carries all of a branch's tags, and cutting its rows would drop matching events. `TagResolver` computes that.

**§9.3's precision was a live bug, not only a hazard.** `DbalTaggedEventReader::matchingEvents()` and
`InMemoryTagIndex::eventsMatching()` both positioned a branch by `tags()[0]`. Fixing it to the first *counted* tag
broke a shipped test until the bound check learned to distinguish "no bound" from "sequence 0": a criterion scoped
only by filter-only tags legitimately matches events whose stamped sequence is 0.

**"Snapshot ahead" needs two different proofs, because the two scopes count differently.** For a tag scope the
captured counter comes back from the same read, so the check is free — `covered > captured` means ignore, and the
model is folded from an unbounded re-read. For an aggregate scope the counter counts *appends* while the position
counts *versions* (§11.3), so there is nothing to compare against. What shipped reads from `fromVersion: covered`
rather than `covered + 1`: the event the snapshot last folded comes back with the tail and anchors it. One extra
row, no extra statement, and the check is exact.

**A merged read can advance a position past the model's own events.** Two models on one aggregate instance share
one read, narrowed to the union of their handled event types. The covered position is therefore computed only over
events the model itself folds, matched by payload class, or a later read narrowed to that one model would lose its
anchor.

**The aggregate envelope had to become a sibling marker.** D6 offers "an envelope or a sibling field". An envelope
turned out to change what `withSnapshotsFor` stores — five shipped tests assert the stored document *is* the
aggregate — and would newly require a converter for in-memory snapshots, which are lenient today. The sibling
marker document keeps the stored format byte-for-byte and still makes a marker-less document stale. It costs one
extra document read per snapshotted aggregate load; swapping it for the envelope later is a contained change.

### 14.3 Coverage

Tests 12–21 of §13.3 landed as behaviour tests, in-memory and against PostgreSQL, MySQL, MariaDB and SQLite:
identical decisions with and without snapshots; a partial-history snapshot plus a new event; a stale snapshot; a
snapshot under a changed fold shape; a corrupt snapshot; a snapshot ahead of its scope; a competing append between
the snapshot read and the commit still conflicting with nothing appended (two connections); a multi-tag model whose
first tag is filter-only; a cross-stream fold over a snapshot; a snapshot surviving an application restart; an
aggregate with `withSnapshotsFor` beside an aggregate-backed model, each reading its own snapshot; and a
marker-less aggregate snapshot ignored and replaced. Statement counts and rows read are not asserted, by the
2026-09-27 rule; Part 12's numbers stay review-protected.
