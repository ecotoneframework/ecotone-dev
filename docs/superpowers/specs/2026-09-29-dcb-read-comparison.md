# The three deferred read optimisations, measured on the shipped code

*Research. 2026-09-29, base `dgafka/ecotone-2-0-dcb-design` @ `c75664b8` — after the probe deletion, the filter-only
boundary guard and decision-model snapshots shipped. Measurement only; nothing is implemented and nothing is
proposed for implementation without a further decision.*

**The maintainer's question.** Three things were deferred on 2026-09-29 for a comparison *"once all changes are
done"*: **(a)** the combined single statement with the capture CTE — option C of
`2026-09-29-dcb-single-read-snapshot-design.md` revision 1 Part 3.3, extended in revision 2 §10.2; **(b)** the
`#[Fetch]` hand-off of revision 2 §10.4; **(c)** the extra index
`(tag_name, tag_value, tag_sequence, stream_name, event_no)` of revision 2 §12.3. Build or skip, for each.

---

## Part 0 — The answer in one paragraph

**Skip (a), skip (b), build (c) but only as an opt-in, and delete one more probe on the way.** The comparison is
decided by a number neither revision measured: **on the shipped code the decision read's cost is not SQL.** For the
brief's headline shape — one tag-scoped model and one aggregate-backed model over a 1 000-event scope — the read
phase is 8.6–12.1 ms of SQL inside **38–46 ms of wall clock**; the rest is PHP deserializing and folding a thousand
events, 12–18 µs each per model. Option C is measured against that: it collapses 3–7 statements into 1 and halves
the rows transferred, and it changes the *whole command* by **−7% to +15%, within ±4% in seventeen of the
twenty-four engine/shape cells measured, and negative in ten of them** (Part 3.3). Under eight-way contention it
moves nothing: the conflict rate is 79–86% with and without it, on the three server engines, exactly as revision 1
predicted — contention is set by the number of contenders, not by the shape of the read (Part 3.5). It also makes the two shapes that matter for
adoption *slower*: a small scope (ten events) and an aggregate-only handler both lose to today's statements on
PostgreSQL. **The `#[Fetch]` hand-off is worth even less than revision 2 estimated, and for a reason worth fixing
separately:** the converter's whole cost today is 1.0–1.8 ms, of which **half to two thirds, on every engine, is an
`information_schema` existence probe that `DbalEventStore::loadAggregateEvents` still issues on every call** — the
probe deletion of 2026-09-29 reached `loadDecisionModelAggregateEvents` and not its sibling. Folding the fetched
aggregate into the combined statement costs as much as it saves at 100 events (Part 4.3). **The index is the one
item whose case grows**: at 1 000 index rows per tag it is noise, as revision 2 said; at 10 000 it is 4–5×; at
**100 000 it is 11–35 ms down to 0.37–0.50 ms** on PostgreSQL, MySQL and MariaDB. It buys nothing on SQLite, whose
planner ignores it, and it costs 37% more table bytes on PostgreSQL and 81% on MySQL and MariaDB. That is an
opt-in for a long tag, not a default. **Two defects surfaced while measuring and are worth more than any of the
three**: the probe above, and an empty extra page that `selectEvents` issues for every aggregate whose event count
is an exact multiple of `loadBatchSize` (Part 2.3).

---

## Part 1 — Method and dataset

### 1.1 What is measured, and against what

Everything below is measured against the code at `c75664b8`, through **two harnesses**, both throwaway and both
deleted after the run.

- **The shipped-code harness** boots `EcotoneLite::bootstrapFlowTestingWithEventStore` with a real DBAL connection
  whose `wrapperClass` is a `Doctrine\DBAL\Connection` subclass recording every `executeQuery`, `executeStatement`
  and transaction boundary in order, with its elapsed time. The handler body calls a marker, so the **read phase**
  is everything before the handler is invoked and the **append phase** everything after. Each measured command runs
  inside an outer transaction that is rolled back, so every one of the twenty repetitions sees byte-for-byte the
  same database. This produces Part 2.
- **The raw-SQL harness** replays the same statements, and option C's combined statement, on a plain connection with
  one clock, so that today and option C are never compared across two different measurement paths. This produces
  Parts 3, 4 and 5.

`xdebug` is loaded in the compose image with `xdebug.mode=debug`, which inflates PHP by three to five times. Every
number below was taken with `php -d xdebug.mode=off`. This is worth stating because it is the difference between
"the fold costs 68 ms" and "the fold costs 12 ms", and the conclusion depends on which.

**Clocks.** For PostgreSQL, MySQL and MariaDB the per-statement number is `executeQuery` alone; with buffered PDO
those drivers transfer the whole result set during execution, so this includes the network. SQLite is lazy, so for
SQLite the per-statement number is execute *plus* `fetchAllAssociative`. Where a figure says **wall**, it is the
elapsed time of the whole phase, SQL and PHP together, measured from the gateway call to the handler marker.

**Medians of 20, two independent runs per engine**, reported as a range across the two runs. Where a range is wide
the spread is real: PostgreSQL and MySQL under this compose stack vary by 30–60% run to run on statements that
return a thousand rows, and that variance is larger than most of what option C is being asked to save.

### 1.2 Engines

| | version | DSN |
|---|---|---|
| PostgreSQL | 16.1 | `pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16` |
| MySQL | 8.0 | `mysql://ecotone:secret@database-mysql:3306/ecotone?serverVersion=8.0` |
| MariaDB | **11.4.12** | `mysql://ecotone:secret@database-mariadb:3306/ecotone?serverVersion=11.4.12-MariaDB` |
| SQLite | 3 (bundled) | `sqlite:////tmp/research.db` |

*The brief named the MariaDB DSN `serverVersion=mariadb-10.11`. The container on this stack runs 11.4.12 and DBAL
4.5 rejects that version string, so the compose file's own form was used with the server's real version. The engine
measured is MariaDB 11.4.12, not 10.11.*

### 1.3 The dataset

Revision 2 §12.1's shape, rebuilt on the shipped schema — tables and indexes created by the real
`EventStreamSchema`, `TaggedEventSchema` and `DocumentStoreTableManager`, rows written by bulk `INSERT` in the exact
format a real append produces.

| | rows |
|---|---|
| `research_stream` | 141 410 events |
| — `w-1`, the read instance | **1 000** events, `_aggregate_version` 1…1 000, each tagged `wallet:w-1` |
| — `w-2` … `w-300` | 100 events each (29 900) |
| — `w-small` | **10** events — the small-scope variant |
| — `w-ms` | 500 events here and 500 in `research_branch_stream` — the multi-stream scope, 1 000 `tag_sequence` values |
| — `w-10k`, `w-100k` | 10 000 and 100 000 events, for Part 5 only |
| `research_branch_stream` | 500 events |
| `research_customer_stream` | 100 events of one `Customer` — the `#[Fetch]`-ed aggregate |
| `ecotone_tagged_events` | 141 910 index rows |
| `ecotone_tag_versions` | 610 counters |
| `ecotone_document_store` | the two decision-model snapshots, `covered_position` **900** |

`VACUUM ANALYZE` / `ANALYZE TABLE` / `ANALYZE` after seeding, on every engine.

### 1.4 The six handler shapes

One command handler each, every one returning one tagged event so that the append is measured too.

| | injects | scope |
|---|---|---|
| **H1** | `SpentToday` — `#[DecisionModel(tags: ['wallet'])]` | `wallet:w-1`, 1 000 events |
| **H2** | `WalletBalance` — `#[DecisionModel(aggregate: Wallet::class)]` | `ResearchWallet:w-1`, 1 000 events |
| **H3** | both | both |
| **H4** | both, plus `#[Fetch('payload.customerId')] Customer $customer` | both, plus a 100-event aggregate |
| **H5** | H3, with `withSnapshotsFor([SpentToday::class, WalletBalance::class], 100)` | both, snapshotted at 900 |
| **H6** | `SpentEverywhere` — one tag, two streams | `wallet:w-ms`, 1 000 events over two streams |

`H1small`, `H2small` and `H3small` are H1, H2 and H3 against `w-small`: ten events, the shape where a snapshot
would never be configured and where every constant cost is visible.

---

## Part 2 — The shipped baseline

### 2.1 The statement log, per shape

Recorded on PostgreSQL; **the order and the shape are identical on all four engines**, only identifier quoting and
the aggregate column expressions differ. Rows are the rows the statement actually returned.

**H1 — one tag-scoped model. Three statements.**

| # | who | statement | rows |
|---|---|---|---|
| 1 | `DbalTagVersionRegister::currentVersions` | `SELECT tag_name, tag_value, version FROM ecotone_tag_versions WHERE (tag_name = ? AND tag_value = ?)` | 1 |
| 2 | `DbalTagIndex::flagsFor` | `SELECT stream_name, event_no, MAX(CASE …) AS sequence_0, MAX(CASE …) AS has_0 FROM ecotone_tagged_events WHERE (tag_name = ? AND tag_value = ?) GROUP BY stream_name, event_no` | 1 000 |
| 3 | `DbalEventStore::loadEventsByNumbers` | `SELECT no, event_name, payload, metadata FROM research_stream WHERE no IN (?×1000)` | 1 000 |

**H2 — one aggregate-backed model. Four statements, and one of them returns nothing.**

| # | statement | rows |
|---|---|---|
| 1 | capture, one pair (`aggregate_ResearchWallet:w-1`) | 1 |
| 2 | `flagsFor` for the same pair — **the aggregate leaf matches no index row, by design** | 0 |
| 3 | `loadDecisionModelAggregateEvents` → `selectEvents`, `… AND event_name IN (?) AND no >= 1 ORDER BY no LIMIT 1000` | 1 000 |
| 4 | **the same statement again at `no >= 1001`** | **0** |

**H3 — both. Five statements:** H1's three (capture now carrying two pairs) plus H2's two aggregate pages.

**H4 — H3 plus one `#[Fetch]`. Seven statements:** H3's five, then

| # | who | statement | rows |
|---|---|---|---|
| 6 | `EventStreamSchema::tableExists`, inside `DbalEventStore::loadAggregateEvents` | `SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ? AND table_schema = ANY (current_schemas(false))` | 1 |
| 7 | `loadAggregateEvents` → `selectEvents`, no `event_name` narrowing | 100 |

**H5 — H3 with snapshots on both models. Six statements:**

| # | statement | rows |
|---|---|---|
| 1 | `SELECT document, document_type FROM ecotone_document_store WHERE collection = ? AND document_id = ? LIMIT 1` — `decision_model_snapshots_…SpentToday`, `wallet\|w-1` | 1 |
| 2 | capture, two pairs | 2 |
| 3 | `flagsFor` **with `AND tag_sequence > 900`** pushed down | 100 |
| 4 | events by `no IN (?×100)` | 100 |
| 5 | the second snapshot document — `…WalletBalance`, `ResearchWallet\|w-1` | 1 |
| 6 | the aggregate tail, `_aggregate_version >= 900` — **from the covered position, not one past it**, so the anchor event comes back | 101 |

**H6 — one tag, two streams. Four statements:** capture, `flagsFor` (1 000 rows spanning two `stream_name` values),
then **one `no IN (…)` per stream** — 500 rows each.

**Appends.** H1 three statements (`UPDATE ecotone_tag_versions`, `INSERT` event, `INSERT` index row); H3 four (two
counters); H4 five (three counters); H6 three; **H2 five**, because the handler's returned event carries
`wallet:w-1`, a tag the aggregate-scoped read never captured, so the append path issues its own
`currentVersions` read before bumping; **H5 six**, the four of H3 plus two `UPDATE ecotone_document_store` — the
snapshots are written inline, inside the handler's transaction, before `COMMIT`.

**Probes that are not in the steady state.** On the first command of a process the append path issues three
`information_schema` probes (the stream table and the two tag tables) and, when a document store is configured, the
document store issues its own three-statement `tablesExist` (`SELECT CURRENT_DATABASE()`, a listing of
`information_schema.tables`, `SELECT current_schema()`) costing 1.3 ms on PostgreSQL. Both are cached per
connection afterwards and appear in no later command. **The `#[Fetch]` probe of H4 is not one of these** — it is
inside `loadAggregateEvents` and runs on **every** call.

### 2.2 Read phase, per engine

Medians of 20, the two runs shown as a range. **SQL** is the sum of the statement times; **wall** is the whole read
phase including PHP deserialization and folding.

| shape | statements | PostgreSQL SQL | MySQL SQL | MariaDB SQL | SQLite SQL |
|---|---|---|---|---|---|
| H1 | 3 | 4.17–6.04 | 4.31–5.22 | 4.06–4.12 | 0.69–0.98 |
| H2 | 4 | 3.18–3.87 | 5.16–5.74 | 3.03–3.16 | 0.19–0.27 |
| H3 | 5 | 9.79–10.53 | 10.86–12.08 | 8.62–9.36 | 1.25–1.64 |
| H4 | 7 | 11.43–12.60 | 13.89–14.24 | 8.07–10.35 | 1.36–1.38 |
| H5 | 6 | 3.06–4.01 | 2.52–2.78 | 2.41–3.06 | 1.07 |
| H6 | 4 | 5.60–6.27 | 5.92–6.07 | 4.83–5.08 | 0.72–0.73 |
| H1small | 3 | 0.69–0.70 | 0.59–0.71 | 0.34–0.44 | 0.11–0.15 |
| H2small | 3 | 0.73–0.86 | 0.52–0.92 | 0.45–0.57 | 0.12–0.17 |
| H3small | 4 | 1.02–1.50 | 1.02–1.20 | 0.88–1.01 | 0.25 |

| shape | PostgreSQL wall | MySQL wall | MariaDB wall | SQLite wall |
|---|---|---|---|---|
| H1 | 16.1–25.8 | 16.0–22.5 | 17.7–18.0 | 13.1–18.6 |
| H2 | 17.3–18.8 | 18.1–18.7 | 13.7–17.6 | 8.9–12.6 |
| H3 | **45.0–46.5** | 38.1–46.0 | 41.1–42.9 | 22.8–30.1 |
| H4 | 45.7–47.3 | 48.6–50.1 | 37.8–46.2 | 24.8 |
| H5 | **8.0–9.5** | 5.6–6.0 | 5.7–8.7 | 3.7–3.8 |
| H6 | 29.8–31.7 | 29.2 | 27.9–28.8 | 15.1 |
| H1small | 1.3–1.4 | 1.2–1.5 | 0.7–0.9 | 0.4–0.6 |
| H3small | 1.8–2.6 | 1.7–2.0 | 1.5–2.0 | 0.9 |

**This table is the finding the rest of the document is measured against.** H3 spends 9–12 ms in the database and
38–46 ms in the read phase. The difference is PHP: `DbalEventStore::convertToEvent` per row, `MatchedEvents` per
flag, `TagResolver::eventsMatching` re-deriving each event's tags from its payload object, and
`EventSourcingHandlerExecutor::fill` — **12–18 µs per event per model** — H1 is 12 ms of PHP for one
thousand-event fold, H3 is 35 ms for two. H5 shows what removing them is worth: the same handler, the same two models, 8.0–9.5 ms instead of
45.0–46.5 ms, because the snapshot cut the fold to a hundred events. **The shipped snapshots already took the
saving that grows with history. What is left for option C is the constant.**

### 2.3 Two defects the measurement found

**(i) `loadAggregateEvents` still probes `information_schema`, once per call.** `DbalEventStore` line 343:

```php
if (! $schema->tableExists($connection, $tableName)) {
    return [];
}
```

The 2026-09-29 unit deleted the probe from `loadDecisionModelAggregateEvents`, which the decision path uses, and
replaced it with a `TableNotFoundException` catch that raises the `ConfigurationException`. `loadAggregateEvents` —
the path `EventSourcingRepository::findBy()` takes, and therefore the path every `#[Fetch]`-ed aggregate, every
event-sourced aggregate command handler and every `Repository::getFor()` takes — still has it. Measured, per call:
**0.92–1.11 ms on PostgreSQL**, 1.06–1.27 ms on MySQL, 0.52–0.57 ms on MariaDB, 0.05 ms on SQLite. Beside the read it guards, it is
**0.92–1.11 ms against 0.54–0.73 ms on PostgreSQL, 1.06–1.27 against 0.46–0.62 on MySQL, 0.52–0.57 against
0.44–0.53 on MariaDB and 0.05 against 0.05 on SQLite** — half to two thirds of the whole cost of loading a
100-event aggregate, on every engine. And it is paid by every aggregate load in the framework, not only on the
decision path.

**(ii) `selectEvents` issues an empty extra page whenever the row count is an exact multiple of `loadBatchSize`.**
The paging loop breaks on `count($rows) < $limit`; at exactly 1 000 rows with `loadBatchSize` 1 000 it loops once
more and the second statement returns nothing. Measured at **0.46–0.57 ms on PostgreSQL** for H2 and H3. It is a
boundary case, not a systematic cost — but it is one statement and half a millisecond, which is the same order as
everything option C is being asked to save.

Neither is in scope for this comparison. Both are cheaper than any of the three items and both are recorded here
so the decision is made against the code as it will be, not as it is.

### 2.4 Which statements can see a commit the earlier ones did not

Measured with two connections on the shipped tables: A opens a transaction and captures a counter; B then commits a
counter bump **and** an index row; A re-reads both and then runs its guarded `UPDATE`.

| engine | isolation | A's re-read of the counter | A's re-read of the index | A's guarded `UPDATE` |
|---|---|---|---|---|
| PostgreSQL 16 | `read committed` | **2** — sees B's commit | **1 row** — sees it | **0 rows → conflict** |
| MySQL 8.0 | `REPEATABLE-READ` | 1 | 0 rows | **0 rows → conflict** |
| MariaDB 11.4 | `REPEATABLE-READ` | 1 | 0 rows | **0 rows → conflict** |
| SQLite 3 | deferred read transaction | 1 | 0 rows | 1 row — B could not commit at all (`database is locked`) |

Revision 1 Part 1.4's table stands unchanged on the shipped code. **On PostgreSQL every statement of the read phase
has its own snapshot**, so in H1 statements 2 and 3 may each be newer than statement 1, in H3 the aggregate pages
may be newer than the tag pages, and in H5 the capture may be newer than the snapshot document read. On the InnoDB
pair and SQLite the whole read phase is served from the snapshot the first statement pinned. In every case the
guarded `UPDATE` is a *current* read and fails, so a diverging read produces a spurious retry, never a wrong
decision.

**One thing revision 2 could not say, because the store had not been chosen yet.** The shipped order reads the
snapshot document **before** the capture (H5 statements 1 and 5 versus 2). With the document store on the event
store's connection — the default, `CachedConnectionFactory` hands back the same `Connection` — the snapshot and
the counter are written in one transaction and commit together, so a reader sees both or neither and
`covered ≤ captured` holds by construction. The `runsAheadOfTheCapture` guard in `DecisionModelBatchLoader` is
therefore dead code for the default configuration and live only for a document store on a different connection,
which is exactly what its own comment says. Option C's CTE would make it structurally impossible rather than
guarded; that is worth one sentence in Part 6, and it is not worth the change on its own.

### 2.5 The append

| shape | statements | PostgreSQL | MySQL | MariaDB | SQLite |
|---|---|---|---|---|---|
| H1 | 3 | 0.99–1.30 | 0.93–1.16 | 0.88 | 0.32–0.41 |
| H2 | 5 | 1.45–1.47 | 1.39–1.53 | 1.07–1.08 | 0.28–0.40 |
| H3 | 4 | 1.36 | 1.44–1.62 | 1.13–1.52 | 0.29–0.38 |
| H4 | 5 | 1.53–1.66 | 1.59–1.62 | 0.96–0.99 | 0.29–0.32 |
| H5 | 6 | 1.29–2.10 | 1.17–1.35 | 1.11–1.41 | 0.27–0.28 |
| H6 | 3 | 1.10–1.24 | 1.14–1.39 | 1.12–1.31 | 0.34–0.35 |

Wall clock, 1.4–3.1 ms on the server engines and 0.6–0.8 ms on SQLite. **The inline snapshot write costs 0.2–0.8 ms
on top of H3's append** (H5 against H3), paid by one command in every `thresholdTrigger` — here every command,
because the tail is 100 and the threshold is 100. That is the cost D4 accepted and it is small, but it is inside the
transaction that governs the conflict rate, which is what revision 2 §12.4 warned about; Part 3.5 measures whether
it shows.

---

## Part 3 — Option C, the combined statement

### 3.1 The statement, as built for the shipped schema

Revision 1 §3.3's shape, with revision 2 §10.2's snapshot branch reading `ecotone_document_store` — because that,
and not a new `ecotone_decision_snapshots` table, is where the shipped snapshots live. PostgreSQL's dialect:

```sql
WITH captured AS (
    SELECT tag_name, tag_value, version FROM "ecotone_tag_versions"
    WHERE (tag_name = ? AND tag_value = ?) OR (tag_name = ? AND tag_value = ?)
), snaps AS (
    SELECT collection, document_id, document,
           CAST(document->>'covered_position' AS BIGINT) AS covered_position
    FROM "ecotone_document_store"
    WHERE (collection = ? AND document_id = ?) OR (collection = ? AND document_id = ?)
), flags AS (
    SELECT stream_name, event_no,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS sequence_0,
           MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END)     AS has_0
    FROM "ecotone_tagged_events"
    WHERE (tag_name = ? AND tag_value = ?
           AND tag_sequence > COALESCE((SELECT covered_position FROM snaps WHERE collection = ? AND document_id = ?), 0))
    GROUP BY stream_name, event_no
)
SELECT 'version' AS branch, tag_name, tag_value, version AS position,
       NULL::bigint AS no, NULL::varchar AS event_name, NULL::json AS payload, NULL::jsonb AS metadata,
       NULL::bigint, NULL::bigint                                            FROM captured
UNION ALL
SELECT 'snapshot', collection, document_id, covered_position, NULL::bigint,
       CAST(document AS VARCHAR), NULL::json, NULL::jsonb, NULL::bigint, NULL::bigint   FROM snaps
UNION ALL
SELECT 'tag', NULL::varchar, NULL::varchar, NULL::bigint, s.no, s.event_name, s.payload, s.metadata,
       f.sequence_0, f.has_0    FROM flags f JOIN "research_stream" s ON s.no = f.event_no WHERE f.stream_name = ?
UNION ALL
SELECT 'aggregate', NULL::varchar, NULL::varchar, NULL::bigint, s.no, s.event_name, s.payload, s.metadata,
       NULL::bigint, NULL::bigint
FROM "research_stream" s
WHERE metadata->>'_aggregate_type' = ? AND metadata->>'_aggregate_id' = ?
  AND CAST(metadata->>'_aggregate_version' AS BIGINT) >= ? AND event_name IN (?)
```

Dialect differences are the ones revision 1 named: PostgreSQL needs the `NULL` casts, MySQL, MariaDB and SQLite do
not; identifiers are backticked on the MySQL pair; the aggregate predicate is the generated `aggregate_type` /
`aggregate_id` / `aggregate_version` columns on MySQL and MariaDB and `json_extract` on SQLite. **One thing revision 1
did not have to handle and the shipped code does**: the SQLite comparison `json_extract(metadata, '$._aggregate_version') >= ?`
silently matches **nothing** unless the parameter is bound as an integer, because the expression has no column
affinity and SQLite compares an integer against a text parameter as always-less. `createAggregateWhereClause` binds
`ParameterType::INTEGER`, which is why the shipped code is correct; any reimplementation has to keep it.

### 3.2 Plans

**PostgreSQL** — every branch index-driven: `BitmapOr` over `ecotone_tag_versions_pkey` for the capture,
`GroupAggregate → Index Scan using ecotone_tagged_events_pkey` with `tag_name`, `tag_value` and `stream_name` in the
`Index Cond`, `Nested Loop → Index Scan using research_stream_pkey` for the join, and
`Index Scan using ix_…_unique_event` for the aggregate branch. H3: `Buffers: shared hit=3257`, `Execution Time` 2.8 ms for 2 002 rows.

**A correction to revision 2 §10.2.** That section states that on PostgreSQL the snapshot's covered position
*"reaches the index as an `InitPlan` inside the same statement"* and quotes
`Index Cond: (… AND tag_sequence > COALESCE($1, 0))`. On the **shipped** primary key
`(tag_name, tag_value, stream_name, event_no)` the `InitPlan` is real but the bound is not an index condition:

```
->  GroupAggregate  (actual time=0.147..0.190 rows=100 loops=1)
      Buffers: shared hit=21
      InitPlan 2 (returns $1)
        ->  CTE Scan on snaps snaps_1  (actual time=0.001..0.001 rows=1 loops=1)
      ->  Index Scan using ecotone_tagged_events_pkey on ecotone_tagged_events  (actual time=0.144..0.159 rows=100)
            Index Cond: ((tag_name = 'wallet') AND (tag_value = 'w-1') AND (stream_name = 'research_stream'))
            Filter: (tag_sequence > COALESCE($1, '0'::bigint))
```

The position is evaluated **once**, in the same statement, which is the correctness property §10.2 wanted. But
`tag_sequence > :covered` stays a **filter inside the tag's range**, so the scan still touches every index entry of
the tag. That is precisely §12.3's own observation about the existing key, and it is why Part 5's index is the item
whose case survives.

**MySQL 8.0** — `ecotone_tag_versions type=range key=PRIMARY`, `ecotone_tagged_events type=ref key=PRIMARY`,
`s type=eq_ref key=PRIMARY` for the join, aggregate branch `type=ref key=ix_query_aggregate`. Revision 1 §3.5
reported that MySQL picked `ix_unique_event` for the aggregate branch and paid a filesort for `ORDER BY no`; on the
shipped schema and this dataset it picks `ix_query_aggregate` and there is **no filesort**, so that particular free
win no longer exists.

**MariaDB 11.4** — identical, with `Using index condition` on the aggregate branch.

**SQLite** — `MULTI-INDEX OR` for the capture, `MATERIALIZE flags`, `SEARCH … USING INDEX` on both branches.

### 3.3 Today against option C, one clock

Raw SQL, medians of 20, two runs as a range. "Today" is the same statement sequence the shipped code issues,
replayed on the same connection.

| shape | engine | today ms / stmts / rows | option C ms / stmts / rows |
|---|---|---|---|
| H1 | PostgreSQL | 5.07–5.65 / 3 / 2 001 | **3.24–4.96** / 1 / 1 001 |
| H1 | MySQL | 6.10–6.70 / 3 / 2 001 | **3.38–5.01** / 1 / 1 001 |
| H1 | MariaDB | 4.04–4.37 / 3 / 2 001 | 3.65–4.30 / 1 / 1 001 |
| H1 | SQLite | 2.29–3.16 / 3 / 2 001 | **1.68–2.61** / 1 / 1 001 |
| H1small | PostgreSQL | **0.22–0.33** / 3 / 21 | 0.43–0.58 / 1 / 11 |
| H1small | MySQL | 0.28–0.63 / 3 / 21 | 0.24–0.58 / 1 / 11 |
| H1small | MariaDB | 0.32–0.53 / 3 / 21 | 0.32–0.51 / 1 / 11 |
| H1small | SQLite | 0.08–0.10 / 3 / 21 | 0.09–0.12 / 1 / 11 |
| H2 | PostgreSQL | **1.95–2.03** / 3 / 1 001 | 2.00–3.07 / 1 / 1 001 |
| H2 | MySQL | 3.07–4.44 / 3 / 1 001 | 2.96–3.76 / 1 / 1 001 |
| H2 | MariaDB | 2.46–2.85 / 3 / 1 001 | 2.13–3.13 / 1 / 1 001 |
| H2 | SQLite | 1.21–1.25 / 3 / 1 001 | **0.87** / 1 / 1 001 |
| H3 | PostgreSQL | 5.68–8.61 / 5 / 3 002 | 4.74–8.15 / 1 / 2 002 |
| H3 | MySQL | 6.75–10.62 / 5 / 3 002 | 5.65–8.87 / 1 / 2 002 |
| H3 | MariaDB | 6.25–6.60 / 5 / 3 002 | 6.25–7.48 / 1 / 2 002 |
| H3 | SQLite | 3.32–3.34 / 5 / 3 002 | **2.57–2.59** / 1 / 2 002 |
| H4 | PostgreSQL | 8.18–8.75 / 7 / 3 104 | **4.81–7.70** / 1 / 2 003 |
| H4 | MySQL | 9.33–10.59 / 7 / 3 104 | **7.46–7.52** / 1 / 2 003 |
| H4 | MariaDB | 5.89–6.07 / 7 / 3 104 | 4.35–7.49 / 1 / 2 003 |
| H4 | SQLite | 3.48–3.52 / 7 / 3 104 | **2.58–2.63** / 1 / 2 003 |
| H5, snapshot as a CTE | PostgreSQL | 1.60–2.46 / 6 / 305 | 1.47–2.93 / 1 / 205 |
| H5, snapshot read first | PostgreSQL | 1.60–2.46 / 6 / 305 | 2.27–3.42 / 3 / 205 |
| H5, snapshot as a CTE | MySQL | 1.45–2.20 / 6 / 305 | 1.45–2.09 / 1 / 205 |
| H5, snapshot read first | MySQL | 1.45–2.20 / 6 / 305 | 1.27–1.44 / 3 / 205 |
| H5, snapshot as a CTE | MariaDB | 0.99–1.36 / 6 / 305 | 1.14–1.25 / 1 / 205 |
| H5, snapshot read first | MariaDB | 0.99–1.36 / 6 / 305 | 1.18–1.85 / 3 / 205 |
| H5, snapshot as a CTE | SQLite | 1.10 / 6 / 305 | **0.54** / 1 / 205 |
| H5, snapshot read first | SQLite | 1.10 / 6 / 305 | **0.47–0.48** / 3 / 205 |
| H6 | PostgreSQL | 6.47–7.15 / 4 / 2 001 | 5.17–6.26 / 1 / 1 001 |
| H6 | MySQL | 4.42–4.45 / 4 / 2 001 | 3.99–6.69 / 1 / 2 001 |
| H6 | MariaDB | 2.79–3.69 / 4 / 2 001 | 2.74–2.88 / 1 / 1 001 |
| H6 | SQLite | 2.38–2.39 / 4 / 2 001 | 2.77 / 1 / 1 001 |

*H6's option C is one statement only because the harness knew which two streams to read. The shipped code does
not: `flagsFor` is the only discovery mechanism (revision 1 §3.4), so a real implementation pays a discovery read
first and option C is then **two** statements for a multi-stream scope, not one. The number above is an upper bound
on what option C could achieve there.*

**The two variants of H5** — the snapshot as a CTE of the combined statement, or read as its own statement first —
are within noise of each other on all four engines, as revision 2 §10.2 found. The CTE keeps it to one round trip;
the preceding read keeps the document store free to live on another connection.

### 3.4 What it is worth as a share of the whole command

The number that decides the item. **Command** is the measured read wall clock plus the measured append wall clock
plus the brief's 2 ms of handler work. **Saving** is today's SQL minus option C's SQL, from the table above.

| shape | engine | read wall | append wall | command | option C saves | share of the command |
|---|---|---|---|---|---|---|
| H1 | PostgreSQL | 16.1–25.8 | 1.4–1.8 | 19.5–29.6 | 0.10–2.40 | 0.4–9.8% |
| H1 | MySQL | 16.0–22.5 | 1.3–1.6 | 19.3–26.1 | 1.09–3.32 | 4.8–14.6% |
| H1 | MariaDB | 17.7–18.0 | 1.3 | 21.0–21.3 | 0.06–0.39 | 0.3–1.8% |
| H1 | SQLite | 13.1–18.6 | 0.6–0.8 | 15.7–21.4 | 0.55–0.61 | 3.0–3.3% |
| H2 | PostgreSQL | 17.3–18.8 | 2.0–2.1 | 21.3–22.9 | **−1.05 to −0.05** | **−4.7 to −0.2%** |
| H2 | MySQL | 18.1–18.7 | 1.9–2.0 | 22.0–22.6 | 0.12–0.68 | 0.5–3.0% |
| H2 | MariaDB | 13.7–17.6 | 1.6 | 17.3–21.3 | −0.28 to 0.32 | −1.5 to 1.7% |
| H2 | SQLite | 8.9–12.6 | 0.6–0.8 | 11.5–15.4 | 0.34–0.38 | 2.5–2.8% |
| H3 | PostgreSQL | 45.0–46.5 | 1.9 | 48.9–50.4 | 0.46–0.94 | 0.9–1.9% |
| H3 | MySQL | 38.1–46.0 | 2.0–2.1 | 42.1–50.1 | 1.10–1.75 | 2.4–3.8% |
| H3 | MariaDB | 41.1–42.9 | 1.6–2.0 | 45.1–46.6 | **−1.23 to 0.35** | **−2.7 to 0.8%** |
| H3 | SQLite | 22.8–30.1 | 0.6–0.8 | 25.4–32.8 | 0.75 | 2.6% |
| H4 | PostgreSQL | 45.7–47.3 | 2.0–2.2 | 49.7–51.6 | 1.04–3.36 | 2.1–6.6% |
| H4 | MySQL | 48.6–50.1 | 2.1 | 52.7–54.2 | 1.81–3.13 | 3.4–5.9% |
| H4 | MariaDB | 37.8–46.2 | 1.5 | 41.3–49.7 | −1.59 to 1.72 | −3.5 to 3.8% |
| H4 | SQLite | 24.8 | 0.6 | 27.4 | 0.88–0.90 | 3.2–3.3% |
| H5 | PostgreSQL | 8.0–9.5 | 2.0–3.1 | 12.0–14.6 | **−0.47 to 0.14** | **−3.5 to 1.0%** |
| H5 | MySQL | 5.6–6.0 | 1.7–1.9 | 9.3–9.9 | −0.01 to 0.11 | −0.1 to 1.2% |
| H5 | MariaDB | 5.7–8.7 | 1.7–2.1 | 9.3–12.9 | −0.26 to 0.22 | −2.4 to 2.0% |
| H5 | SQLite | 3.7–3.8 | 0.6 | 6.3–6.4 | 0.56 | 8.7–8.8% |
| H6 | PostgreSQL | 29.8–31.7 | 1.6–1.8 | 33.4–35.6 | 0.89–1.31 | 2.6–3.8% |
| H6 | MySQL | 29.2 | 1.7–1.9 | 32.8–33.2 | **−2.28 to 0.47** | **−6.9 to 1.4%** |
| H6 | MariaDB | 27.9–28.8 | 1.7–1.8 | 31.5–32.7 | −0.09 to 0.95 | −0.3 to 3.0% |
| H6 | SQLite | 15.1 | 0.7 | 17.7–17.8 | **−0.38** | **−2.2%** |

Ten of the twenty-four cells have a negative lower bound and six have a midpoint below zero; one — H6 on SQLite —
is negative in both runs. Seventeen of the twenty-four lie entirely within ±4%. The best cell is 14.6% and it is
MySQL's H1, whose two runs disagree by a factor of three. **The honest
summary is: option C is worth nought to four percent of the command, it is inside this stack's own run-to-run
variance, and there are shapes where it is a loss.**

**Where it is a loss, and why, is not noise.** A small scope (`H1small` on PostgreSQL, 0.22–0.33 ms today against
0.43–0.58 ms) loses because three tiny index lookups are cheaper than one statement with three CTEs and a
four-branch `UNION ALL` to plan; the planning cost is fixed and the work is nothing. An aggregate-only handler
(`H2`) loses on PostgreSQL for the same reason, and it is the shape where option C has least to fold — there is no
tag branch to merge. Both are common shapes.

**What option C does buy, unambiguously**: the statement count falls from 3–7 to 1 (2 for a multi-stream scope),
and the rows crossing the wire fall by a third to a half, because today's `flagsFor` and `no IN (…)` return the same
thousand events twice — once as index rows and once as events. On a database that is not on localhost, round trips
are the cost that does not show on this stack at all. That is the argument for option C and it is the argument
revision 1 itself made ("take it for the round trips, and say so honestly"). It is not visible in any number here.

### 3.5 Contention

Eight worker processes, the same counters, each committing fifteen times; think time 0, 2 and 10 ms between the
read and the guarded bump; read phase either today's statements or option C's one statement. Raw SQL, so the
comparison isolates the read.

| engine | shape | think | variant | conflicts | conflict rate | commits/s | median latency |
|---|---|---|---|---|---|---|---|
| PostgreSQL | H3 | 0 ms | today | 470 | 79.7% | 97.7 | 8.4 ms |
| PostgreSQL | H3 | 0 ms | option C | 578 | 82.8% | 78.5 | 11.1 ms |
| PostgreSQL | H3 | 2 ms | today | 583 | 82.9% | 62.5 | 14.7 ms |
| PostgreSQL | H3 | 2 ms | option C | 550 | 82.1% | 72.3 | 13.1 ms |
| PostgreSQL | H3 | 10 ms | today | 611 | 83.6% | 43.1 | 20.8 ms |
| PostgreSQL | H3 | 10 ms | option C | 562 | 82.4% | 47.3 | 18.6 ms |
| PostgreSQL | H5 | 0 ms | today | 665 | 84.7% | 164.9 | 5.1 ms |
| PostgreSQL | H5 | 0 ms | option C | 502 | 80.7% | 211.0 | 3.9 ms |
| PostgreSQL | H5 | 2 ms | today | 676 | 84.9% | 123.1 | 7.1 ms |
| PostgreSQL | H5 | 2 ms | option C | 588 | 83.0% | 144.9 | 5.9 ms |
| PostgreSQL | H5 | 10 ms | today | 589 | 83.1% | 61.7 | 15.1 ms |
| PostgreSQL | H5 | 10 ms | option C | 614 | 83.7% | 62.5 | 14.8 ms |
| MySQL | H3 | 0 ms | today | 632 | 84.0% | 63.2 | 14.3 ms |
| MySQL | H3 | 0 ms | option C | 615 | 83.7% | 68.1 | 13.5 ms |
| MySQL | H3 | 2 ms | today | 555 | 82.2% | 49.6 | 18.5 ms |
| MySQL | H3 | 2 ms | option C | 598 | 83.3% | 62.7 | 13.9 ms |
| MySQL | H3 | 10 ms | today | 635 | 84.1% | 38.8 | 25.0 ms |
| MySQL | H3 | 10 ms | option C | 621 | 83.8% | 41.6 | 22.9 ms |
| MySQL | H5 | 0 ms | today | 709 | 85.5% | 140.3 | 6.0 ms |
| MySQL | H5 | 0 ms | option C | 601 | 83.4% | 120.5 | 6.9 ms |
| MySQL | H5 | 2 ms | today | 667 | 84.8% | 128.4 | 6.8 ms |
| MySQL | H5 | 2 ms | option C | 658 | 84.6% | 130.2 | 6.8 ms |
| MySQL | H5 | 10 ms | today | 678 | 85.0% | 59.6 | 15.7 ms |
| MySQL | H5 | 10 ms | option C | 700 | 85.4% | 54.7 | 16.9 ms |
| MariaDB | H3 | 0 ms | today | 629 | 84.0% | 89.2 | 10.2 ms |
| MariaDB | H3 | 0 ms | option C | 562 | 82.4% | 80.5 | 12.2 ms |
| MariaDB | H3 | 2 ms | today | 526 | 81.4% | 67.1 | 13.8 ms |
| MariaDB | H3 | 2 ms | option C | 518 | 81.2% | 69.9 | 13.7 ms |
| MariaDB | H3 | 10 ms | today | 562 | 82.4% | 48.1 | 19.8 ms |
| MariaDB | H3 | 10 ms | option C | 535 | 81.7% | 48.2 | 19.5 ms |
| MariaDB | H5 | 0 ms | today | 636 | 84.1% | 262.4 | 3.1 ms |
| MariaDB | H5 | 0 ms | option C | 591 | 83.1% | 283.6 | 2.7 ms |
| MariaDB | H5 | 2 ms | today | 628 | 84.0% | 159.1 | 5.6 ms |
| MariaDB | H5 | 2 ms | option C | 660 | 84.6% | 131.5 | 6.8 ms |
| MariaDB | H5 | 10 ms | today | 707 | 85.5% | 64.3 | 14.9 ms |
| MariaDB | H5 | 10 ms | option C | 652 | 84.5% | 68.9 | 13.9 ms |
| SQLite | H3 | 0 ms | today | 456 | 79.2% | 61.0 | 16.0 ms |
| SQLite | H3 | 0 ms | option C | 336 | 73.7% | 79.7 | 13.7 ms |
| SQLite | H3 | 2 ms | today | 347 | 74.3% | 60.1 | 16.7 ms |
| SQLite | H3 | 2 ms | option C | 525 | 81.4% | 59.4 | 17.2 ms |
| SQLite | H3 | 10 ms | today | 528 | 81.5% | 32.6 | 34.5 ms |
| SQLite | H3 | 10 ms | option C | 592 | 83.2% | 33.0 | 33.8 ms |
| SQLite | H5 | 0 ms | today | 260 | 68.4% | 144.7 | 6.4 ms |
| SQLite | H5 | 0 ms | option C | 109 | 47.6% | 181.8 | 4.3 ms |
| SQLite | H5 | 2 ms | today | 294 | 71.0% | 89.2 | 9.5 ms |
| SQLite | H5 | 2 ms | option C | 305 | 71.8% | 127.5 | 7.7 ms |
| SQLite | H5 | 10 ms | today | 405 | 77.1% | 40.4 | 22.5 ms |
| SQLite | H5 | 10 ms | option C | 406 | 77.2% | 40.3 | 22.3 ms |

*Every worker commits fifteen times, so 120 commits per cell; the conflict count is how many attempts were
rejected by the guarded `UPDATE` before those 120 succeeded.*

**The conflict rate is 79–86% in every configuration on the three server engines, with option C and without, at
every think time.** Revision 1 measured 87.1% against 86.2% and concluded that the rate is set by the number of
contenders; that conclusion holds on the shipped code. Throughput moves by **−20% to +43% with no consistent
sign** — the worst cell is PostgreSQL H3 at think 0 (97.7 → 78.5 commits/s) and the best is SQLite H5 at think 2
(89.2 → 127.5) — and the sign flips between engines for the same shape and the same think time, which is what a
measurement inside its own noise looks like.

**The only clean signal in the whole grid is not about option C at all**: H5 against H3, snapshots against no
snapshots, on today's statements, is 97.7 → 164.9 commits/s on PostgreSQL at think 0, 63.2 → 140.3 on MySQL,
89.2 → 262.4 on MariaDB and 61.0 → 144.7 on SQLite — **1.7× to 2.9×, on every engine**. Note what does *not*
improve: the conflict rate is the same or slightly worse (PostgreSQL 79.7% → 84.7%). A shorter read does not make
a contending handler more likely to win; it makes it fail and retry faster, so more work gets through per second.
That is the shipped snapshots paying off under contention, and it is a larger effect than anything option C does.

---

## Part 4 — The `#[Fetch]` hand-off

### 4.1 What the converter costs today

H4's fetched aggregate, measured through the shipped code by selecting the statements
`FetchAggregateConverter → AllAggregateRepository::findBy()` issues.

| engine | no aggregate snapshot | with `withSnapshotsFor(Customer::class, 1)` |
|---|---|---|
| PostgreSQL | **1.52–1.76 ms / 2 statements** | 1.85–1.93 ms / **4 statements** |
| MySQL | 1.71–1.81 ms / 2 | 2.06–2.22 ms / 4 |
| MariaDB | 1.06–1.09 ms / 2 | 1.22–1.44 ms / 4 |
| SQLite | 0.10–0.12 ms / 2 | 0.27 ms / 4 |

The two statements without a snapshot are **the `information_schema` probe and the aggregate read**, and the probe
is the larger of the two on every engine:

| engine | probe | the 100-event aggregate read it guards |
|---|---|---|
| PostgreSQL | 0.92–1.11 ms | 0.54–0.73 ms |
| MySQL | 1.06–1.27 ms | 0.46–0.62 ms |
| MariaDB | 0.52–0.57 ms | 0.44–0.53 ms |
| SQLite | 0.05 ms | 0.05 ms |

*Per-statement figures are from the accounting run, one sample each; the totals in the table above are medians of
twenty.*

With a
snapshot configured the four are: the snapshot document, the **fold-shape marker document** that
`EventSourcedRepositoryAdapter::foldShapeStoredWith` reads beside it (the sibling marker of revision 2 Part 14.2,
and it is a second round trip), the probe, and the tail.

### 4.2 What folding it would cost

The customer aggregate added as a fourth branch of the combined statement, against the same statement without it.
The customer holds 101 events after the snapshot-creating command.

| engine | combined without the fetched aggregate | with it folded from version 1 | folded from its own snapshot |
|---|---|---|---|
| PostgreSQL | 9.14–9.28 ms / 2 003 rows | 9.14–9.37 ms / 2 104 rows | 7.77–8.90 ms / 2 004 rows |
| MySQL | 8.53–9.47 ms / 2 003 | 6.90–10.28 ms / 2 104 | 6.68–9.74 ms / 2 004 |
| MariaDB | 5.10–5.70 ms / 2 003 | 5.46–5.48 ms / 2 104 | 5.29–8.80 ms / 2 004 |
| SQLite | 3.33–3.78 ms / 2 003 | 3.22–3.73 ms / 2 104 | 2.63–3.61 ms / 2 004 |

**The branch is free, and so is what it replaces.** Adding the fetched aggregate to the statement costs between
−1.6 ms and +0.8 ms — it is inside the variance of the statement it joins. What it removes is the 1.06–1.76 ms of
Part 4.1, of which 0.05–1.27 ms is a probe that should not be there in the first place. **Net, on this stack, the
hand-off is worth about half a millisecond and one round trip per fetched aggregate**, and it is worth it only
where the combined statement already exists — that is, only if option C is built first, which Part 3 recommends
against.

### 4.3 The eligibility condition, stated precisely

If the hand-off were built, the batch loader may pre-load a `#[Fetch]`-ed parameter **only when all of the
following hold, and all of them are decidable at bootstrap, from the configuration and the class metadata, with no
runtime branch**:

1. The aggregate class carries `#[EventSourcingAggregate]` — a state-stored aggregate has no events to fold.
2. The repository that `AllAggregateRepository` would dispatch to for that class is Ecotone's own
   `EventSourcingRepository` wrapped in `EventSourcedRepositoryAdapter` — not a user-supplied
   `EventSourcedRepository`, whose events need not be in `ecotone_event_stream` or in a database at all, and not a
   `StateStoredRepositoryAdapter`.
3. The aggregate's stream resolves, through `StreamTableRegistry`, to a table on **the handler's own connection** —
   the condition `CrossConnectionDecisionModelGuard` already enforces for decision models.
4. The aggregate's identifier is resolvable before the converters run, which
   `FetchedAggregateCounterCapture::resolveCriteria` already establishes: it is how the counter reaches the capture
   set today.

**What stays on the repository, always:** the licence check, the expression evaluation, `identifiersFrom`, the
`AggregateNotFoundException` and the nullable handling; `withSnapshotsFor` snapshots and their fold-shape markers,
which live in the document store under the user's own serialization and are read by
`EventSourcedRepositoryAdapter`, not by the tag reader; and every class failing any of 1–4. So
`FetchAggregateConverter` gains exactly one guard clause —

```php
$preloaded = DecisionModelLoadedState::fetchedAggregateIn($message, $this->parameterName);
if ($preloaded !== null) { return $preloaded; }
```

— and nothing else. That is revision 2 §10.4's design and it is still the right design. **The measurement says it
is not worth building yet**, and it says so more strongly than revision 2 expected, because most of the round trip
it removes can be removed by deleting one line (Part 2.3(i)).

**One correctness note, and it is in favour of the hand-off, not against it.** Folding the fetched aggregate into
the combined statement would put its read into the same database snapshot as its captured counter, which on
PostgreSQL it is not today (Part 2.4). Revision 1 §2.3b already showed that the present arrangement is *safe* —
the counter is captured in statement 1, long before the converter reads the aggregate in statement 7, so a
competing save makes the events newer than the capture and the guarded `UPDATE` fails. The hand-off would make it
*tidy*. That is not a reason to build it.

---

## Part 5 — The extra index

`CREATE INDEX ix_tag_sequence ON ecotone_tagged_events (tag_name, tag_value, tag_sequence, stream_name, event_no)`.

### 5.1 The flags tail

The snapshot tail — the last 100 of a tag's index rows, which is exactly the statement H5 issues — at three tag
sizes. Medians of 20, two runs as a range.

| tag rows | engine | without the index | with it | speed-up |
|---|---|---|---|---|
| 1 000 | PostgreSQL | 0.47–0.57 ms | 0.46–0.49 ms | — |
| 1 000 | MySQL | 0.51–1.01 ms | 0.43–0.62 ms | ~1.4× |
| 1 000 | MariaDB | 0.57–0.79 ms | 0.54–0.74 ms | — |
| 1 000 | SQLite | 0.23–0.34 ms | 0.22–0.34 ms | — |
| 10 000 | PostgreSQL | 2.42–2.46 ms | 0.46–0.59 ms | **4.7×** |
| 10 000 | MySQL | 2.55–4.00 ms | 0.45–0.90 ms | **5.0×** |
| 10 000 | MariaDB | 2.52–3.93 ms | 0.70–0.87 ms | **4.1×** |
| 10 000 | SQLite | 1.20–1.54 ms | 1.03–1.60 ms | — |
| 100 000 | PostgreSQL | 10.95–11.00 ms | 0.38–0.49 ms | **25×** |
| 100 000 | MySQL | 20.76–35.43 ms | 0.37–0.47 ms | **56×** |
| 100 000 | MariaDB | 20.19–31.30 ms | 0.39–0.50 ms | **56×** |
| 100 000 | SQLite | 11.34–11.66 ms | **11.77–13.65 ms** | **none** |

Revision 2 §12.3 measured the 1 000-row row and concluded "noise on PostgreSQL and MariaDB, helps MySQL"; that
reproduces exactly. The two rows §12.3 did not measure are the ones that decide the item. **The tail without the
index is O(the whole tag); with it, O(the tail)** — PostgreSQL's plan collapses to
an `Index Only Scan using ix_tag_sequence` with the bound **in the index condition** —
`Index Cond: (tag_name = 'wallet' AND tag_value = 'w-100k' AND tag_sequence > '99900')`, `Buffers: shared hit=6`,
`Execution Time: 0.116 ms` — where without it PostgreSQL gives up on the index entirely and runs a
`Parallel Seq Scan on ecotone_tagged_events` with `Rows Removed by Filter: 70 905` and `Buffers: shared hit=1405`.
MySQL and MariaDB go to `type=range key=ix_tag_sequence … Using index`, rows=100.

**SQLite gets nothing, and the plan says why.** Even after `ANALYZE`, SQLite keeps
`SEARCH ecotone_tagged_events USING INDEX sqlite_autoindex_ecotone_tagged_events_1 (tag_name=? AND tag_value=?)` —
its planner does not prefer the wider index for this shape, and the extra index is then pure write cost on SQLite.

*The whole-scope read (no snapshot bound) is unaffected or slightly worse with the index on every engine, as
expected: it is the same range either way and the extra index adds a candidate for the planner to weigh.*

### 5.2 Write amplification

| engine | index-row `INSERT`, no extra index | with it | table + indexes, no extra index | with it | build time |
|---|---|---|---|---|---|
| PostgreSQL | 0.60–0.61 ms | 0.31–0.52 ms | 25.9 MB | **35.5 MB (+37%)** | 235 ms |
| MySQL | 1.45–9.02 ms | 1.66–7.00 ms | 11.1 MB | **20.0 MB (+81%)** | 858 ms |
| MariaDB | 0.36–1.84 ms | 0.36–1.98 ms | 11.1 MB | **20.0 MB (+81%)** | 200 ms |
| SQLite | 1.25–1.63 ms | 1.28–1.31 ms | — | — | 87 ms |

**The insert cost is not measurable against the noise** on any engine — the ranges overlap everywhere, and on
PostgreSQL the "with" case is nominally *faster*, which is the clearest possible statement that the signal is
below the noise floor at this table size. **The storage cost is measurable and large**: the index duplicates every
column of the primary key plus `tag_sequence`, on a table that already holds one row per (event, tag). At 141 910
rows that is +9.6 MB on PostgreSQL and +8.9 MB on the MySQL pair — about 68 bytes per index row, so roughly
**7 GB at a hundred million index rows**. Build time is 87–858 ms for 141 910 rows, so a `CREATE INDEX` on a production-sized table is a
maintenance window on MySQL unless it is built online.

### 5.3 Where the break-even is

The tail statement is one of two to six in the read phase, and Part 2.2 puts the whole read phase's SQL at 2.4–4.0 ms
for H5. The index saves:

| tag rows | saving on the tail | as a share of H5's read-phase SQL | as a share of the whole command |
|---|---|---|---|
| 1 000 | 0.0–0.4 ms | 0–13% | 0–3% |
| 10 000 | 1.8–3.1 ms | 60–100% | 14–26% |
| 100 000 | 10.5–35.0 ms | 4–12× the whole read phase | **45–75% of a ~14 ms command** |

At a thousand index rows per tag the index is what revision 2 said it was. **At ten thousand it is the largest
single item in this whole document, and at a hundred thousand it is the difference between a decision read that
scales and one that does not.** And a hundred thousand index rows under one tag value is exactly the workload
snapshots were built for: a long-lived wallet, subscription or account, which is §12.5's own "benefits" column.

---

## Part 6 — Recommendations

| item | verdict | the number that decides it | cost | correctness or guarantee effect |
|---|---|---|---|---|
| **(a) Option C — the combined statement with the capture CTE** | **Skip** | −7% to +15% of the whole command, within ±4% in seventeen of twenty-four engine/shape cells, negative in ten; no effect on the conflict rate (79–86% either way, three server engines, every think time) | A SQL composition layer (`DbalSingleReadPlan` plus a `*Cte` fragment on three collaborators), four dialects, and a permanent constraint that every future read feature must be expressible as a `UNION ALL` branch. Makes small scopes and aggregate-only handlers measurably slower on PostgreSQL | Would make "the capture and the reads share one database snapshot" structural on PostgreSQL instead of argued, and would make revision 2 §11.4's "snapshot ahead of the capture" impossible rather than guarded. Both are already safe today (Part 2.4); the change buys tidiness, not correctness |
| **(b) The `#[Fetch]` hand-off** | **Skip** | The whole converter path is 1.06–1.76 ms without an aggregate snapshot and 1.22–2.22 ms with one; folding it into the statement costs −1.6 to +0.8 ms, i.e. nothing measurable. Half the saving is a probe that one deleted line removes | A bootstrap-decided eligibility rule with four conditions (Part 4.3), a guard clause in a branch-free converter, and a read shape that differs by aggregate class. Requires option C to exist first | Would put the fetched aggregate's read in the same snapshot as its captured counter. Safe today either way (revision 1 §2.3b, reconfirmed in Part 2.4) |
| **(c) The extra index `(tag_name, tag_value, tag_sequence, stream_name, event_no)`** | **Build, as an opt-in — not a default** | 4–5× at 10 000 index rows per tag, 25–56× at 100 000, on PostgreSQL, MySQL and MariaDB; nothing at 1 000, and nothing at any size on SQLite | +37% table bytes on PostgreSQL, +81% on MySQL and MariaDB; insert cost below the noise floor; 87–858 ms to build 141 910 rows | None. It changes no result, no ordering and no guarantee — `tag_sequence > :covered` returns the same rows either way |
| *Delete the `tableExists` probe from `DbalEventStore::loadAggregateEvents`* | **Build first** — outside this comparison | 0.92–1.11 ms per aggregate load on PostgreSQL and 1.06–1.27 ms on MySQL — half to two thirds of the cost of loading a 100-event aggregate — on **every** aggregate load in the framework | A few lines: the `TableNotFoundException` catch that `loadDecisionModelAggregateEvents` already uses | Turns a silent empty read into the `ConfigurationException` the design already raises elsewhere — strictly better |
| *Break `selectEvents`' paging loop on `count($rows) < $limit` **or** an empty page* | **Build** — outside this comparison | 0.46–0.57 ms per aggregate whose event count is an exact multiple of `loadBatchSize` (PostgreSQL) | One condition | None |

### 6.1 If the maintainer wants the shipped read faster, in order

1. **Delete the second probe** (`loadAggregateEvents`). Largest ratio of benefit to work in this document, and it
   is not a decision-path change — it is every aggregate load.
2. **Make the extra index available**, as a `database:setup` feature that an application opts into per deployment,
   and document the threshold: *worth it above roughly ten thousand index rows under one tag value; pointless
   below one thousand; pointless on SQLite.*
3. **Attack the fold, not the read.** H3 spends 9–12 ms in the database and 38–46 ms in the read phase.
   `TagResolver::eventsMatching` re-derives every event's tags from its payload object for every model, and `MatchedTagSequences` is already
   stamping the sequences the index returned; the events are deserialized once and folded once per model. That is
   where the other thirty milliseconds are. It is a separate research question and this document does not answer
   it — but no amount of SQL shaping will reach it.
4. **Nothing else here.** Option C and the hand-off are both real, both correct, both fully designed in revision 2,
   and both worth less than their own measurement error on this workload.

### 6.2 What would change the answer for option C

State it now so the decision can be revisited on evidence rather than re-argued:

- **A database that is not on the same host.** Every number in Parts 3 and 4 was taken over a container-network
  socket with sub-100 µs round trips. Option C removes two to six round trips per command; at a 1 ms network
  round trip that is 2–6 ms of pure latency it would recover, which is larger than everything it saves here.
  **This is the one measurement that would flip the recommendation, and this stack cannot make it.**
- **A handler whose models are all snapshotted and whose scopes are all small.** Then the fold is cheap, the read
  phase is 6–15 ms rather than 50, and a flat 1–3 ms saving is 10–20% instead of 2–4%. H5 on SQLite is already
  8.7% for that reason.
- **Many statements per read.** The general form is `1 + A_fetched` against `2 + S + 2·(A_backed + A_fetched)`. A
  handler with four aggregate-backed models across three streams pays 13 statements today and 1 with option C. No
  such handler was measured because none appears in the design's own examples.

---

## Part 7 — Open questions, with recommended answers

**OQ-A — should option C be revisited for a remote database?** Yes, and only then. *Recommendation: record in the
docs that the decision read costs `2 + S + 2·(A_backed + A_fetched)` round trips, so that a deployment with a
remote database knows what it is paying; re-open option C if and when a user reports it. Do not build it
speculatively — it is a permanent constraint on the read path's shape in exchange for a saving this stack cannot
measure.*

**OQ-B — how should the extra index be offered?** Not as a schema change to `TaggedEventSchema`, because it is
wrong for the majority of applications (short-lived high-cardinality tags, and every SQLite deployment).
*Recommendation: a named, optional `database:setup` feature — `ecotone_tagged_events` gains the index only when the
deployment asks for it — with the thresholds of Part 5.3 in the documentation beside the snapshot guidance, since
the two apply to the same workload. `verify-schema` should not complain about its absence.*

**OQ-C — should the index be `(tag_name, tag_value, tag_sequence, stream_name, event_no)` or narrower?** The wide
form measured here is index-only for the whole `flagsFor` statement — `Using index` on MySQL and MariaDB,
`Index Only Scan` on PostgreSQL (with `Heap Fetches: 80` of 100 rows on a freshly written table, which a later
`VACUUM` removes) — which is where the 25–56× comes from. A narrower
`(tag_name, tag_value, tag_sequence)` would still turn the filter into a range but would then need a heap or
primary-key lookup per row. *Recommendation: the wide form, and measure the narrow one before shipping if the
storage cost is contested.*

**OQ-D — is the `runsAheadOfTheCapture` guard still needed?** With the document store on the event store's
connection (the default) the snapshot and the counter commit in one transaction, so `covered ≤ captured` holds by
construction and the guard never fires. It fires only for a document store configured on a different connection.
*Recommendation: keep it — it is cheap, it is the only thing standing between a split-connection document store and
a double-folded model, and Part 2.4 is an argument, not an enforcement. Add a sentence to its docblock naming the
configuration it protects.*

**OQ-E — should the `#[Fetch]` hand-off be reconsidered after the probe is deleted?** After Part 2.3(i) the
converter path is one statement and 0.54–0.73 ms on PostgreSQL. *Recommendation: no. At that point the hand-off buys
one round trip and under a millisecond, for a bootstrap-decided branch in a converter that currently has none.
Revisit only under OQ-A.*

**OQ-F — what is the per-event PHP cost, and can it be reduced?** Measured here as a by-product: 12–18 µs per
event per model on this stack, dominating the decision read by three to four times at a thousand events.
*Recommendation: a separate research question. The two candidates visible from this measurement are (i)
`TagResolver::eventsMatching` re-deriving each event's tags from its payload for every model when
`MatchedTagSequences` already carries what the index matched, and (ii) deserializing an event once per read rather
than once per model. Neither is in this document's scope and neither is claimed to be a defect.*

**OQ-G — should `flagsFor` and `loadEventsByNumbers` be merged even without option C?** They return the same
thousand events twice — once as index rows, once as event rows — and the merge is a single join, not a four-branch
`UNION ALL` with three CTEs. Measured as part of option C it is most of what option C actually saves.
*Recommendation: if any part of option C is ever built, build this part alone first: `flags ⋈ stream` per stream,
leaving the capture and the aggregate reads as they are. It keeps the capture-first ordering literal, it costs one
collaborator's SQL rather than a composition layer, and Part 3.3's H1 and H6 rows are its upper bound
(0.9–2.4 ms on PostgreSQL). It was not measured in isolation here and should be before it is built.*

---

## Appendix — Reproducing this

The harness was one directory of throwaway PHP under `research-harness/`, run inside the compose `app` container
with `php -d xdebug.mode=off`, and deleted after the run. It is not committed. Its shape, for anyone rebuilding it:

- `shapes.php` — the six handlers, three aggregates, three decision models, the event classes, and the media-type
  converters the snapshots need.
- `bootstrap.php` — the recording `Connection` subclass, the four DSNs, and `bootstrapEcotone()` over
  `EcotoneLite::bootstrapFlowTestingWithEventStore` with `runForProductionEventStore: true` and an Enterprise
  licence.
- `seed.php` — the dataset of §1.3, written with explicit `no` values and `setval` on PostgreSQL afterwards.
- `baseline.php`, `optionc.php`, `handoff.php`, `index_and_isolation.php`, `isolation.php`,
  `contention.php` / `contention_worker.php` — Parts 2, 3, 4, 5, 2.4 and 3.5 respectively.

Per the 2026-09-27 rule, none of this is a test and none of these numbers is asserted anywhere in the suite; they
are review-protected, as revision 2 Part 12's are.
