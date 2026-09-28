# DCB on `ecotone_event_stream` — 2.0 Design

Status: **agreed** (2026-09-23) — every decision in Part 5 is closed; Part 6 is the implementation plan
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
| Query execution — the `event_tags` JOIN with `HAVING COUNT(DISTINCT tag_name)` | **Still the right technique**, applies to whatever side table we settle on | |
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

## Part 2 — Maintainer direction (2026-09-20)

Four constraints set by the maintainer after reading Part 1. They are requirements, not preferences:

1. **Optimistic locking, not pessimistic database-level locks.** No advisory locks, no `SELECT … FOR UPDATE`, no
   `SERIALIZABLE`.
2. **Aggregate id and version stay in the stream table** and keep driving normal aggregate behaviour. The unique
   index on `(aggregate_type, aggregate_id, aggregate_version)` remains the aggregate's concurrency mechanism.
3. **Aggregate id and version may be null** for events appended without an aggregate. Which projections can see such
   events must be an explicit, enforced invariant.
4. **The schema must be upgradable while still on Ecotone 1.x.** Every change — new tables, new indexes, dropped
   constraints — has to leave a running 1.x application working, so users expand the schema first and upgrade the
   code second.

This settles the question Part 1 ended on: **DCB sits alongside aggregates.** It does not replace their concurrency
mechanism.

---

## Part 3 — How Axon does it on PostgreSQL, and what transfers

Source: `io.axoniq.framework:axoniq-postgresql` 5.3.2 (`PostgresqlEventStorageEngine`, `PostgresqlFinalizer`,
`PostgresqlSchemaInitializer`), read from the sources jar AxonIQ publishes to Maven Central, plus the
[RDBMS tuning guide](https://docs.axoniq.io/axon-framework-reference/5.1/tuning/rdbms-tuning/). The module is
commercially licensed (AxonIQ Terms of Service, evaluation use) — **we take ideas, never code or SQL text.** The
aggregate-based JPA engine in Axon 5 is explicitly *not* DCB-capable; this engine is the only relational DCB store
they ship, and it is PostgreSQL 16+ only.

### Their three tables

| Table | Shape | Role |
|---|---|---|
| `events` | `global_index` (identity, **increments by −1**), timestamp, identifier, type, type_version, payload, metadata | The log |
| `tags` | `(key, value, global_index)` primary key | One row per tag per event. Queries are a range scan on `(key, value)` joined back to `events`; multi-tag AND is `GROUP BY global_index HAVING COUNT(DISTINCT (key,value)) = n` |
| `consistency_tags` | `tag_hash INT4` primary key → `global_index` | **The conflict detector.** One row per *hash bucket* (2²⁰ buckets, MurmurHash3), holding the position of the last event that carried a tag hashing there. Bounded: it never grows with event volume |

### Their conditional append

A reader sources events for its criteria and receives a **consistency marker** — a global position. To append under
a condition, for every tag in the condition the engine runs an upsert on `consistency_tags` whose update is guarded
by *"the stored position is lower than my marker"*. If the guard holds for every tag, nothing relevant was written
since the read and the append goes through; the row now records the new event's position. If a guard fails, a
second, separate statement re-checks precisely against `tags`/`events` (the hash bucket may have collided, or the
newer event may carry the tag but not the type the criterion asked for); only a confirmed match rejects the append.

Four details matter and are worth stealing:

1. **Unconditional appends update the conflict table too.** Every event bumps the row of every tag it carries, even
   when appended with no condition. Without this a conditional writer cannot see an unconditional one — a bug other
   DCB stores have shipped ([ruby-dcb #42](https://github.com/kjeldahl/ruby-dcb/issues/42)).
2. **Tag rows are touched in sorted order** so two multi-tag appends cannot deadlock ABBA.
3. **Event types are never conflict-tracked on their own** — low cardinality would make every writer of a common
   type contend on one row. Type precision comes from the fallback re-check.
4. **The conflict table is separate from the tag index.** Reads never touch it; it stays small and hot.

### The part that does *not* transfer: commit-ordered positions

A position marker is only sound if positions are assigned in commit order. With a plain sequence they are not: a
writer can allocate position 95, stall, and commit *after* a reader has already seen position 100 — the reader's
marker (100) would wave through a conflict at 95. Axon closes this by inserting every event with a **temporary
negative index** and running a post-commit *finalizer* that renumbers committed events from a second, gapless
sequence — serialised behind `pg_advisory_xact_lock`, announced over `LISTEN/NOTIFY`, driven by a dedicated
single-thread executor in a long-lived JVM.

That cannot come to Ecotone:

| Axon mechanism | Why it fails here |
|---|---|
| Negative temporary `global_index`, renumbered after commit | 1.x reads `WHERE no >= 1`; rows written by 2.0 would be invisible to a 1.x node mid-upgrade. **Violates constraint 4** |
| Finalizer behind a global advisory lock | A database-level lock every append funnels through. It runs *after* commit, so it does not block the decision itself — but it is still the kind of mechanism constraint 1 rules out |
| Finalizer thread + `LISTEN/NOTIFY` connection | PHP has no long-lived process to own it. A worker dying between commit and finalize leaves committed events invisible until someone else appends |
| PostgreSQL 16+ only | Ecotone supports MySQL and MariaDB |
| Rewrites the primary key of every event once | Doubles write amplification on the hottest table |

**Conclusion: keep Axon's conflict-table design, replace its position marker with a per-tag version counter.** A
counter incremented inside the writer's own transaction is immune to out-of-order position allocation — it changes
when the writer *commits*, whatever `no` the writer was handed. This is also Marten's choice
(`mt_dcb_tag_version`), for the same reason.

---

## Part 4 — The solution

*Revision 2 — rewritten after the three reviews in Part 7. What changed and why is recorded there.*

### 4.1 Shape in one paragraph

A **decision model** is a small reusable class that answers one question about the past, rebuilt on demand from
the events selected *by tag* instead of by aggregate id. Message handlers — on services, on aggregates — declare
the models they need as parameters; the framework loads them and guarantees the events the handler returns are
appended only if none of those models has gone stale. `ecotone_event_stream` does not change. Two side tables are
added: **`ecotone_tagged_events`** (which event carries which tag — for reads) and **`ecotone_tag_versions`**
(one counter per tag value — for conflict detection). Events declare tags with `#[EventTag]`. Every append bumps
the counters of the tags it carries, then writes the events and their tag rows. A decision model's events are
appended on the condition that none of the counters it depends on moved since it read. Aggregates are untouched:
same columns, same unique index, same `#[Version]`.

### 4.2 Schema

**`ecotone_event_stream` and every `#[Stream]` table: no new columns, no new indexes.** That is what makes
constraint 4 cheap. PostgreSQL:

```sql
CREATE TABLE ecotone_tagged_events (
    tag_name     VARCHAR(100) NOT NULL,
    tag_value    VARCHAR(255) NOT NULL,
    stream_name  VARCHAR(128) NOT NULL,
    event_no     BIGINT       NOT NULL,
    tag_sequence BIGINT       NOT NULL,
    PRIMARY KEY (tag_name, tag_value, stream_name, event_no)
);

CREATE TABLE ecotone_tag_versions (
    tag_name   VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT       NOT NULL,
    PRIMARY KEY (tag_name, tag_value)
) WITH (fillfactor = 70);
```

MySQL / MariaDB: identical shape, `ENGINE=InnoDB ROW_FORMAT=DYNAMIC`, **`COLLATE utf8mb4_bin`** — the server
default `utf8mb4_0900_ai_ci` would merge `course:ABC` with `course:abc`. SQLite (supported engine, maintainer
2026-09-23): identical shape, `TEXT`/`INTEGER`, default `BINARY` collation (byte-exact, no PAD SPACE); needs
3.24+ for `ON CONFLICT DO UPDATE` and 3.35+ for `RETURNING`. There is **no SQLite stream schema today** — the
store has Postgres, MySQL and MariaDB classes only — so a `SqliteEventStreamSchema` (`no INTEGER PRIMARY KEY
AUTOINCREMENT`, expression indexes over `json_extract(metadata, '$._aggregate_id')` …) is a prerequisite that
this plan adds to task 3. SQLite serialises writers, so the guarded `UPDATE` never blocks — a loser gets 0 rows
immediately — and lock contention cannot be tested there; `busy_timeout` must be set or a second connection sees
`SQLITE_BUSY`, which is mapped to `ConcurrencyException` like the other engines' lock errors. Worst-case primary key is
400 + 1020 + 512 + 8 bytes, inside InnoDB's 3072-byte limit and PostgreSQL's btree tuple limit.

- **One pair per connection; counters are not keyed by stream.** On 1.x every aggregate type lives in its own
  `_<sha1>` table, and upgrade guide §4 tells users to leave them there. A cross-aggregate invariant — the whole
  point of DCB — therefore spans tables. The index records *which* table each event is in; the counter is about the
  tag alone. **A boundary may span every stream on one connection** (§4.5a). It cannot span connections: there is
  no transaction to hold it.
- `stream_name` is the physical table name. `event_no` is that table's `no`. `tag_sequence` is the version the tag's
  counter took in the append that wrote the event — an order stamp, not a version: it is copied once, never compared
  or incremented, and it is the same across every stream, which is what orders a model's events when they come from
  more than one table (§4.5a). Filter-only tags store 0.
- **Only tagged events are indexed.** An event whose class declares no `#[EventTag]` — by default, every aggregate
  event in an existing application — writes nothing to either table; aggregates keep loading through their own
  columns and never need their id as a tag. A tagged event writes one index row per tag value. *Estimated, not
  measured:* on PostgreSQL an index row is roughly 110 bytes of heap plus about as much in the primary key, so
  ~220 bytes per tag per event — 10 million events with two tags each ≈ 4–5 GB, against typically 10–20 GB for
  those events' own rows. InnoDB clusters the table on its primary key, so roughly half that. The `stream_name` string is
  the fattest part of the row (about a fifth of it for `ecotone_event_stream`, a third for a 41-character legacy
  `_<sha1>` name); replacing it with a small integer from a lookup table is a known optimisation, deliberately not
  taken in the first cut — it costs a join and debuggability — and is to be decided on measurements from task 5.
  The counter table holds one ~100-byte row per distinct counted tag *value*: a million customers is ~100–200 MB.
- No foreign keys. `EventStore::delete($stream)` deletes that stream's index rows in the same transaction — without
  this, a re-created stream restarts `no` at 1 and stale rows join to unrelated events (every test suite that resets
  streams would hit it). Counters are left: a stale counter can only cause one spurious retry.
- Tag values are validated in PHP before they reach SQL: non-empty, ≤ 255 characters, no trailing whitespace
  (`utf8mb4_bin` is PAD SPACE — `'abc'` equals `'abc '` on MySQL but not on PostgreSQL). Non-strict MySQL would
  otherwise truncate silently and the decision would silently miss events.
- Both tables register with `ecotone:migration:database:setup` under their **own feature, `event_tags`**,
  whose table manager reports `isUsed()` only when the application declares an `#[EventTag]`. DCB is Enterprise
  (§4.10): an open-core application never sees these tables in its setup output or its database. `--sql` prints
  them for a DBA; they obey §8 and inherit §4's open TODO for non-default connections.

### 4.3 Declaring tags

```php
use Ecotone\Api\Attribute\EventTag;

final readonly class StudentSubscribedToCourse
{
    public function __construct(
        #[EventTag('course')]  public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {}
}

final readonly class MoneyTransferred
{
    public function __construct(
        #[EventTag('account')] public string $fromAccountId,
        #[EventTag('account')] public string $toAccountId,
        public int $amount,
    ) {}
}

final readonly class SeatsReserved
{
    /** @param string[] $seatIds */
    public function __construct(#[EventTag('seat')] public array $seatIds) {}
}

#[EventTag('invoiceSequence', value: 'default')]
final readonly class InvoiceIssued
{
    public function __construct(public int $number) {}

    #[EventTag('customerEmail')]
    public function customerEmailHash(): string { /* ... */ }
}
```

- Targets: promoted constructor parameter, property, method (for computed or hashed values — mirrors
  `#[IdentifierMethod]`), and class with a literal `value` (for decisions that have no natural entity, like a
  gapless sequence).
- **The same key may appear more than once**, and a property may be an array of scalars — each value is one index
  row. Transfers, swaps and multi-seat reservations need this.
- Values are scalar, `Stringable`, or arrays of those. Anything else is a bootstrap `ConfigurationException`;
  `null` means "no tag". The registry is built once at bootstrap with
  `AnnotationFinder::findClassesWithAnnotatedProperties()`, the way DataProtection finds `#[Sensitive]`.
- Tags are resolved from the event object **above the serializer** (`SerializingEventStore` runs before the store
  sees the event) and handed down with the event. Array payloads — `EventStreamEmitter`, raw `appendTo` — are
  resolved by event name if the class is known, and otherwise carry no tags.
- Tags are not copied into `metadata`. The index is the single source, so a backfill never rewrites an event.
- `#[EventTag]` works on any event, including those recorded by an `#[EventSourcingAggregate]`. That is how a
  decision model includes aggregate-produced facts in its boundary. It is the **only** place the attribute
  appears; models are scoped by tag names (§4.4), commands may carry it to say which property supplies a value.
  An event with no `#[EventTag]` can therefore never be folded by a decision model: handling one leaves the model
  without a tag name and is rejected at bootstrap (§4.4).
- Tags are stored in plaintext beside the payload and appear in diagnostics. Do not tag personal data directly —
  tag a hash through a method.

**Filter-only tags.** Every tag an event carries bumps a counter row that is then held until commit. A
low-cardinality tag (`tenant`, `region`) would make most writers queue behind each other. Declared once per key,
so two event classes can never disagree. The declaration lives on the object that enables DCB
(`DynamicConsistencyBoundaryConfiguration`, §4.9), so enabling and configuring DCB happen in one place:

```php
#[ServiceContext]
public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
{
    return DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withFilterOnlyTags(['tenant']);
}
```

A filter-only tag is indexed and never counted. A decision model that depends on one is a bootstrap
`ConfigurationException`.

### 4.4 Decision models

*Revision 3 — maintainer direction, 2026-09-21: a decision model is a standalone, reusable class, like an
aggregate, and is **injected into message handlers**. Whatever a handler injects, the framework keeps consistent.
Revision 4 — maintainer direction, 2026-09-23: a model declares the **names** of the tags that scope it, not
properties holding their values; `#[EventTag]` appears on events only.*

**A decision model is one question about the past, answered by folding events selected by tag.** It owns state and
the methods that read it. It does not own the command.

```php
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;

#[DecisionModel]                                   // tags inferred: 'course' (see below)
final class CourseCapacity
{
    private int $capacity = 0;
    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefined $event): void { $this->capacity = $event->capacity; }

    #[EventSourcingHandler]
    public function capacityChanged(CourseCapacityChanged $event): void { $this->capacity = $event->capacity; }

    #[EventSourcingHandler]
    public function seatTaken(StudentSubscribedToCourse $event): void { $this->seatsTaken++; }

    public function hasFreeSeat(): bool { return $this->seatsTaken < $this->capacity; }
}

#[DecisionModel(tags: ['student'])]               // explicit: the only handled event carries 'course' too
final class StudentCourses
{
    private int $courses = 0;

    #[EventSourcingHandler]
    public function joined(StudentSubscribedToCourse $event): void { $this->courses++; }

    public function canJoinAnother(): bool { return $this->courses < 5; }
}

#[DecisionModel]                                   // tags inferred: 'course' AND 'student'
final class StudentSubscription
{
    private bool $exists = false;

    #[EventSourcingHandler]
    public function subscribed(StudentSubscribedToCourse $event): void { $this->exists = true; }

    public function exists(): bool { return $this->exists; }
}
```

The handler is an ordinary message handler that asks for the models it needs:

```php
final class CourseSubscriptions
{
    #[CommandHandler]
    public function subscribe(
        SubscribeStudentToCourse $command,
        CourseCapacity $course,
        StudentCourses $student,
        StudentSubscription $subscription,
    ): array {
        if ($subscription->exists()) {
            return [];
        }
        if (! $course->hasFreeSeat()) {
            throw new CourseIsFull($command->courseId);
        }
        if (! $student->canJoinAnother()) {
            throw new StudentHasTooManyCourses($command->studentId);
        }

        return [new StudentSubscribedToCourse($command->courseId, $command->studentId)];
    }

    #[CommandHandler]
    public function changeCapacity(ChangeCourseCapacity $command, CourseCapacity $course): array
    {
        if ($course->seatsTaken() > $command->capacity) {
            throw new CapacityBelowSubscriptions();
        }

        return [new CourseCapacityChanged($command->courseId, $command->capacity)];
    }
}
```

`CourseCapacity` is written once and used by both handlers. No query object, no store call, no append condition,
no `if ($event->courseId === $this->courseId)`, and the model holds **only the state it needs** — it never carries
`$courseId` unless one of its own `#[EventSourcingHandler]`s chooses to assign it from an event. `#[EventTag]` has
exactly one meaning, on events: *publish this value under this name*. A model is scoped by tag **names**:

> **A model is one criterion: its tag names, AND-ed, with the event types it handles. The names come from
> `#[DecisionModel(tags: [...])]`, or by default are the tags that _every_ handled event carries. A handler's
> consistency boundary is the OR of the models it injects.**

**Why the default is the intersection, not the union.** `CouponRedemptions` (§4.5b) folds `CouponIssued`
(`coupon`) and `OrderPlaced` (`customer`, `coupon`). The union would scope it by `customer` *and* `coupon` — a
different, wrong question ("this customer's redemptions"), and one the command could not always answer. The
intersection, `coupon`, is the largest set of names every handled event can be matched on, which is exactly the
rule a model must satisfy anyway (a handled event that lacks one of the model's tags could never reach it). When
the intersection is not what is wanted — `StudentCourses` handles only `StudentSubscribedToCourse`, whose tags are
`course` and `student`, but the question is about the student alone — `tags:` says so, and a listed name absent
from any handled event is a bootstrap `ConfigurationException`. So is a model left with **no** tag name — no
`tags:` and handled events that share no `#[EventTag]` name, a handled event carrying none included: it would fold
no event and guard nothing, and the message names the model, each handled event with the tags it carries, and the
two remedies (tag the event, or give the model `tags:`).

| Injected model | Criterion |
|---|---|
| `CourseCapacity` | `course:<courseId> ∧ {CourseDefined, CourseCapacityChanged, StudentSubscribedToCourse}` |
| `StudentCourses` | `student:<studentId> ∧ {StudentSubscribedToCourse}` |
| `StudentSubscription` | `course:<courseId> ∧ student:<studentId> ∧ {StudentSubscribedToCourse}` |

This is exactly the dcb.events query shape, and it is what wwwision and patchlevel make users assemble by hand from
projection objects. Here the type-hint *is* the assembly. The boundary is per handler by construction —
`changeCapacity` depends on one tag, `subscribe` on two — which revision 2 had to bolt on.

**How it runs.** For a handler with injected models the framework: resolves every model's tag values from the
message → captures all their counters in one statement → reads the index once for all criteria → loads each
matching event once and applies it to every model whose criterion it matches → invokes the handler → appends the
returned events under the condition built from *all* injected models → publishes them on the event bus with the
usual metadata propagation. Three models cost the same three statements as one. This one-load-per-handler
guarantee is protected by code review, not by a test: tests observe Ecotone only through userland, and statement
counts are not observable there.

**Tag values come from the message** by tag *name*, the way aggregate identifiers do: a message property carrying
`#[EventTag('course')]`; else a message property named `course`, `courseId` or `course_id`; else an explicit
expression, reusing the attribute that already injects aggregates into handlers (`#[Fetch]`, Enterprise,
`FetchAggregateConverter`):

```php
#[CommandHandler]
public function transfer(
    TransferMoney $command,
    #[Fetch('payload.fromAccountId')] AccountBalance $from,
    #[Fetch('payload.toAccountId')]   AccountBalance $to,
): array {
    if ($from->balance() < $command->amount) {
        throw new InsufficientFunds();
    }

    return [new MoneyTransferred($command->fromAccountId, $command->toAccountId, $command->amount)];
}
```

The same model class twice, with different values — the case convention alone cannot resolve. For a multi-tag
model the expression returns a map keyed by tag name, as `#[Fetch]` already accepts for multi-identifier
aggregates. An array value selects several values of one key. A tag that
cannot be resolved is a bootstrap error when statically knowable, otherwise an exception naming model, tag and
message.

**A tag value that resolves to `null`** (an order placed without a coupon) follows the rule `#[Fetch]` already
applies to aggregates: a nullable parameter (`?CouponRedemptions $coupon`) receives `null` and contributes nothing
to the boundary; a non-nullable one throws, naming the model and the tag.

**Where models can be injected**

| Handler | Returned array | Consistency |
|---|---|---|
| `#[CommandHandler]` / `#[EventHandler]` on a service | appended as events under the condition | guaranteed for those events |
| `#[CommandHandler]` on a `#[DecisionModel]` class | **not supported** (maintainer, 2026-09-23) | one shape only: models are injected, they never own commands |
| `#[QueryHandler]` | the reply, untouched | none needed — a live, always-current read of "how many seats are left" with no projection to maintain |
| `#[CommandHandler]` on an `#[EventSourcingAggregate]` | the aggregate's events, saved as today **and** under the models' condition | both checks, one transaction — see below |

A handler that injects a model and also declares `outputChannelName` appends and publishes the events, then
forwards them to the output channel as any handler would (maintainer, 2026-09-23: output-channel behaviour is
kept). `return []` is a no-op. **Consistency protects the events the handler
returns** — a handler that reads a model and then writes somewhere else (a state-stored entity, an HTTP call) has
read a consistent snapshot but has no guarantee at write time, and the docs must say so plainly.

**Aggregates can inject decision models.** This is the adoption path for every existing application, and the thing
the 1.x-user review asked for:

```php
#[EventSourcingAggregate]
final class Order
{
    #[CommandHandler]
    public static function place(PlaceOrder $command, CouponRedemptions $coupon): array
    {
        if ($command->couponCode !== null && $coupon->isExhausted()) {
            throw new CouponExhausted();
        }

        return [new OrderPlaced($command->orderId, $command->couponCode)];   // OrderPlaced: #[EventTag('coupon')]
    }
}
```

`Order` stays an aggregate: id, `#[Version]`, unique index, partitioned projections, all as today — constraint 2 is
untouched. The injected model adds a *second* guard on the same append: the events are saved with their aggregate
version **and** on the condition that `coupon:<code>` has not moved. Either check failing raises
`ConcurrencyException`. Mechanically the condition travels to `EventSourcedRepository::save()` in its `$metadata`
argument and is stripped before persisting, so that interface does not change. Because the recorded events carry
aggregate metadata, the projection invariant of §4.6 never triggers for them.

**Rules.** A model class has a public no-argument constructor (what `EventSourcingHandlerExecutor` requires of
aggregates). Every `#[EventSourcingHandler]` event class must declare all of the model's tag names — otherwise the
model could never receive it; bootstrap `ConfigurationException` (the inferred default satisfies this by
construction). A model that needs a tag value in its state assigns it in a handler (`$this->courseId =
$event->courseId`) — the framework never writes into a model. Interface or union handler parameters are
rejected for the same reason. A model with no tagged property is allowed only if it handles events carrying a
class-level `#[EventTag(…, value: …)]` (the gapless-sequence case). Pointcuts target `DecisionModel::class`.
`#[Reference]`, `#[Header]` and `#[Asynchronous]` work as on any handler; models are loaded when the handler
runs, after the channel.

**Escape hatch**, for a boundary no model expresses — a static method on the handler's class:

```php
#[DecisionBoundary]
public static function boundary(RateCourse $command): EventCriteria { /* … */ }
```

`DecisionBoundaryEvaluator` (Enterprise) owns the escape hatch: it discovers boundary methods at bootstrap — a
boundary is matched to the `#[CommandHandler]`/`#[EventHandler]` of its class whose **first parameter has the same type
as the boundary's first parameter** — and, when the handler returns, calls the static method with the handler's
command and loads by the criteria it returns. **Cost:** the one-load-per-handler guarantee (see "How it runs" above) covers injected
models only. A boundary is a **second, separate `loadByCriteria()`** on top of the batched model load, run inside the
same transaction, and its condition is merged into the handler's `AppendCondition`. It is not folded into the batch
because the batch runs as a before-interceptor over the message, while a boundary takes the handler's converted first
argument; a handler with only a boundary has no batch at all. Correctness is unaffected — only an extra read.

**Testing** needs nothing new — tags are on the events:

```php
EcotoneLite::bootstrapFlowTesting([CourseSubscriptions::class, CourseCapacity::class, StudentCourses::class, StudentSubscription::class])
    ->withEvents([new CourseDefined('c1', capacity: 1), new StudentSubscribedToCourse('c1', 's1')])
    ->sendCommand(new SubscribeStudentToCourse('c1', 's2'));   // expects CourseIsFull
```

and a model is a plain class, so its fold is unit-testable with `new CourseCapacity()` and no framework at all.
`InMemoryEventStore` implements the full contract — index, counters bumped on *every* append, compare-then-append
— so flow tests exercise real conditional-append semantics without a database. A conflict is forced
deterministically by injecting a service that appends a competing event on first call.

The in-memory classes mirror the DBAL ones one for one, so the two implementations can be compared by name:

| Role | DBAL | In-memory |
|---|---|---|
| Enterprise composition root | `EnterpriseDbalTagCollaborator` | `EnterpriseInMemoryTagCollaborator` |
| open-core implementation | `OpenCoreDbalTagCollaborator` | `OpenCoreInMemoryTagCollaborator` |
| counters | `DbalTagVersionRegister` | `InMemoryTagVersionRegister` |
| index | `DbalTagIndex` | `InMemoryTagIndex` |
| append, read | `DbalTagConditionalAppender`, `DbalTaggedEventReader` | inline in `EnterpriseInMemoryTagCollaborator` |
| backfill | `DbalTagBackfiller` | none — in-memory events are never written without their tags |

The two asymmetries are by design: the in-memory collaborator *holds* the tag state (the database holds it for DBAL),
and there is nothing to backfill.

**Without any class**, the same machinery is a gateway on the store itself — **revision 5, maintainer direction,
2026-09-27: there is one `EventStore`, not a second `TaggedEventStore` interface beside it.** `loadByCriteria()` and
`appendTo()`'s optional `AppendCondition` moved onto `Ecotone\EventSourcing\EventStore` directly, alongside
`loadAggregateEvents()` (folded in from the now-deleted `AggregateEventStore`):

```php
$decision = $eventStore->loadByCriteria(
    EventCriteria::tag('course', $courseId)->ofTypes(CourseDefined::class, StudentSubscribedToCourse::class)
);
// fold $decision->events
$eventStore->appendTo('ecotone_event_stream', [new StudentSubscribedToCourse(...)], $decision->appendCondition);
```

`loadByCriteria()` returns the events **and** the ready-made condition; user code never touches a version.
`EventStore` lives in core (`packages/Ecotone`), as do the attributes — `InMemoryEventStore` and the decision-model
flow are core, and core cannot depend on `PdoEventSourcing`. Only schema, DBAL implementation and the console
commands live in `PdoEventSourcing`. `loadByCriteria(EventCriteria $criteria)` takes a single, non-variadic
argument — an OR of several criteria is expressed on `EventCriteria` itself (`EventCriteria::tag(...)->or(...)`) —
so it stays reachable through the `EventStore` *gateway* (`GatewayProxyBuilder`) the same way every other
`EventStore` method is; `$eventStore` above is whatever `EventStore` the container hands you, gateway or concrete
store alike.

Framework callers — the decision-model load and append interceptors — do not go through that gateway. They take
`EventStore::RAW_REFERENCE`, the concrete store: a gateway call re-enters the messaging bus, and the load and the
guarded append must stay inside the caller's own transaction and on its connection, which a nested bus invocation
would not guarantee. Users get the gateway; the framework gets the store. §4.9 covers the licence split this merge introduces: the aggregate part of `AppendCondition` is
open-core, only the tag part requires Enterprise.

**Where events go:** the default stream, or a `#[Stream]` on the handler's class or — new, maintainer 2026-09-23
— on the **handler method** itself (`Stream` gains `TARGET_METHOD`; the method wins over the class); for an
aggregate, the aggregate's stream. Events returned by a non-aggregate handler carry no aggregate id, type or version (§4.6).

### 4.5 Concurrency, in full

**Invariant, maintained by every append — conditional or not, decision model or aggregate:** for each distinct
counted tag the appended events carry, `ecotone_tag_versions.version` is incremented by one in the same
transaction as the event insert, always through the guarded `UPDATE` below — never a blind upsert (2026-09-28). Without the unconditional half, a conditional writer cannot see an unconditional
one ([ruby-dcb #42](https://github.com/kjeldahl/ruby-dcb/issues/42)).

**Read side — capture first, then read:**

```sql
-- 1. capture (a missing row is version 0)
SELECT tag_name, tag_value, version FROM ecotone_tag_versions
WHERE (tag_name, tag_value) IN ((:k1, :v1), (:k2, :v2));

-- 2. which events, in which stream, matching which criterion
SELECT stream_name, event_no, MAX(tag_sequence) ...,   -- per guard tag, see §4.5a
       MAX(CASE WHEN tag_name = :k1 AND tag_value = :v1 THEN 1 ELSE 0 END) AS has_t1,
       MAX(CASE WHEN tag_name = :k2 AND tag_value = :v2 THEN 1 ELSE 0 END) AS has_t2
FROM ecotone_tagged_events
WHERE (tag_name, tag_value) IN ((:k1, :v1), (:k2, :v2))
GROUP BY stream_name, event_no;

-- 3. per stream: the events themselves
SELECT no, event_name, payload, metadata, created_at FROM <stream>
WHERE no IN (:nos) AND event_name IN (:types) ORDER BY no;
```

Statement 2 drives from the primary key and yields each event **once**, however many criteria it matches — a
`UNION` would double-apply `StudentSubscribedToCourse(c1, s1)`, and `DISTINCT` over `payload` fails outright on
PostgreSQL (`json` has no equality operator). Criteria with event-type and AND conditions are evaluated in PHP from
the flags. Each model is folded in the order of **its own guard tag's `tag_sequence`, then `event_no`** — exact
commit order for that tag, across streams (§4.5a).

Capture-before-read matters. A writer committing between the statements makes the events *newer* than the captured
version: a spurious retry, safe. The opposite order pairs a newer version with older events and **misses** the
conflict.

**Write side.** A tagged append is several statements that must commit together, so it **requires an active
transaction and never opens one itself** (maintainer, 2026-09-28). Transactions belong to `DbalTransactionInterceptor`
(`DbalConfiguration::withTransactionOnCommandBus/AsynchronousEndpoints/ConsoleCommands`); when a tagged append, a
conditional append or `backfill-tags` runs with none active, the store throws a `ConfigurationException` naming the
switch to enable. Handlers under `#[WithoutDatabaseTransaction]`, streams on a non-default connection and direct
gateway calls must open one explicitly. Untagged appends stay today's single atomic `INSERT` and need none.

```sql
-- A. ONE pass over the union of (condition tags ∪ tags of the new events), sorted by (tag_name, tag_value).
--    Per tag, one of:

--    in the condition, captured > 0
UPDATE ecotone_tag_versions SET version = version + 1
WHERE tag_name = :k AND tag_value = :v AND version = :captured;          -- 0 rows → ConcurrencyException

--    in the condition, captured = 0
INSERT INTO ecotone_tag_versions (tag_name, tag_value, version) VALUES (:k, :v, 1)
ON CONFLICT DO NOTHING;                                                  -- 0 rows → ConcurrencyException
--    MySQL/MariaDB: plain INSERT, duplicate key → ConcurrencyException

--    not in the condition
INSERT INTO ecotone_tag_versions (tag_name, tag_value, version) VALUES (:k, :v, 1)
ON CONFLICT (tag_name, tag_value) DO UPDATE SET version = ecotone_tag_versions.version + 1;
--    MySQL/MariaDB: ON DUPLICATE KEY UPDATE version = version + 1

-- B. events
INSERT INTO <stream> (event_id, event_name, payload, metadata, created_at) VALUES ...;

-- C. tag index, resolving no by the unique event_id
INSERT INTO ecotone_tagged_events (tag_name, tag_value, stream_name, event_no, tag_sequence)
SELECT :k, :v, :streamName, no, :newVersion FROM <stream> WHERE event_id = :eventId;
```

`:newVersion` is `captured + 1` on every append path — no second read. Only the backfill still uses the blind
upsert, and there the new version comes from `RETURNING version` (PostgreSQL, MariaDB, SQLite) or a plain `SELECT` of
the row the transaction has just written (MySQL).

**Counters first, events second.** Three reasons. (1) A lost condition has written nothing — no burned `no` for
`GapAwarePosition` to chase, and nothing for an outer transaction to commit by accident if user code swallows the
exception around a nested `send`. (2) `no` is now allocated *under* the tag's row lock, so **for any one tag, `no`
order equals commit order** — which is what makes per-tag snapshots and tag-partitioned projections sound later.
(3) Losers fail before they write.

**One merged sorted pass**, not "condition tags, then the rest": an aggregate save carrying `{A, B}` locks A→B,
while a model conditioned on `{B}` whose event carries `{A, B}` would lock B→A — an ABBA deadlock. Step C uses
the `event_id` sub-select rather than `LAST_INSERT_ID() + i`, which breaks under Galera and group replication
(`auto_increment_increment ≠ 1`). The guarded check is a plain `UPDATE`, identical on all engines and immune to
MySQL's `CLIENT_FOUND_ROWS` affected-rows semantics.

#### 4.5a Models fed from different event streams

*Maintainer question, 2026-09-21.* The common 1.x case: `CourseDefined` was recorded by a `Course` aggregate into
`_<sha1('Course')>`, `StudentSubscribedToCourse` lives in `ecotone_event_stream`, and `CourseCapacity` folds both.
Or a handler injects `CouponRedemptions` (coupon stream) and `CustomerCredit` (customer stream) together.

**Same connection — yes, fully, and nothing extra to configure.**

- *Conflict detection never looks at streams.* The counter is keyed by tag alone and lives in one table per
  connection. Whichever stream an append targets, it bumps the counters of its tags **in the same database
  transaction** as its event insert. A handler's condition is one set of guarded `UPDATE`s on that one table,
  whatever mix of streams its models read. The proof in this section does not change by a word.
- *Discovery is from data.* The index query is not filtered by stream; its rows say which tables hold matching
  events. Nobody declares which streams a model reads.
- *Order is exact, per model.* Revision 2 ordered cross-stream events by `created_at`. That is not good enough:
  `created_at` is application-assigned, often at one-second resolution, and a fold like "capacity changed, then a
  seat was taken" is order-sensitive. But counters-first ordering already serialises every append of a tag behind
  that tag's row lock, **across all streams** — so the counter value an append produced is a gapless, commit-ordered
  sequence for that tag. Storing it in the index row (`tag_sequence`) costs one column and gives each model a total
  order over its events no matter how many tables they came from: `ORDER BY tag_sequence, event_no` (one append goes
  to one stream, so `event_no` orders within it). A multi-tag model uses its guard tag's sequence. Models in one
  handler are folded independently, each in its own order.
- *Backfilled history* has no commit order to recover. The backfill assigns `tag_sequence` in
  `(created_at, stream, no)` order — the best available, the same rule multi-stream projections use today — and
  everything appended afterwards is exact.

**Different connections — no, and it must fail loudly rather than quietly.** A stream declared with
`#[Stream(connectionReferenceName: 'other')]` lives in another database: there is no transaction that can hold a
counter update there and an event insert here, and its index rows are in *its* connection's tag tables, so a model
loaded for a handler writing to the default connection would simply not see those events — a silent wrong decision,
the failure mode this design refuses everywhere else. So: a handler's models are loaded from, and its condition
enforced on, the connection of the stream the handler appends to. At bootstrap, every event class a model handles is
traced to the aggregates that record it (their `#[EventSourcingHandler]`s reveal this) and to their `#[Stream]`
connection; a model injected into a handler whose write stream is on a different connection is a
`ConfigurationException` naming both. Events recorded only by service handlers cannot be traced statically; for
those the rule is documented. Cross-database consistency is a saga, not a consistency boundary.

Multi-tenancy is the same rule seen from the other side: each tenant's connection has its own tag tables, and a
boundary lives inside one tenant.

#### 4.5b Worked example — a coupon limited to two redemptions

A shop issues coupon `SUMMER24`, valid for **2 orders in total** and **once per customer**. Orders are an ordinary
event-sourced aggregate. The limit spans all orders, so no `Order` instance can own it.

```php
final readonly class CouponIssued {
    public function __construct(#[EventTag('coupon')] public string $code, public int $limit) {}
}
final readonly class OrderPlaced {
    public function __construct(
        public string $orderId,
        #[EventTag('customer')] public string $customerId,
        #[EventTag('coupon')]   public ?string $couponCode,
    ) {}
}

#[DecisionModel]                                      // tags inferred: coupon (the only tag both events carry)
final class CouponRedemptions {                       // criterion: coupon:<code>
    private int $limit = 0; private int $used = 0;
    #[EventSourcingHandler] public function issued(CouponIssued $e): void { $this->limit = $e->limit; }
    #[EventSourcingHandler] public function redeemed(OrderPlaced $e): void { $this->used++; }
    public function isExhausted(): bool { return $this->used >= $this->limit; }
}

#[DecisionModel(tags: ['customer', 'coupon'])]      // criterion: customer:<id> AND coupon:<code>; first = guard tag
final class CustomerCouponUse {
    private bool $used = false;
    #[EventSourcingHandler] public function redeemed(OrderPlaced $e): void { $this->used = true; }
    public function alreadyUsed(): bool { return $this->used; }
}

#[EventSourcingAggregate]
final class Order {
    #[CommandHandler]
    public static function place(PlaceOrder $c, ?CouponRedemptions $coupon, ?CustomerCouponUse $usage): array {
        if ($coupon?->isExhausted()) { throw new CouponExhausted(); }
        if ($usage?->alreadyUsed())  { throw new CouponAlreadyUsedByCustomer(); }
        return [new OrderPlaced($c->orderId, $c->customerId, $c->couponCode)];   // no coupon → models are null
    }
}
```

**How `SUMMER24` gets from the command to the models, and from the event into the index.** At bootstrap the
framework reads `#[EventTag]` off the event classes and derives each model's tag names:

| Class | Derived |
|---|---|
| event `CouponIssued` | `coupon` ← property `code` |
| event `OrderPlaced` | `customer` ← `customerId`; `coupon` ← `couponCode` |
| model `CouponRedemptions` | names: `{coupon}` — intersection of its handled events' tags |
| model `CustomerCouponUse` | names: `{customer, coupon}` — declared |

`PlaceOrder('o-1', 'alice', 'SUMMER24')` arrives → routed to `Order::place` → the handler's parameters include two
`#[DecisionModel]` classes → for each model tag *name* the framework looks on the command for a property carrying
`#[EventTag('coupon')]`, else one named `coupon`/`couponCode` → finds `SUMMER24` and `alice` → captures counters,
reads the index for `coupon:SUMMER24` and `customer:alice`, instantiates the models with `new`, and folds. The
models receive nothing but events.
When the handler returns `OrderPlaced('o-1', 'alice', 'SUMMER24')`, the store looks the class up in the event map,
reads `customerId` and `couponCode` off the object — before it is serialized — and gets the two rows to write:
`(customer, alice)` and `(coupon, SUMMER24)`. A `null` property writes no row.

**① The coupon is issued** (event 1, written through a handler that injected `CouponRedemptions` to refuse a
duplicate issue — captured version 0, so the guarded step is the `INSERT`).

`ecotone_event_stream`

| no | event_name | payload | aggregate_id / version |
|---|---|---|---|
| 1 | CouponIssued | `{code: SUMMER24, limit: 2}` | *null / null* |

`ecotone_tagged_events`

| tag_name | tag_value | stream_name | event_no | tag_sequence |
|---|---|---|---|---|
| coupon | SUMMER24 | ecotone_event_stream | 1 | 1 |

`ecotone_tag_versions`

| tag_name | tag_value | version |
|---|---|---|
| coupon | SUMMER24 | **1** |

**② Alice places order `o-1` with the coupon.** Nothing is locked during a–c.

| Step | SQL | Result |
|---|---|---|
| a. capture | `SELECT … FROM ecotone_tag_versions WHERE (tag_name,tag_value) IN ((coupon,SUMMER24),(customer,alice))` | coupon:SUMMER24 = **1**, customer:alice = **0** (no row) |
| b. read index | `… FROM ecotone_tagged_events WHERE (tag_name,tag_value) IN (…) GROUP BY stream_name, event_no` | event 1 — has `coupon`, not `customer` |
| c. fold + decide | `CouponRedemptions`: limit 2, used 0. `CustomerCouponUse` needs both tags → event 1 not applied → unused | returns `OrderPlaced(o-1, alice, SUMMER24)` |
| d. guard, sorted | `UPDATE … SET version = version+1 WHERE coupon/SUMMER24 AND version = 1` | **1 row** → 2 |
| | `INSERT … (customer, alice, 1) ON CONFLICT DO NOTHING` | **1 row** |
| e. event | `INSERT INTO ecotone_event_stream …` with `_aggregate_id = o-1`, `_aggregate_version = 1` | no = 2; the aggregate unique index is checked here, as today |
| f. index | two rows for event 2 | |
| g. `COMMIT` | | row locks from step d released |

`ecotone_tagged_events` now:

| tag_name | tag_value | stream_name | event_no | tag_sequence |
|---|---|---|---|---|
| coupon | SUMMER24 | ecotone_event_stream | 1 | 1 |
| coupon | SUMMER24 | ecotone_event_stream | 2 | 2 |
| customer | alice | ecotone_event_stream | 2 | 1 |

`ecotone_tag_versions`: coupon:SUMMER24 = **2**, customer:alice = **1**.

**③ Bob (`o-2`) and Carol (`o-3`) race for the last redemption.**

| | Bob | Carol |
|---|---|---|
| capture | coupon = **2**, customer:bob = 0 | coupon = **2**, customer:carol = 0 |
| read + fold | used 1 of 2 → OK | used 1 of 2 → OK |
| guard `coupon` | `UPDATE … WHERE version = 2` → 1 row (now 3, uncommitted) | `UPDATE … WHERE version = 2` → **blocks on Bob's row** |
| | inserts customer:bob, event 3, index rows | *waiting* |
| | `COMMIT` | unblocked; the database re-checks `version = 2` against the committed row: it is 3 → **0 rows** |
| | | `ConcurrencyException`. Carol has inserted **nothing** — no event, no `no` burned, no customer row. Rollback |
| | | **retry** (automatic, new transaction): capture coupon = 3 → read events 1, 2, 3 → used 2 of 2 → `CouponExhausted` |

Carol gets a business answer, not a technical one. Had Bob's transaction rolled back instead, Carol's `UPDATE`
would have found version 2 and gone through.

Final state:

`ecotone_event_stream`

| no | event_name | aggregate_id / version |
|---|---|---|
| 1 | CouponIssued | *null / null* |
| 2 | OrderPlaced (alice) | o-1 / 1 |
| 3 | OrderPlaced (bob) | o-2 / 1 |

`ecotone_tagged_events`

| tag_name | tag_value | event_no | tag_sequence |
|---|---|---|---|
| coupon | SUMMER24 | 1 | 1 |
| coupon | SUMMER24 | 2 | 2 |
| coupon | SUMMER24 | 3 | 3 |
| customer | alice | 2 | 1 |
| customer | bob | 3 | 1 |

`ecotone_tag_versions`: coupon:SUMMER24 = **3**, customer:alice = 1, customer:bob = 1.

**What the example shows.** *Two guards, side by side:* the counter protects the coupon limit; the aggregate's
unique index still protects each `Order` (two commands racing on `o-1` collide on `(Order, o-1, version)` exactly
as today). *Disjoint decisions never wait:* an order using `WINTER24` touches `coupon:WINTER24` — a different row.
*An unrelated event with a shared tag does cost a retry:* a `CustomerAddressChanged` tagged `customer:bob`
committing during Bob's decision would bump `customer:bob` and send Bob round once more — the over-approximation,
and the reason to tag only what a decision needs. *Unconditional writers count too:* if `OrderPlaced` were recorded
by a handler that injected no model, step d would be an unguarded `version = version + 1` — and Carol would still
be stopped.

**Why this is optimistic, and atomic.** Nothing is locked while the model reads and decides. Check and write are
one statement: the `UPDATE` finds the row, verifies `version = :captured` and changes it. Two subscriptions racing
for the last seat of `c1`:

| | T1 | T2 |
|---|---|---|
| read | `course:c1` = 7, 9 of 10 seats | `course:c1` = 7, 9 of 10 seats |
| decide | room → subscribe | room → subscribe |
| append | `UPDATE … WHERE version = 7` → 1 row | `UPDATE … WHERE version = 7` → **waits** on T1's row |
| | insert event, `COMMIT` | re-evaluates against the committed row: 8 ≠ 7 → **0 rows** |
| | | `ConcurrencyException`, nothing written → retry reads 10 of 10 → `CourseIsFull` |

If T1 rolls back, T2 finds 7 and proceeds. PostgreSQL `READ COMMITTED` re-checks the waiting statement's `WHERE`
against the newly committed row; an InnoDB `UPDATE` is a current read regardless of the transaction's snapshot.
No raised isolation level, no advisory lock, no `SELECT … FOR UPDATE`.

**Two tables, two write patterns — and why the counter is an `UPDATE`.** `ecotone_tagged_events` is a *list*: which
events carry a tag. It is append-only; every tagged event INSERTs its rows and nothing is ever updated — an update
would erase the earlier events from the boundary. `ecotone_tag_versions` is a *register*: one row per tag
value, INSERTed the first time the value appears and UPDATEd in place ever after.

The obvious alternative is to make the register insert-only as well — put `UNIQUE (tag_name, tag_value,
tag_sequence)` on the index, let a conditional writer insert `captured + 1`, and treat a unique violation as the
conflict. That is exactly how aggregates work today, it would remove a table, and it was considered seriously.
It fails on one case aggregates never have: **a writer with no expected version.** Every aggregate save knows the
version it loaded. A tagged event appended by a handler that injected no model does not know the tag's version —
and it has no condition, so it must never fail. Insert-only, it has to compute `MAX(tag_sequence) + 1` and insert
it, racing every other such writer for the same number: on PostgreSQL the loser's unique violation aborts its
whole transaction (or needs a savepoint-and-retry loop inside the store); on InnoDB `INSERT … SELECT MAX()` takes
next-key locks on the very gap both writers then insert into — the textbook deadlock. With a register the same
writer runs `version = version + 1`, which cannot fail and cannot deadlock; it only queues. Filter-only tags
(which carry no version) would also break the unique key.

Nothing is given up for it. An insert of a duplicate key waits for the uncommitted first inserter exactly as an
`UPDATE` waits for the uncommitted first updater, so lock reach and lock duration are identical; the register is
no more "pessimistic" than the aggregate's unique index. The one real cost is PostgreSQL's: each in-place update
leaves a dead tuple. `version` is not indexed, so these are HOT updates, `fillfactor = 70` leaves room for them on
the page, and autovacuum reclaims them — and the table is tiny.

**Alternative reviewed (maintainer, 2026-09-23): drop the per-event index rows, keep only the guarded `UPDATE`.**
The guarded `UPDATE … WHERE version = :captured → 0 rows = conflict` *is* the design's locking mechanism, and it
already creates no row per action: `ecotone_tag_versions` has one row per tag value, updated in place. The
per-event rows are in the *other* table, and they exist for the read side only — the store has to find the events
a model folds. Removing them means storing tags on the event row instead, in `metadata` (`_tags: {coupon:
[SUMMER24], customer: [alice]}`), indexed by an expression index on `metadata->'_tags'` (rows without tags produce
no index entries, so untagged aggregates pay nothing). Compared honestly:

| | Side table (current) | Tags in `metadata` + JSON index |
|---|---|---|
| Storage | ~220 B per tag per event (PostgreSQL) | Smaller — GIN posting lists are compact; no `stream`/`event_no` duplication |
| Write | one multi-row INSERT per append | nothing extra — `metadata` is written anyway; GIN maintenance per insert |
| Read, PostgreSQL | PK range scan + join | `metadata->'_tags' @> '{"coupon":["SUMMER24"]}'` on a GIN index — good |
| Read, MySQL | same | multi-valued index over `CAST(metadata->'$._tags' AS CHAR ARRAY)` + `MEMBER OF` — **8.0.17+ only** |
| Read, MariaDB | same | **no multi-valued JSON index exists** → full scan of the stream table per decision |
| Read, SQLite | same | **no indexable containment on JSON arrays** (`json_each` cannot use an index) → full scan |
| Backfill (1.x history, tags added later) | INSERT only; event rows untouched | **`UPDATE` of every matching event row** — rewrites immutable events, a full tuple copy per row on PostgreSQL (bloat, TOAST churn, GIN rebuild); tens of millions of rows |
| Cross-stream models (§4.5a) | one query, discovers streams from data | one query **per declared stream table**, union in PHP; per-tag order needs `_tag_sequences` in `metadata` too |
| Stream table | untouched | new expression index on the hottest table, incl. legacy `_<sha1>` tables (allowed by constraint 4, but each is a separate DDL) |
| Cleanup on `delete()` | one DELETE | none needed |

**Verdict: keep the side table — decided 2026-09-23.** MariaDB and SQLite cannot serve the read at all, the
backfill would rewrite immutable event rows, and cross-stream reads become N queries. The register table is the
concurrency mechanism in both designs and is identical. The maintainer confirmed SQLite as a supported engine,
which closes the question (Open Decision 8).

**The honest cost against constraint 1.** The only lock is the row lock the write itself takes, held to commit —
the same *kind* of lock today's unique-index insert takes. But not the same *reach*: today a writer waits only on a
writer of the identical `(type, id, version)` — one it truly conflicts with. A counter also queues **unconditional**
writers that merely share a tag: two different aggregates whose events are both tagged `course:c1` now commit one
after the other, including their synchronous `#[EventHandler]`s. No variant avoids this without reintroducing the
missed-conflict bug. It is the price of tagging an event, paid only by events that are tagged — hence filter-only
tags, and hence: **tag what a decision needs, nothing else.**

**Pure optimistic locking (maintainer, 2026-09-28).** The guarded `UPDATE ... WHERE version = :captured` (0 rows →
`DecisionModelConcurrencyException`) is the only conflict mechanism: no `SELECT ... FOR UPDATE`, no per-transaction
snapshot tracking, and the store keeps no state between executions. The guarded `UPDATE` is a current read on every
engine, so a foreign commit that landed after a decision captured its version fails the guard even inside an older
InnoDB snapshot. The blind bump that used to leave a
`REPEATABLE READ` corner was removed on 2026-09-28 (below). The earlier InnoDB own-bump tracking was removed: it kept per-connection state in a
singleton that leaked into the next transaction. Recent MariaDB (`innodb_snapshot_isolation=ON`) raises error 1020
on such writes instead — mapped below.

**Every tagged append is guarded (maintainer, 2026-09-28).** An append never bumps a counter blindly. For each
counted tag the new events carry that the explicit `AppendCondition` does not already cover, the store captures the
tag's version with a plain `SELECT` inside the caller's transaction (`DbalTagVersionRegister::captureTagVersions`)
and bumps it with the same guarded `UPDATE ... WHERE version = :captured`; zero rows is a
`DecisionModelConcurrencyException`. Explicit condition versions win over captured ones for the same tag. The
capture is a consistent read: on `REPEATABLE READ` it is the version as of the transaction's snapshot — the version
at the moment the aggregate was loaded — while the guarded `UPDATE` is a current read; on `READ COMMITTED` the
capture is current. `InMemoryEventStore` has no snapshots, so capture equals current and only a stale explicit
condition conflicts. The event store still opens no transaction; it only requires one.

*Walkthrough — coupon `SUMMER10`, limit 3, two redemptions exist (tag version 2), MySQL `REPEATABLE READ`.*

1. Anna's transaction loads her `Order` aggregate — the snapshot is pinned: two redemptions, tag version 2.
2. Ben's transaction commits the third redemption: tag version 3.
3. Anna's aggregate save appends `OrderPlaced` tagged `coupon:SUMMER10`. The store captures version 2 from her
   snapshot and runs `UPDATE ... SET version = version + 1 WHERE ... AND version = 2`; the current row says 3, zero
   rows are affected, and the save fails with `DecisionModelConcurrencyException`. Nothing was written; the
   synchronous handler that would have injected `CouponRedemptions` never runs.
4. The command is retried (the user-configured retry wraps the transaction): Anna's new transaction sees Ben's
   order, the count reaches the limit and the model refuses. The coupon is never over-redeemed.

Before this rule, step 3 was a blind upsert — a current read that succeeded (version 4). The synchronous handler then
read version 4 (its own write) with events from the older snapshot, folded 3, and let a fourth redemption through.
On `READ COMMITTED` (PostgreSQL default) step 3 captures version 3 and succeeds, but the handler's read is current
too, so it sees Ben's order and refuses — same invariant, refused by the model instead of by the counter.
SQLite serializes writers and cannot commit Ben's transaction while Anna's read transaction is open, so the race
itself is not reproducible there.

**Deadlocks still happen, and are conflicts.** Sorting removes cycles *within* one append. It cannot remove
them across two appends in one transaction, nor InnoDB's three-way duplicate-insert deadlock on a never-written
tag (the hot path of every uniqueness claim). MySQL 1213/1205, MariaDB 1020, PostgreSQL 40P01/40001 are mapped to
`ConcurrencyException`. Today only `UniqueConstraintViolationException` is (`DbalEventStore.php:122`).

**Retry — configured by the user, not by the framework (maintainer, 2026-09-23).** A
`DecisionModelConcurrencyException extends ConcurrencyException` always means *run the command again*, and the
retry must wrap the transaction — inside it, InnoDB re-reads the same snapshot forever and PostgreSQL's transaction
is already aborted. Ecotone's existing pieces are the right ones and sit in the right place:
`InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [DecisionModelConcurrencyException::class])`
or Enterprise `#[InstantRetry]` on the bus; both run at precedence −2002, outside the transaction interceptor at
−2000. Nothing is registered automatically. The docs must carry this in the first paragraph of the DCB page,
because under any contention an unretried decision model surfaces a technical exception where the user expects a
business answer. Asynchronous endpoints already retry three times by default.

**What the user sees** on a conflict: the model class, the tag `name:value`, captured vs. current version — not a
database error string.

**Known over-approximation.** Counters are per tag, not per tag-and-type. A multi-tag model (an AND criterion) is
guarded by one of its tags — the first declared, so declare the most selective first. When a handler injects
several models, a tag already guarded by another model is not guarded twice. Any event sharing a counted tag with an in-flight
decision forces that decision to retry, even one of a type the model ignores. It never lets a real conflict
through. A precise re-check is possible later on PostgreSQL only (a count-based one is unsound on InnoDB: the count
is a snapshot read and cannot see the commit that failed the guard).

**Bounded histories.** A decision reads every matching event, on every attempt. Tags that accumulate tens of
thousands of events make slow decisions. Per-tag snapshots are sound under counters-first ordering and are the
follow-up; until then this is tag-design guidance, stated in the docs.

### 4.6 Events without an aggregate, and the projection invariant

Decision-model events carry no `_aggregate_id`, `_aggregate_type`, `_aggregate_version`. The 2.0 stream schema
permits that on all three engines. Some 1.x tables do not (§4.7).

| Projection kind | Sees them? | Why |
|---|---|---|
| Global, `#[FromStream]` | **Yes** | Tracks `no`, filters by event name |
| Global, `#[FromAggregateStream(X::class)]` | **No** | Filters `_aggregate_type = X` |
| `#[Partitioned]` | **No** | Partitions are `(aggregate_type, aggregate_id)`; position *is* the aggregate version |

**Enforced at append time, not bootstrap.** The first draft checked at bootstrap and was both leaky (a handler
returning `array` does not reveal what it records) and over-strict (`CourseDefined` recorded by a `Course`
aggregate, folded by a decision model *and* consumed by a partitioned projection is perfectly valid). Instead: when
an aggregate-less event is appended whose name a partitioned or aggregate-stream projection on that stream
subscribes to, the append throws, naming the projection, the event and the fix. The projection→event-name map
exists at bootstrap, so the check is a lookup.

Until tag-partitioned projections ship, every read model fed by decision-model events is a global projection.

**SQL-side projection filtering** is a separate work item. `GapAwarePosition` treats every missing `no` as a
possible in-flight transaction, so a filtered query poisons it. The sound form is **one** statement returning
`no, created_at` for every row and `CASE WHEN <filter> THEN payload END` (likewise `metadata`): one snapshot, no
transfer or JSON decode for non-matching rows, no duplicates. (Two statements would deliver a row that committed in
between while still listing it as a gap.)

### 4.7 Upgrade runbook — expand on 1.x, then upgrade

1.x names its five columns on every insert, never reads unknown tables, and always writes aggregate metadata. So:

| # | When | Step | 1.x keeps working because | Rollback |
|---|---|---|---|---|
| 1 | on 1.x | Create the three tag tables (§4.2 DDL, published in the docs so it can be applied before 2.0 is installed) | never referenced | `DROP TABLE` |
| 2 | on 1.x, optional | Create `ecotone_event_stream` | never referenced | `DROP TABLE` |
| 3 | on 1.x, **rarely** | Relax a 1.x table — see below | only permits more | until the first aggregate-less row |
| 4 | | Deploy 2.0 to **every** node | | redeploy 1.x |
| 5 | on 2.0 | Release adding `#[EventTag]` to events; deploy to every node | | |
| 6 | on 2.0 | `ecotone:event-store:backfill-tags`, and **wait for it to finish** | | re-runnable |
| 7 | on 2.0 | Release adding the `#[DecisionModel]` | | |

**Step 3 is only for a 1.x table a decision model *writes into*.** Because boundaries span streams, a model can
*read* `Order` events from `_<sha1('Order')>` and `Coupon` events from `_<sha1('Coupon')>` and write its own to
`ecotone_event_stream` — no 1.x table is altered at all. When it is needed:

| 1.x layout | PostgreSQL | MySQL | MariaDB |
|---|---|---|---|
| `single` / `partition` (one table per stream — the 1.x default) | `SET lock_timeout = '2s'; ALTER TABLE "_<sha1>" DROP CONSTRAINT IF EXISTS aggregate_version_not_null, DROP CONSTRAINT IF EXISTS aggregate_type_not_null, DROP CONSTRAINT IF EXISTS aggregate_id_not_null;` — metadata-only once the `ACCESS EXCLUSIVE` lock is granted; without the timeout it queues behind any long reader and blocks everyone behind it. Retry on timeout | `MODIFY` the three `STORED` generated columns without `NOT NULL`, restating each expression. **Rebuilds the table, blocking writes** — use `gh-ost`/`pt-online-schema-change` | **Nothing.** The 1.x MariaDB columns are already nullable |
| `simple` | nothing — no such constraints | nothing | nothing |
| `aggregate` (table per instance) | unusable in place; copy into one table first (§4 of the guide), then backfill | | |

Also on 1.x tables: `event_name` is `VARCHAR(100)` — a longer decision-model event name fails on PostgreSQL and is
truncated on non-strict MySQL; widen it (metadata-only on PostgreSQL) or use `#[NamedEvent]`. Keep `event_streams`
until rolling back to 1.x is off the table: 1.x `load()` throws `StreamNotFound` without its catalogue row.

**`ecotone:event-store:verify-schema`** — a CI and deploy gate. Table *existence* is not enough: a hand-applied
tag table with a case-insensitive collation or the wrong primary key silently disables conflict detection. It
checks key and collation of the tag tables, and nullability on every stream a decision model writes to; on failure
it prints the exact `ALTER`. `--sql` emits the statements for a DBA.

**Mixed writers are unsupported on tagged events.** A 1.x node — or a 2.0 node running code from before an
`#[EventTag]` was added — appends without index rows or counter bumps, and a decision model would approve what it
should reject. That is why the release order above separates *every node on 2.0* → *tags* → *backfill* → *model*,
It is an operator rule, documented in the upgrade guide and the DCB page, **not enforced at runtime**
(maintainer, 2026-09-23): the user completes the backfill, then starts using decision models. Rolling code back to
1.x after decision models ran means re-running the backfill before rolling forward.

### 4.8 The backfill

Events recorded before their class declared its current tags — every 1.x event, and any event tagged later — have
no index rows, and a model deciding on them would read too little. The user runs the backfill and starts using
decision models once it has completed. There is no runtime guard and no coverage table (maintainer, 2026-09-23:
the framework does not track backfill state; a Part 7 recommendation for a guard was declined).

`ecotone:event-store:backfill-tags [--stream=] [--event=] [--batch-size=500] [--dry-run]` follows the shape of the
projection backfill (`ProjectingConsoleCommands::backfillProjection` → `ProjectingManager::prepareBackfill()`): a
`#[ConsoleCommand]` that splits the work into batches, executed synchronously by default or handed to an
asynchronous channel when one is configured (`EventSourcingConfiguration::withTagBackfillChannel('backfill')`,
mirroring `#[ProjectionBackfill(asyncChannelName:)]`), so a large stream is processed by workers rather than one
console process. Per batch, in one transaction: walk the stream by `no`, deserialize events whose class declares
tags, bump the affected counters in sorted order and insert the missing index rows (idempotent on the primary key,
so re-running is safe). `tag_sequence` for backfilled rows is assigned in `(created_at, stream, no)` order. A
payload that no longer deserializes is reported with its `no` and skipped only under `--skip-undeserializable`.
Progress is per-batch: the command prints the last `no` processed per stream, and `--from-no=` resumes. There is
no index on `event_name`, so a large stream is a full scan: hours on tens of millions of rows, once. On a
multi-tenant setup (§4.5a), the `tenant` header selects which tenant's connection and tag tables the backfill (and
`verify-schema`) run against — `--header "tenant:a"` backfills tenant `a` only, run once per tenant — and running
either command with no header fails with the same tenant-context error every other console command raises, rather
than silently touching a default connection.

### 4.9 Licence — DCB is Enterprise

Maintainer decision (2026-09-21), **narrowed 2026-09-27**: the tag-carrying half of DCB is under the Enterprise
licence — `#[EventTag]`, `#[DecisionModel]`, `#[DecisionBoundary]`, `EventCriteria`, and a
tag-bearing `AppendCondition`. **What changed on 2026-09-27:** the maintainer allowed folding `TaggedEventStore` and
`AggregateEventStore` into `Ecotone\EventSourcing\EventStore` (one store, §4.4), and let `AppendCondition` also
carry an aggregate's optimistic-lock expectation (`AppendCondition::forAggregate()`) — that half is open-core,
because it only formalizes the unique-index check aggregate saves already relied on. `EventStore` and
`AppendCondition` themselves are `licence Apache-2.0`; only actually populating a tag part, or a `#[EventTag]`
class existing at all, requires Enterprise.

Gated the way projections' enterprise features already are (`ProjectingModule.php:69-72`), at bootstrap, in the
module's `prepare()`, **plus a second, runtime line for the store itself:**

- `DynamicConsistencyBoundaryConfiguration` registered while `isRunningForEnterpriseLicence()` is false →
  `LicensingException` at bootstrap (2026-09-28: the extension object decides *whether* DCB runs, the licence decides
  *may*; this replaces the earlier "any `#[EventTag]` or `#[DecisionModel]` found without a licence" check). Without
  the extension object DCB is simply off — see *Enabling* below — so an application never silently records events
  with a half-enabled index.
- **Appending is split into an append strategy, chosen once at bootstrap** (`AppendStrategy`, core,
  `packages/Ecotone/src/EventSourcing/EventStore/AppendStrategy`) — open-core when the extension object is absent,
  `LicenceDecider`-selected when present: the open-core strategy handles the aggregate
  part only and throws the disabled `ConfigurationException` the moment a hand-built `AppendCondition` carries a tag part — the
  runtime line for a hand-built condition through the gateway or the raw store, since no `#[EventTag]` class needs
  to exist for that. The Enterprise strategy handles the tag part (the counters-first protocol) and delegates the
  aggregate-only case to the open-core strategy by composition. `AppendStrategy` is registered unconditionally
  (the project rule: no nullable services, gate at runtime) via `LicenceDecider::prepareDefinition()`, exactly like
  `AggregateMethodInvoker` already is.
- Consequences that fall out for free: an application without the extension object indexes no tags, so **the append
  path is byte-for-byte today's single INSERT** — no counter statements, no own-transaction wrapper, no tag tables. The store's
  behaviour for existing users does not change at all; only the aggregate's own unique-index check, which was
  already there, is now also expressed as an explicit `AppendCondition`.
- Tests use the existing `LicenceTesting::VALID_LICENCE` with `EcotoneLite::bootstrapFlowTesting(...,
  enterpriseLicenceKey: ...)`.
- The 1.x expand-first runbook (§4.7) is unaffected: the DDL is published in the docs, and creating three unused
  tables needs no licence.

#### Enabling

```php
#[ServiceContext]
public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
{
    return DynamicConsistencyBoundaryConfiguration::createWithDefaults();
}
```

`Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration` is `licence Enterprise` and carries every DCB
option (`withFilterOnlyTags()`). It is resolved from `$extensionObjects` exactly once per module, by
`Ecotone\EventSourcing\Tagging\Config\DynamicConsistencyBoundary`, which answers `isEnabled()`, exposes the
configured options, and registers the DCB service set: `EventTaggingModule`, `DecisionModelModule`, the Pdo
`EventSourcingModule` and the in-memory `EcotoneTestSupportModule` all consult it instead of testing the extension object
themselves, and the two store modules share its `registerServicesForInMemoryStore()`. Absent, the open-core (`OpenCore*`) classes are what runs — there is no third set:
`#[DecisionModel]`, `#[DecisionBoundary]` and decision-model injection are a bootstrap `ConfigurationException`;
`loadByCriteria()`, a tag-bearing `AppendCondition` and the `backfill-tags` / `verify-schema` commands throw the same
runtime `ConfigurationException`; `#[EventTag]` events are stored as plain rows (one `INSERT`, no tag tables, no
counters); the `event_tags` DDL/setup feature is inactive. Present, everything above applies, and registering it
without an Enterprise licence is a `LicensingException`.

What stays open-core: the aggregate half of `AppendCondition` and `EventStore` itself (§4.4, revision 5). SQL-side
projection filtering (§4.6) is a separate work item and, where it filters by event name and aggregate type, does
not depend on tags or on a licence.

### 4.10 Deliberately not in this plan

| Item | Why |
|---|---|
| `pg_snapshot_xmin` gap detection | Independent of tags. Own design |
| UUID v7 for the store's fallback id | One-line change, unrelated |
| Replacing `MetadataMatcher` / the `EventStore` interface | §4 promised it unchanged |
| Tag-partitioned projections | Follow-up; `tag_sequence` is the per-tag position they need |
| Decision-model snapshots | Follow-up; same |
| `#[CommandHandler]` directly on a `#[DecisionModel]` class | Dropped 2026-09-23 after wave 1: one shape only, models are injected |
| An OR *inside* one model (`#[MatchingTags]` from revision 2) | Removed. Two questions are two models; the OR happens where they are injected |
| Runtime backfill-coverage guard | Declined by the maintainer (2026-09-23); an operator rule instead |
| Automatic retry registration | Declined by the maintainer (2026-09-23); users configure `InstantRetryConfiguration` / `#[InstantRetry]` |
| Precise (type-aware) conflict re-check | PostgreSQL only; measure first |
| SQL-side projection filtering | Enabled by this, specified in §4.6, separate work item |

---

## Part 4½ — Store cleanups pulled into scope (maintainer, 2026-09-23)

Not DCB, but the maintainer wants the store left clean by the same work. Each is a plan task.

| # | Directive | What it means in code | Note |
|---|---|---|---|
| 1 | **Remove every Prooph mention and the BSD attribution.** | `DbalEventStore`, `EventStreamSchema` and the three platform schema classes carry `licence BSD-3-Clause / code comes from prooph/pdo-event-store`. | Honest caveat: the *DDL* in those classes is currently near-verbatim Prooph, and BSD-3 requires the notice on redistributed copies of *their* code. Dropping the header is legitimate once the classes are rewritten — which #2 and #4 do anyway: new table names, new column names, our own DDL. Do #2/#4 first, then delete the headers; the `MetadataMatcher`/`FieldType`/`Operator` trio is also Prooph vocabulary and goes with `WriteLockStrategy` |
| 2 | **One way to create stream tables; one schema.** | Confirmed 2026-09-23. The table manager (+ automatic initialization in tests/dev) is the only DDL path; `create()`/`hasStream()` stop touching DDL. **One schema, no persistence strategies: the aggregate columns are always nullable** — filled for aggregate events, empty otherwise. Column names unchanged (#4); the aggregate fields stay derived from `metadata` as today (expression index on PostgreSQL, generated columns on MySQL/MariaDB, `json_extract` expression index on SQLite). | Legacy 1.x `_<sha1>` tables keep their own layout and NOT NULL checks; §4.7 step 3 still applies to them |
| 3 | **Delete `WriteLockStrategy`.** | `Dbal/WriteLock/*` (advisory lock / `GET_LOCK` around the insert) and `enableWriteLockStrategy` on `EventSourcingConfiguration` go. Concurrency is the aggregate unique index and, for DCB, the tag counter. | It was opt-in and off by default, so the default behaviour does not change; it was Prooph's way to reduce `no` gaps, which `GapAwarePosition` already handles |
| 4 | **New, accurate names for the tag tables only.** | Confirmed 2026-09-23. `ecotone_tagged_events (tag_name, tag_value, stream_name, event_no, tag_sequence)`, `ecotone_tag_versions (tag_name, tag_value, version)`, `ecotone_tag_coverage (event_name, tags_hash, covered_at)`; `tag_sequence` not `tag_version` — an order stamp, not a version. **`ecotone_event_stream` keeps its column names** (`no`, `event_id`, `event_name`, `payload`, `metadata`, `created_at`) — one layout serves the default table and every legacy `_<sha1>` table, so no `StreamLayout` abstraction. Its only deviation from 1.x stays what it is today: the three aggregate NOT NULL constraints are gone; the unique index `(aggregate_type, aggregate_id, aggregate_version)` and the lookup index remain — they are the aggregate's concurrency mechanism (constraint 2). | Reverses the rename proposed earlier the same day: the two-layout cost was not worth it |
| 5 | **Licence check.** | Already §4.9: `LicensingException` at bootstrap for any `#[EventTag]`/`#[DecisionModel]` without Enterprise. | Done in design |
| 6 | **Returned events go to the stream; `#[Stream]` on the handler method.** | Confirmed 2026-09-23: **only handlers that inject a `#[DecisionModel]`** append their returned events. Every other handler keeps today's semantics — the return goes to the reply / `outputChannelName`. On a model-injecting handler `outputChannelName` keeps working too: events are appended and published, then forwarded as today (no bootstrap error — revised from §4.4). `Stream` gains `TARGET_METHOD`. | Closes Open Decision 7 |
| 7 | **`EcotoneLite` integration tests proving the optimistic lock.** | Pattern already in the codebase: `packages/Dbal/tests/Integration/DeduplicationModuleTest.php:141` — two `DbalConnectionFactory`s on one DSN, two `bootstrapFlowTesting` instances, `SET lock_timeout` / `innodb_lock_wait_timeout` on the second so a blocked statement fails fast instead of hanging the test. Plan task 5 adopts it verbatim. | |
| 8 | **Models declare tag names; `#[EventTag]` only on events.** | §4.4 revision 4. Default = intersection of handled events' tags (not union — reasoning in §4.4). | |

## Part 5 — Decisions (all closed 2026-09-23)

| # | Decision | Outcome |
|---|---|---|
| 1 | Licence | **Enterprise**, all of DCB (§4.9) |
| 2 | Boundaries span streams | **Yes** — counters keyed by tag only (§4.5a) |
| 3 | Backfill coverage guard | **No guard.** `backfill-tags` command shipped, modelled on the projection backfill; the user waits for completion before enabling decision models (§4.8) |
| 4 | Automatic retry | **No.** Each user configures retries (`InstantRetryConfiguration` / `#[InstantRetry]`); documented up front (§4.5) |
| 5 | Names | `#[EventTag]`, `#[DecisionModel(tags:)]`, `#[DecisionBoundary]`, `EventCriteria`, `TaggedEventStore::load/appendTo`, `#[Fetch]` for explicit mapping; tables `ecotone_tagged_events`, `ecotone_tag_versions` |
| 6 | Filter-only tags | **Yes**, per key in `DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags()` |
| 7 | Returned array from a model-injecting handler | Appended as events; other handlers unchanged; output channel honoured |
| 8 | Tag index as side table | **Yes** — MariaDB and SQLite cannot index JSON array membership |

## Part 6 — Implementation plan

One worker session per task, test-first, sequential, in docker. Core tests use inline anonymous classes. Tasks 6–8
depend only on task 2, so the user-facing layer can be reviewed on the in-memory store while 3–5 proceed.

0. **Every task:** new classes carry `licence Enterprise`; tests bootstrap with `LicenceTesting::VALID_LICENCE`.
   **Task 0a — store cleanup (before any DCB task):** delete `WriteLockStrategy` and `enableWriteLockStrategy`;
   make the table manager the single DDL path (`create()`/`hasStream()` no longer create tables); one stream
   schema on all four engines — today's columns, aggregate fields nullable, unique index kept — rewritten as our
   own DDL with the Prooph/BSD headers removed; drop `MetadataMatcher`/`FieldType`/`Operator` in favour of a plain
   aggregate query in the store. Tests:
   existing suites green on all four engines; `create()` on a missing table raises the §8 `ConfigurationException`.
1. **Core — `#[EventTag]`, the tag registry, and the licence gate.** `LicensingException` at bootstrap when an
   `#[EventTag]` or `#[DecisionModel]` exists without an Enterprise licence (test it first — it is the cheapest
   test in the plan and every later task depends on it). `packages/Ecotone/Api/Attribute/EventTag.php`,
   `src/EventSourcing/Tagging/*`, a module scanning with `findClassesWithAnnotatedProperties`. Tests: property,
   promoted parameter, method, class-level literal; repeated key; array value; `null` skipped; non-scalar type and
   invalid value (empty, > 255, trailing space) rejected; filter-only keys from `DynamicConsistencyBoundaryConfiguration`.
2. **Core — `TaggedEventStore`, `EventCriteria`, `AppendCondition`, in-memory implementation.** Gateway
   registration; `withEvents()` writes to the default stream; `InMemoryEventSourcedRepository` writes through the
   store so a model can see aggregate facts in core-only tests. Tests: OR and AND criteria; type filter; an event
   matching two criteria is returned once; conditional append succeeds/fails; an unconditional `appendTo`
   invalidates a held condition; a disjoint tag does not; `delete()` clears the index.
3. **PdoEventSourcing — schema.** A `SqliteEventStreamSchema` for the stream table first (none exists), then tag schema classes for all four platforms, table manager under its own `event_tags`
   feature with `isUsed()` true only when tags are declared, §8 behaviour, per-tenant ensure. Tests: an
   application with no `#[EventTag]` lists and creates no tag tables; with tags, setup creates and lists both
   and prints them with `--sql`;
   missing table → `ConfigurationException` naming the command; `utf8mb4_bin` keeps `ABC` ≠ `abc`.
4. **PdoEventSourcing — every `appendTo` bumps counters and writes index rows.** First test: with no tags
   declared, `appendTo` issues exactly the one INSERT it issues today. Then: counters first, merged sorted
   pass, own transaction when none is active, `event_id` sub-select, tag carrier through `SerializingEventStore`,
   `delete()` cleanup. Tests on PostgreSQL, MySQL, MariaDB, inspecting the tables directly: tagged aggregate
   events; untagged aggregates write nothing; works under `#[WithoutDatabaseTransaction]` and rolls back whole.
5. **PdoEventSourcing — `load(criteria)` and conditional append. The riskiest task** — it must prove real
   concurrency on PostgreSQL, MySQL and MariaDB using the two-connection `EcotoneLite` pattern of
   `DeduplicationModuleTest::test_deduplication_inserts_before_handler_when_transaction_is_active`
   (`SET lock_timeout` / `innodb_lock_wait_timeout` on the second connection, an externally begun transaction on
   the first) (SQLite serialises writers, so it can only prove the 0-rows path
   and `SQLITE_BUSY` mapping), which no in-memory test can. Two-connection tests: conflict on an existing
   counter; conflict on a never-written tag; loser wrote nothing and burned no `no`; rollback lets the waiter
   succeed; opposite-order multi-tag appends do not deadlock; aggregate save `{A,B}` vs. model on `{B}` do not
   deadlock; the InnoDB own-bump snapshot hazard throws; deadlock and lock-timeout codes surface as
   `ConcurrencyException`; a model fed from two stream tables folds in commit order even when `created_at` ties or
   disagrees; an append to stream A fails a condition held by a model that only ever read stream B; a model traced
   to a stream on another connection is rejected at bootstrap.
6. **Core — `#[DecisionModel]` classes injected into service handlers.** A `DecisionModelModule`; per handler,
   the chain `ResolveTags → LoadModels (one capture, one index read, each event applied to every matching model) →
   Call → AppendUnderCondition`; models reach the method through a parameter converter, as
   `FetchAggregateConverter` does for aggregates; reuse `EventSourcingHandlerExecutorBuilder` and
   `SaveAggregateServiceTemplate::buildEcotoneEvents`. Tests: the three-model course example; one model reused by
   two handlers with different boundaries; a multi-tag (AND) model; business exception; `[]` no-op; events reach an
   `#[EventHandler]` and a saga; metadata propagates; `#[Reference]`; `#[Asynchronous]` + `run()`; pointcut on
   `DecisionModel::class`; `#[QueryHandler]` with a model replies and appends nothing; `#[EventHandler]` with a
   model; `outputChannelName` + model rejected; handled event missing a model tag rejected; interface parameter
   rejected; constructor rule; unresolvable tag.
7. **Core — explicit mapping, on-model handlers, aggregates.** `#[Fetch]` on a model parameter (single value and
   map); the same model class injected twice (transfer); array tag values; `#[CommandHandler]` on a
   `#[DecisionModel]` class; `#[DecisionBoundary]`; **a model injected into an `#[EventSourcingAggregate]`
   command handler** — condition carried through `save()`'s `$metadata`, stripped before persisting. Tests: the
   `Order` + `CouponRedemptions` example — a concurrent redemption fails the save, a concurrent unrelated order
   does not, the aggregate version check still fires independently, partitioned projections still see the events.
8. **Core — `DecisionModelConcurrencyException` and the retry story.** No automatic registration. Tests: with
   `InstantRetryConfiguration` command-bus retry on that exception, an injected conflict succeeds on the second
   pass; without it the exception surfaces with model, tag and versions in the message; no retry inside an outer
   transaction; `#[InstantRetry]` (Enterprise) works the same.
9. **PdoEventSourcing — decision model end to end on DBAL.** `#[Stream]` on a model; a 1.x-shaped table raising
   the `ConfigurationException` that quotes the `ALTER`; the append-time projection invariant; multi-tenant.
10. **PdoEventSourcing — `backfill-tags` and `verify-schema`.** Backfill shaped like `ProjectingManager::
    prepareBackfill()` (batches; sync or via a configured async channel). Tests: idempotent re-run; `--from-no`
    resumes; a backfilled batch bumps counters so an in-flight condition fails; `tag_sequence` order for backfilled
    rows; `--dry-run` counts; undeserializable payload reported; `verify-schema` catches a wrong collation and a
    missing NOT NULL relaxation.
11. **Symfony and Laravel smoke tests, docs** — `upgrade-2.0.md` §4/§13/§16, the namespace-map CSV,
    the runbook of §4.7 per engine and layout, a contention guide, the `ecotone-event-sourcing` skill.

## Part 7 — Review record

Three sub-agents reviewed revision 1 (`8b425a89`) on 2026-09-20. All confirmed the core — guarded `UPDATE` on a
per-tag counter, bumped by unconditional appends too — and all found it incomplete around it.

| Source | Finding | Outcome |
|---|---|---|
| Architect | InnoDB `REPEATABLE READ`: own bump + stale snapshot lets a decision pass without a foreign event | **Accepted** — §4.5 snapshot hazard |
| Architect, Tech Lead | No ambient transaction is guaranteed; append is no longer one statement | **Accepted** — store opens its own |
| Architect, 1.x user | Insert events before checking: burned `no`, swallowed exceptions commit failed appends | **Accepted** — counters first; also gives per-tag commit order |
| Architect, 1.x user | "Cannot deadlock" was false (two sorted passes; InnoDB insert deadlock; multi-append transactions) | **Accepted** — one merged pass, deadlock codes mapped, claim withdrawn |
| Architect | Read merge double-applies events; `DISTINCT` fails on `json` | **Accepted** — flag query over the index |
| Architect, 1.x user | `EventStore::delete()` leaves stale index rows | **Accepted** |
| Architect | "Precisely what the aggregate save does" overstated | **Accepted** — honest-cost paragraph |
| Architect | Count-based precise re-check unsound on InnoDB | **Accepted** — deferred, PostgreSQL only |
| Architect | PAD SPACE, truncation, key-length margins | **Accepted** — PHP validation, `stream VARCHAR(128)` |
| Architect | Per-stream side tables | **Rejected** — conflicts with cross-stream boundaries |
| 1.x user | One-stream boundaries make DCB useless to 1.x users | **Accepted** — counters keyed by tag only. Open Decision 2 |
| 1.x user, Architect | Nothing stops a model running on an incomplete index; mixed writers break the invariant | **Accepted** — coverage guard, release order. Open Decision 3 |
| 1.x user | §4.7 was a table, not a runbook; MariaDB step wrong; no `lock_timeout`; no verification; no SQL for a DBA | **Accepted** — §4.7 rewritten, `verify-schema` |
| 1.x user | Same key twice, array tags, computed tags undefined | **Accepted** — §4.3 |
| 1.x user | Conflict diagnostics; personal data in tags | **Accepted** |
| 1.x user | Aggregate handler saving under a tag condition | **Deferred** — §4.9 |
| Tech Lead | API placed in a package core cannot depend on | **Accepted** — attributes and interfaces in core |
| Tech Lead | Hand-written `if` discrimination — merely matches Gember | **Accepted** — `#[MatchingTags]` |
| Tech Lead | Derivation cannot express AND criteria; type-only decisions; interface parameters | **Accepted** — per-handler criteria, `#[DecisionBoundary]`, class-level tags |
| Tech Lead | Boundary must be per command handler | **Accepted** |
| Tech Lead | `concurrencyRetries` inside the transaction cannot work | **Accepted** — existing interceptor, outside the transaction |
| Tech Lead | Bootstrap projection invariant: false positives, unknowable input | **Accepted** — append-time check |
| Tech Lead | Tags "from the live object" collides with `SerializingEventStore`, array payloads | **Accepted** |
| Tech Lead | `withEvents()` stream mismatch; in-memory repository bypasses the store | **Accepted** — plan task 2 |
| Tech Lead | `consistency: false` per usage lets two events disagree | **Accepted** — per key, in configuration |
| Tech Lead | "Instantiated without constructor" is factually wrong | **Accepted** |

## Part 8 — Decision log

| Date | Decision | By | Why |
|---|---|---|---|
| 2026-09-20 | DCB lands on `ecotone_event_stream`; the old spec's `event_log` is dropped | Claude, from Part 1 | A second data migration inside 2.0 would invalidate upgrade guide §4 |
| 2026-09-20 | Optimistic only; aggregates keep id/version and the unique index; aggregate fields nullable with an explicit projection invariant; schema upgradable while on 1.x | **Maintainer** | Part 2 |
| 2026-09-20 | DCB sits alongside aggregates | follows from the above | |
| 2026-09-20 | Axon's conflict-table design, with a version counter instead of a position marker | Claude, from Part 3 | Position markers need commit-ordered positions; Axon's way of getting them breaks constraints 1 and 4 |
| 2026-09-21 | **All of DCB is under the Enterprise licence** | **Maintainer** | Business decision. Consequences worked through in §4.9: bootstrap `LicensingException`, own `event_tags` setup feature active only when tags are declared, open-core append path unchanged |
| 2026-09-21 | **A decision model is a standalone reusable class, injected into message handlers; consistency covers whatever a handler injects** | **Maintainer** | Reuse across handlers without duplicating folds. Falls out better than revision 2: one model = one criterion, handler = OR of its models, so `#[MatchingTags]` and the per-handler-boundary machinery disappear; and injecting a model into an aggregate handler gives existing applications an adoption path without touching constraint 2 |
| 2026-09-21 | Cross-stream models ordered by a per-tag `tag_version` stored in the index, not by `created_at`; cross-connection models rejected at bootstrap | Claude, answering the maintainer's question | `created_at` is application-assigned and coarse; the counter is already a commit-ordered per-tag sequence across streams, so exact order costs one column |
| 2026-09-21 | The tag index is insert-only; the per-tag counter is updated in place rather than being an insert-only unique key like the aggregate version | Claude, answering the maintainer's question | Writers with no condition have no expected version: insert-only makes them race for `MAX + 1` (transaction abort on PostgreSQL, gap-lock deadlock on InnoDB); `version = version + 1` cannot fail. Lock reach and duration are identical either way |
| 2026-09-23 | Guarded in-place `UPDATE` confirmed as the locking mechanism; per-event index rows kept for the read side (vs. tags in `metadata`) | Claude, answering the maintainer | MariaDB has no way to index JSON array membership; backfill must not rewrite event rows; cross-stream discovery. Open Decision 8 |
| 2026-09-23 | **SQLite is a supported engine**; tag index stays a side table | **Maintainer** / follows | SQLite cannot index JSON array membership either; a SQLite stream schema becomes a prerequisite (task 3) |
| 2026-09-23 | Eight directives — Part 4½: no Prooph/BSD, one DDL path, no write-lock strategy, new table/column names, licence check, `#[Stream]` on handler methods, two-connection `EcotoneLite` lock tests, **models declare tag names and `#[EventTag]` lives on events only** | **Maintainer** | Default tag names = intersection of handled events' tags (Claude: union would scope `CouponRedemptions` by customer) |
| 2026-09-23 | One stream schema, aggregate fields always nullable, unique index kept; **stream column names unchanged** — only the new tag tables get new names; only model-injecting handlers append their return, output channel kept | **Maintainer** | Part 4½ #2, #4, #6; closes Open Decision 7. A full stream rename was proposed and withdrawn the same day: one layout for default and legacy tables beats two |
| 2026-09-23 | Boundaries span streams — yes; no runtime backfill guard, user waits for `backfill-tags`; no automatic retry, users configure it; names and filter-only tags as proposed | **Maintainer** | Closes Part 5. Claude's recommendations on 3 and 4 (guard on by default, retry registered automatically) were declined: the maintainer prefers explicit operator control over framework-enforced safety here — documented loudly instead |
| 2026-09-27 | **One `EventStore`, not `TaggedEventStore` beside it.** `TaggedEventStore` and `AggregateEventStore` deleted, folded into `EventStore` (`loadByCriteria`, `loadAggregateEvents`, optional `AppendCondition` on `appendTo`). `AppendCondition::forAggregate()` added — open-core, since it only formalizes the existing unique-index check. Appending split into `AppendStrategy` (open-core, aggregate only, rejects a tag part) composed with an Enterprise strategy (tag protocol, delegates the aggregate-only case) | **Maintainer** | Implemented in `implement-unified-event-store`. `loadByCriteria()` turned out not to be exposable through the `EventStore` gateway — the messaging layer has no variadic-parameter support — so it stays reachable only on the concrete/raw store, same as `TaggedEventStore` always was |
| 2026-09-27 | **Review fix: `EventCriteria` gained `->or()`/`branches()`, `loadByCriteria()` made non-variadic, and it is now registered as a gateway action.** `EventCriteria::or(self $other): self` composes an OR of criteria into one object; `branches(): self[]` iterates the single-criterion leaves for `DbalEventStore`/`InMemoryEventStore`. `loadByCriteria(EventCriteria $criteria): LoadedEvents` replaces the variadic form everywhere, so `EventStore` obtained from the container or the gateway both expose it | **Maintainer**, review follow-up | The variadic signature was never reachable through the `EventStore` gateway, which the design doc's own §4.4 example assumes; "obtain the concrete store" is not acceptable for a public API. `EventStoreGatewayLoadByCriteriaTest` (in-memory) and `DbalTaggedLoadTest::test_loading_events_by_criteria_through_the_gateway_returns_the_event_that_carries_it` (PostgreSQL) cover the gateway path |
| 2026-09-28 | **Stateless DCB services, pure optimistic locking, transactions required.** InnoDB own-bump snapshot tracking removed from `DbalTagVersionRegister`; the guarded `UPDATE` is the sole conflict mechanism. The event store never opens a transaction: tagged/conditional appends and `backfill-tags` throw `ConfigurationException` without one, naming the `DbalConfiguration` switch. Decision-model state is function-scoped: a per-handler before interceptor loads the batch once and carries an immutable `DecisionModelLoadedState` header (instances + `AppendCondition`) read by the converter, the append interceptor and `SaveAggregateService`; the message-id keyed collectors are deleted. `InMemoryEventStore` collaborators are constructor-only | **Maintainer** | Services injected through constructors must be stateless: the snapshot tracking leaked into the next transaction on the same connection, and the collectors leaked conditions/instances across query handlers, converter failures and retries with the same message id. A before interceptor rather than an around one, because around interceptors resolve the handler's arguments before they run and cannot hand converters a changed message |
| 2026-09-28 | **DCB is opt-in through `DynamicConsistencyBoundaryConfiguration`, and every tagged append is guarded.** The extension object (Enterprise) enables decision models, event tags and append conditions; absent, bootstrap of a decision model/boundary throws `ConfigurationException`, `loadByCriteria`/tag conditions/`backfill-tags`/`verify-schema` throw it at runtime, and `#[EventTag]` events are plain rows with no tag tables. Registering it without a licence is a `LicensingException`. The unconditional counter bump on the append path is deleted: each tag not covered by the explicit condition is captured with a consistent `SELECT` and bumped with the guarded `UPDATE`; the upsert survives for the backfill only | **Maintainer** | The unconditional bump was a current read: an aggregate save made after a competing commit succeeded, and a synchronous decision-model handler in the same `REPEATABLE READ` transaction then read its own bump with events from the older snapshot and over-redeemed a coupon. The accepted §4.5 corner is gone: Anna's save now fails at step 3 |
| 2026-09-28 | **DCB options move onto `DynamicConsistencyBoundaryConfiguration`; one class resolves the flag.** `withFilterOnlyTags()` is deleted from `BaseEventSourcingConfiguration`/`EventSourcingConfiguration` (2.0 branch, no shim) and lives on `DynamicConsistencyBoundaryConfiguration`. `Ecotone\EventSourcing\Tagging\Config\DynamicConsistencyBoundary` reads the extension object once and registers the append-strategy and in-memory collaborator pairs for both the Pdo and the flow-testing module | **Maintainer**, readability review item 9 | Enabling and configuring DCB happened on two unrelated objects, and four modules each re-asked whether the extension object was present |
