# DCB on `ecotone_event_stream` — 2.0 Design

Status: **in discussion** — inventory complete, decisions open
Date: 2026-09-20
Supersedes: `docs/superpowers/specs/2026-08-22-dcb-event-store-design.md` (written before §4 of the upgrade guide shipped)
Research still valid: `docs/superpowers/research/dcb-event-store/report.md` (prior art and mechanism analysis; its
"what Ecotone does today" sections are stale)

---

## Part 1 — What actually shipped, versus what the old spec assumed

The old spec was written against a Prooph-wrapping store. §4 has since landed. This is the honest comparison,
read from the code at `d7402683`, not from the upgrade guide's prose.

### The store as it exists today

| Aspect | Reality at `d7402683` |
|---|---|
| Store class | `packages/PdoEventSourcing/src/Dbal/DbalEventStore.php` — Ecotone's own, no `Prooph\*` anywhere |
| Provenance | Headed `licence BSD-3-Clause / code comes from prooph/pdo-event-store`. It is Prooph's **design**, reimplemented in-house |
| `EventStore` interface | `packages/Ecotone/src/EventSourcing/EventStore.php` — **unchanged**: `create/appendTo/delete/hasStream/load(streamName, fromNumber, count, MetadataMatcher, deserialize)` |
| Query language | `MetadataMatcher` + `FieldType` + `Operator` — still the only way to filter, still Prooph's vocabulary |
| Physical layout | One table per stream name; stream name **is** the table name. Default `ecotone_event_stream`. No `event_streams` catalogue, no sha1 — except `#[Stream(legacyStreamName:)]`, which computes `_sha1(name)` to reach 1.x tables |
| Columns | `no` (BIGSERIAL/AUTO_INCREMENT, PK), `event_id` (UUID/CHAR(36), unique), `event_name`, `payload` (JSON), `metadata` (JSONB/JSON), `created_at`. MySQL/MariaDB add three STORED generated columns off `metadata` |
| Optimistic concurrency | `UNIQUE (_aggregate_type, _aggregate_id, _aggregate_version)` — a Postgres expression index over `metadata->>`, a MySQL unique key over the generated columns. Violation → `ConcurrencyException` |
| Version stamping | `SaveAggregateServiceTemplate.php:187` writes `MessageHeaders::EVENT_AGGREGATE_VERSION => ++$incrementedVersion` into each event's metadata |
| Write lock | `WriteLockStrategy` (Postgres advisory lock / MySQL `GET_LOCK`) around the multi-row INSERT, keyed on `sha1(tableName)`, opt-in via `enableWriteLockStrategy` |
| Event id fallback | `Uuid::uuid4()` (`DbalEventStore::convertToRow`) when no `MessageHeaders::MESSAGE_ID` is present |
| Table creation | §8 shipped. Tables are declared (`StreamTableRegistry`) and registered under the `event_stream` feature of `ecotone:migration:database:setup`. Runtime creation only when `automaticTableInitialization` is on (tests/dev), else `ConfigurationException` with the exact command |
| Projection reads | `EventStoreGlobalStreamSource` selects `no, event_name, payload, metadata, created_at WHERE no > :position` and filters **in PHP** — `matchesAnyFilter()` checks aggregate type and event-name globs per row |
| Gap handling | `GapAwarePosition` + `cleanGapsByTimeout()` — wall-clock, exactly as the old spec described. Unchanged |
| Multi-stream projections | `loadFromMultipleStreams()` still merges several tables by `created_at`, with a positions string `table=pos;table=pos;` |
| Package | Still `ecotone/pdo-event-sourcing`, still `packages/PdoEventSourcing`. Not renamed |
| Licence | `EventStreamTableManager`, `ProjectionStateTableManager`, `EventSourcingRepository` are all `licence Apache-2.0` now. The inconsistency the old spec flagged is resolved, free |

### Old spec, section by section

| Old spec section | Verdict | Why |
|---|---|---|
| Problem — "thin wrapper over `prooph/pdo-event-store`, 21 files import `Prooph\*`" | **Superseded** | Zero `Prooph\*` imports; 33 files in the package. The *shape* of the problem survives: the store is stream-centric and there is still no tag concept anywhere |
| Finding 1 — four persistence strategies are the consistency boundary | **Done** | One layout. `with*PersistenceStrategy()` removed |
| Finding 2 — `withPartitionStreamPersistenceStrategy()` bug | **Moot** | Method deleted |
| Finding 3 — concurrency is `_aggregate_version` + a physical UNIQUE | **Still exactly true** | Now an expression/generated-column index instead of a Prooph one. This is the single most load-bearing fact for DCB |
| Finding 4 — uuid4 in the store's fallback path | **Still true** | `DbalEventStore::convertToRow` |
| Finding 5 — gap detection is a wall-clock retry window | **Still true** | `GapAwarePosition::cleanGapsByTimeout()` |
| Finding 6 — v2 sources assume a global gappy `no` with no store guaranteeing one | **Half fixed** | The default single table *does* give a genuinely global `no`. Multi-table projections still merge by timestamp |
| Finding 7 — aggregate loading duplicates tag-query logic ad hoc | **Still true** | `EventSourcingRepository::findBy()` and `EventStoreAggregateStreamSource::loadFromStreamFilter()` each hand-roll the same three `MetadataMatcher` matches |
| Finding 8 — per-stream tables created on the fly, `DatabaseSetupManager` can't pre-create them | **Done** | §8. One residual gap: streams on a non-default `connectionReferenceName` are not covered by the setup command (upgrade guide §4 marks it TODO) |
| **Decision** — build `event_log` + `event_tags` + `event_tag_versions` | **Now wrong as written** | `ecotone_event_stream` has shipped, with a documented schema, a documented 1.x compatibility path (`legacyStreamName` tables keep the 1.x five columns), and a documented manual migration for stream-per-aggregate users. Introducing `event_log` would be a **second** breaking data migration inside 2.0, invalidating the migration advice already published in §4 |
| Decision — option table (B `bwaidelich`, C fork Prooph, D JSONB+GIN) | **Still valid reasoning**, wrong conclusion target | The two code-level rejections of `bwaidelich/dcb-eventstore` (top-level `SERIALIZABLE`, Postgres-only tag index) still hold. C is moot. D's rejection reason (a side table is needed anyway for per-tag sequencing) still holds |
| **`event_tag_versions` compare-and-swap** | **Mechanism still valid; role needs re-deciding** | The analysis of why re-check races, why `SELECT … FOR UPDATE` cannot gap-lock on Postgres, and why UPSERT-with-guard works at default isolation is engine behaviour and has not changed. What *has* changed: the shipped store already has a working per-aggregate concurrency mechanism (the UNIQUE index). Whether CAS **replaces** it or sits **beside** it for multi-tag conditions is now an open decision, not a foregone one |
| **Gap detection via `pg_snapshot_xmin`** | **Still valid, still unimplemented — and orthogonal to DCB** | Nothing about tags requires it, and nothing about it requires tags. Recommend splitting it out of this plan |
| Public API — namespace `Ecotone\EventSourcing\Api\*` | **Wrong namespace** | §13 settled the convention: `Ecotone\Api\EventSourcing\*`, flat (`Ecotone\Api\EventSourcing\Stream`, `…\EventSourcingConfiguration`), files under `packages/PdoEventSourcing/Api/` |
| Public API — `Query`, `AppendCondition`, `GlobalPosition`, `EventPage`, new `EventStore` interface | **Still the right shape, but must be re-scoped** | The shipped `EventStore` interface is public and §4 explicitly promises "custom implementations are unaffected — the interface did not change". A wholesale replacement breaks that promise a second time |
| Public API — `#[Tag]` attribute | **Still valid and still needed** | Nothing equivalent exists |
| Aggregate loading and saving via tag query | **Needs rework** | `EventSourcingRepository::save()` today just calls `appendTo()` and lets the unique index arbitrate. `findBy()` builds a `MetadataMatcher`. Both are small — that part is *easier* than the old spec assumed |
| Extension object — remove `with*PersistenceStrategy()`, add `withEventLogTableName()` | **Done / moot** | Strategies already gone. Table name comes from `#[Stream]`, not from configuration |
| CLI — `ecotone:migration:database:setup --feature=event_log` | **Superseded** | The feature is `event_stream` and it already exists |
| CLI — `migrate-aggregate-streams`, `audit-legacy-metadata` | **Superseded** | §4 answered 1.x migration with `legacyStreamName` (no migration at all) plus a hand-written `INSERT … SELECT` for stream-per-aggregate. No command was built and none is now needed for *that* problem. A **tag backfill** command may be needed — different problem, see Part 2 |
| CLI — `verify-gaps` | **Deferred with the gap work** | |
| Schema per platform — PostgreSQL / MySQL / MariaDB / SQLite | **Rewrite** | The shipped schema classes are `PostgresEventStreamSchema`, `MySqlEventStreamSchema`, `MariaDbEventStreamSchema` behind `EventStreamSchemaFactory`. There is **no SQLite schema** — the old spec assumed one. Tests use `InMemoryEventStore` |
| Query execution — the `event_tags` JOIN with `HAVING COUNT(DISTINCT tag_key)` | **Still the right technique**, applies to whatever side table we settle on | |
| Write shape — O(1) round trips in event count | **Still valid** | |
| UUID v7 | **Still valid, still undone** | Both `ramsey/uuid ^4.0` and `symfony/uid` are present. Unchanged question |
| `#[Version]` / `#[TargetVersion]` | **Reasoning stale in its premises** | It argued against counting events and for the CAS row. The shipped store still stamps `EVENT_AGGREGATE_VERSION` into metadata and still enforces it with a unique index, so "the version number is the concurrency token" is *more* entrenched than when the spec was written |
| Snapshots — `tag_local_seq > :snapshotVersion` | **Simplifies** | Post-snapshot load today is `findBy(..., fromVersion)` → a `_aggregate_version >= N` metadata match. That already works and needs no tag-local sequence *for aggregates* |
| Lock-hold duration | **Still valid and still important** | The transaction shape it describes has not changed. It is an argument *against* making the CAS row the aggregate concurrency mechanism |
| ProjectionV2 stream sources | **Rewrite against what shipped** | `#[FromStream(stream, aggregateType, eventStoreReferenceName)]` and `#[FromAggregateStream(aggregateClass)]` both exist. `StreamFilter` has `streamName`, `aggregateType`, `eventStoreReferenceName`, `eventNames`. Filtering is **in PHP**, not SQL — the old spec did not know this, and it is the strongest practical argument for tags |
| `AggregateIdPartitionProvider`'s per-platform `metadata->>'_aggregate_id'` SQL | **Still true, still a platform branch** | |
| Projection position translation (`single`/`partition` vs `aggregate`/`simple`) | **Superseded** | Those strategies no longer exist and §4's migration story is already published |
| DCB decision models — out of scope | **Open again** | With Prooph removal, the log unification and the package rename all done or dropped, the remaining budget is much smaller than the old spec assumed. This may now fit |
| Edge cases table | **Mostly survives**, minus every Prooph/migration row | |
| Migration / upgrade notes (the whole §4-shaped block) | **Delete** | §4 shipped and says something different. Carrying it forward would actively mislead |
| Implementation plan, steps 1–16 | **Delete steps 5, 11, 13, 14, 15, 16** | Prooph deletion, config rewrite, migration tooling and package rename are done, moot, or answered differently |
| Open question 6 (licence inconsistency) | **Resolved** | All three table managers and the repository are `Apache-2.0` |
| Open question 8 (package rename) | **Answered by inaction** | Package is still `ecotone/pdo-event-sourcing`. Out of scope here |
| Open question 12 (`ecotone:database:setup` naming) | **Resolved** | `ecotone:migration:database:setup` |

### What that leaves

Of the old spec's 16 implementation steps, six are gone. The remaining problem is narrower and sharper than the
document describes: **`ecotone_event_stream` exists, works, and enforces per-aggregate optimistic concurrency with
a unique index. DCB has to add tags, tag queries and a multi-tag append condition on top of it without breaking
that, without a second data migration, and without invalidating upgrade guide §4.**

---

## Part 2 — Decisions to make

*(populated as the discussion proceeds)*
