# Reading the aggregate decision model in the same statement as the event tags

*Research, 2026-09-29. Base `dgafka/ecotone-2-0-dcb-design` @ `8839179a`. Proposal for approval; nothing is
implemented.*

**The maintainer's question, verbatim:** *"Can we ensure that aggregate decision model is loaded via same sql as
event tags, just to ensure that we snapshot at the same time. Let's research: is it possible and how would it look
like."*

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
| **No schema or protocol change** | No DDL, no migration, no backfill, no new column, no configuration. |

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

**OQ5 — Should the `#[Fetch]`-ed aggregate be folded into the statement too?** It would mean reimplementing
repository loading — snapshots, state-stored aggregates, the full event set — inside the tag reader, for an aggregate
whose counter is already captured in the same statement. *Recommendation: **no**. Keep it on
`AllAggregateRepository::findBy()`. State the reason in the design document so it does not get re-asked.*

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
