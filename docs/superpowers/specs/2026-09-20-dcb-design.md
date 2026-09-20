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
| Finalizer behind a global advisory lock | A pessimistic database-level lock on the write path. **Violates constraint 1** |
| Finalizer thread + `LISTEN/NOTIFY` connection | PHP has no long-lived process to own it. A worker dying between commit and finalize leaves committed events invisible until someone else appends |
| PostgreSQL 16+ only | Ecotone supports MySQL and MariaDB |
| Rewrites the primary key of every event once | Doubles write amplification on the hottest table |

**Conclusion: keep Axon's conflict-table design, replace its position marker with a per-tag version counter.** A
counter incremented inside the writer's own transaction is immune to out-of-order position allocation — it changes
when the writer *commits*, whatever `no` the writer was handed. This is also Marten's choice
(`mt_dcb_tag_version`), for the same reason.

---

## Part 4 — The solution

### 4.1 Shape in one paragraph

`ecotone_event_stream` does not change. Two side tables are added: **`ecotone_event_tags`** (the tag index, for
reads) and **`ecotone_event_tag_versions`** (one counter per tag value, for conflict detection). Events declare tags
with `#[Tag]` on their properties. Every append writes the tag rows and bumps the counters of the tags it carries.
A **decision model** — a class marked `#[DecisionModel]` — is rebuilt per command from the events matching its tags,
decides, and returns new events; the framework appends them on the condition that none of those tag counters moved
since the read. Aggregates are untouched: same columns, same unique index, same `#[Version]`.

### 4.2 Schema

**`ecotone_event_stream` and every `#[Stream]` table: no new columns, no new indexes.** This is what makes
constraint 4 cheap — the only changes to existing tables are *relaxations*, and only for 1.x-era tables (§4.7).

New tables, one pair per connection, PostgreSQL:

```sql
CREATE TABLE ecotone_event_tags (
    stream     VARCHAR(255) NOT NULL,
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    event_no   BIGINT       NOT NULL,
    PRIMARY KEY (stream, tag_key, tag_value, event_no)
);

CREATE TABLE ecotone_event_tag_versions (
    stream     VARCHAR(255) NOT NULL,
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT       NOT NULL,
    PRIMARY KEY (stream, tag_key, tag_value)
);
```

MySQL / MariaDB: identical shape, `BIGINT(20)`, `ENGINE=InnoDB`, and **`COLLATE utf8mb4_bin`** on both — the
server default `utf8mb4_0900_ai_ci` is case- and accent-insensitive and would silently merge `course:ABC` with
`course:abc`. The shipped stream schema already uses `utf8mb4_bin` for the same reason.

- `stream` is the physical table name (`ecotone_event_stream`, a `#[Stream]` name, or a legacy `_<sha1>`). `no` is
  only unique per table, so the tag index has to say which table an `event_no` belongs to. A consistency boundary
  therefore lives inside **one stream**.
- No foreign key to the stream table: stream tables are created and dropped independently, and rows are never
  updated or deleted on the append path.
- Both tables register with `ecotone:migration:database:setup` under the existing `event_stream` feature, and obey
  §8: never created at runtime outside automatic initialization, and a missing table raises the standard
  `ConfigurationException` naming the feature, table and command.
- Growth: `ecotone_event_tags` has one row per tag per event. `ecotone_event_tag_versions` has one row per distinct
  tag *value* — tens of bytes each. Aggregates contribute nothing to either table unless their events declare
  `#[Tag]`.

### 4.3 Declaring tags

```php
use Ecotone\Api\EventSourcing\Tag;

final readonly class StudentSubscribedToCourse
{
    public function __construct(
        #[Tag('course')]  public string $courseId,
        #[Tag('student')] public string $studentId,
    ) {}
}

final readonly class CourseDefined
{
    public function __construct(
        #[Tag('course')] public string $courseId,
        public int $capacity,
    ) {}
}
```

Tags are resolved from the live event object at append time, before serialization, by reading the annotated
properties (scalar or `Stringable`; anything else is a bootstrap `ConfigurationException`; a `null` value means "no
tag for this event"). Tags are **not** copied into `metadata` — the side table is the single source, so a backfill
(§4.7) never has to rewrite an event.

`#[Tag]` works on any event, including events recorded by an `#[EventSourcingAggregate]`. That is how a decision
model gets to include aggregate-produced facts in its boundary.

**Low-cardinality tags.** Every tag an event carries bumps a counter row that the writer then holds until commit
(§4.5). A tag like `tenant` or `region` shared by most events would serialise most writers. Such tags are useful
for *filtering* but should never guard a decision:

```php
#[Tag('tenant', consistency: false)] public string $tenantId,
```

`consistency: false` writes the index row and skips the counter. A decision model that names a non-consistency tag
is a bootstrap `ConfigurationException`.

### 4.4 Decision models

The canonical DCB example — a student may subscribe if the course has room and the student has fewer than five
courses. Two entities, one decision, no aggregate that naturally owns it:

```php
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\EventSourcing\DecisionModel;
use Ecotone\Api\EventSourcing\Tag;

final readonly class SubscribeStudentToCourse
{
    public function __construct(public string $courseId, public string $studentId) {}
}

#[DecisionModel]
final class CourseSubscription
{
    #[Tag('course')]  private string $courseId;
    #[Tag('student')] private string $studentId;

    private int $capacity = 0;
    private int $seatsTaken = 0;
    private int $coursesOfStudent = 0;
    private bool $alreadySubscribed = false;

    #[CommandHandler]
    public function subscribe(SubscribeStudentToCourse $command): array
    {
        if ($this->alreadySubscribed) {
            return [];
        }
        if ($this->seatsTaken >= $this->capacity) {
            throw new CourseIsFull($command->courseId);
        }
        if ($this->coursesOfStudent >= 5) {
            throw new StudentHasTooManyCourses($command->studentId);
        }

        return [new StudentSubscribedToCourse($command->courseId, $command->studentId)];
    }

    #[EventSourcingHandler]
    public function whenCourseDefined(CourseDefined $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function whenSubscribed(StudentSubscribedToCourse $event): void
    {
        if ($event->courseId === $this->courseId) {
            $this->seatsTaken++;
        }
        if ($event->studentId === $this->studentId) {
            $this->coursesOfStudent++;
        }
        if ($event->courseId === $this->courseId && $event->studentId === $this->studentId) {
            $this->alreadySubscribed = true;
        }
    }
}
```

That is the whole feature from the user's side. No query object, no event store call, no append condition, no
projection closures. Sending the command is unchanged: `$commandBus->send(new SubscribeStudentToCourse(...))`.

**What the framework derives.**

- *Tag values* come from the command exactly the way an aggregate `#[Identifier]` does: a command property with the
  same name as the model's tagged property, or a command property carrying the same `#[Tag('course')]`, or an
  `identifierMapping`-style expression on the handler. A tag that cannot be resolved is a bootstrap error when
  detectable and a clear runtime exception otherwise.
- *The query* is built at bootstrap from the model's `#[EventSourcingHandler]` methods. For each tag key on the
  model: **one criterion = that tag value ∧ the handled event types that declare that tag key.** Criteria are OR-ed.
  For the class above:

  ```
  (course:<courseId>   ∧ type ∈ {CourseDefined, StudentSubscribedToCourse})
  OR
  (student:<studentId> ∧ type ∈ {StudentSubscribedToCourse})
  ```

  This is the dcb.events query shape. A handled event that declares none of the model's tags is a bootstrap
  `ConfigurationException` — the model could never receive it.
- *The lifecycle*: instantiate without constructor (as event-sourced aggregates are), set tag properties, capture
  tag versions, load and apply events in `no` order, invoke the handler, append the returned events under the
  condition, publish them on the event bus. The instance is discarded; a decision model has no identity and no
  `#[Version]`.
- *Where events go*: `ecotone_event_stream`, or the `#[Stream]` declared on the model. The events carry **no**
  aggregate id, type or version (§4.6).

**Without the ergonomic layer.** For a decision that does not fit a class, the same machinery is a gateway:

```php
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\EventSourcing\EventQuery;

#[CommandHandler]
public function subscribe(SubscribeStudentToCourse $command, TaggedEventStore $eventStore): void
{
    $decision = $eventStore->read(
        EventQuery::tag('course', $command->courseId)->ofTypes(CourseDefined::class, StudentSubscribedToCourse::class)
            ->or(EventQuery::tag('student', $command->studentId)->ofTypes(StudentSubscribedToCourse::class))
    );

    // fold $decision->events ...

    $eventStore->append([new StudentSubscribedToCourse(...)], $decision->appendCondition);
}
```

`read()` returns the events **and** the ready-made `AppendCondition`, so user code never handles versions.
`TaggedEventStore` is a **new** interface next to `Ecotone\EventSourcing\EventStore`, which stays exactly as §4 of
the upgrade guide promised. `DbalEventStore` and `InMemoryEventStore` implement both, so
`EcotoneLite::bootstrapFlowTesting()` exercises real conditional-append semantics with no database.

### 4.5 Concurrency, in full

**Invariant maintained by every append, conditional or not:** for each distinct consistency tag carried by the
appended events, `ecotone_event_tag_versions.version` is incremented by one, in the same transaction as the event
insert. Aggregate saves obey it too when their events carry tags.

**Read side.** Capture first, then read:

```sql
-- 1. capture (a missing row means version 0)
SELECT tag_key, tag_value, version FROM ecotone_event_tag_versions
WHERE stream = :stream AND (tag_key, tag_value) IN ((:k1, :v1), (:k2, :v2));

-- 2. read, one branch per criterion, merged and ordered by no
SELECT e.no, e.event_name, e.payload, e.metadata
FROM ecotone_event_stream e
JOIN ecotone_event_tags t ON t.event_no = e.no
WHERE t.stream = :stream AND t.tag_key = :k1 AND t.tag_value = :v1
  AND e.event_name IN (:typesForK1)
ORDER BY e.no;
```

The order matters. If a writer commits between the two statements, the events are *newer* than the captured
version: the append fails spuriously and retries — safe. The opposite order would pair a newer version with older
events and **miss** the conflict. (Under InnoDB `REPEATABLE READ` both statements share one snapshot and are
mutually consistent either way.)

**Write side**, inside the message's existing transaction:

```sql
-- a. events (unchanged)
INSERT INTO ecotone_event_stream (event_id, event_name, payload, metadata, created_at) VALUES ...;

-- b. tag index, one multi-row insert
INSERT INTO ecotone_event_tags (stream, tag_key, tag_value, event_no) VALUES ...;

-- c. for each tag in the append condition, sorted by (tag_key, tag_value):
--    captured version > 0
UPDATE ecotone_event_tag_versions SET version = version + 1
WHERE stream = :stream AND tag_key = :k AND tag_value = :v AND version = :captured;
--    → 1 row affected: nobody wrote this tag since the read. 0 rows: ConcurrencyException.

--    captured version = 0 (the tag has never been written)
INSERT INTO ecotone_event_tag_versions (stream, tag_key, tag_value, version) VALUES (:stream, :k, :v, 1);
--    → duplicate key: somebody created it first. ConcurrencyException.

-- d. for each remaining tag the new events carry (not in the condition), sorted:
INSERT INTO ecotone_event_tag_versions (stream, tag_key, tag_value, version) VALUES (:stream, :k, :v, 1)
ON CONFLICT (stream, tag_key, tag_value) DO UPDATE SET version = ecotone_event_tag_versions.version + 1;
--    MySQL/MariaDB: ON DUPLICATE KEY UPDATE version = version + 1
```

Step c is deliberately a plain guarded `UPDATE` / plain `INSERT` rather than a dialect-specific guarded upsert: it
is the same SQL on all three engines, and its result does not depend on MySQL's affected-rows semantics
(`CLIENT_FOUND_ROWS` makes `ON DUPLICATE KEY UPDATE` report an unchanged row as affected).

**Why this is optimistic, and why it is atomic.** Nothing is locked while the model reads and decides. The check
and the write are one statement: the `UPDATE` finds the row, and *that same statement* both verifies
`version = :captured` and changes it. The only lock in the whole protocol is the row lock that write takes, held to
commit — precisely what today's aggregate save does when it inserts into the unique index. Walk through two
concurrent subscriptions to the last seat of course `c1`:

| | T1 | T2 |
|---|---|---|
| read | `course:c1` = 7, 9 of 10 seats | `course:c1` = 7, 9 of 10 seats |
| decide | room for one → subscribe | room for one → subscribe |
| append | `UPDATE … WHERE version = 7` → 1 row, version is 8 | `UPDATE … WHERE version = 7` → **waits** on T1's row |
| | `COMMIT` | re-evaluates against the committed row: version is 8 ≠ 7 → **0 rows** |
| | | `ConcurrencyException` → rollback → retry: reads 10 of 10 → `CourseIsFull` |

If T1 rolls back instead, T2's update finds version 7 and proceeds. On PostgreSQL (`READ COMMITTED`) the waiting
statement re-checks its `WHERE` against the newly committed row; on InnoDB an `UPDATE` always reads the latest
committed row regardless of the transaction's snapshot. Neither needs a raised isolation level. A re-check-then-
insert, by contrast, races on both engines — two writers both see nothing and both commit.

**Disjoint tags never contend.** T1 on `course:c1` + `student:s1` and T2 on `course:c2` + `student:s2` touch
different rows. **Overlapping multi-tag appends cannot deadlock** because steps c and d walk tags in sorted order.

**Why a version counter and not a position** — Part 3: `no` is handed out at insert time, not commit time, so "no
event beyond position M" can be true at check time and false a moment later for a position *below* M.

**What the user sees.** `Ecotone\Messaging\Support\ConcurrencyException`, the same class an aggregate version
conflict raises today. The correct reaction is always *run the command again*: the model re-reads, re-decides, and
either succeeds or fails for a business reason. See Open Decision 2 for whether that retry is automatic.

**Known over-approximation.** Counters are per tag, not per tag-and-type. An event tagged `course:c1` of a type the
model does not handle still bumps the counter and can cause one needless retry. It can never let a real conflict
through. Axon removes this with a precise re-check under the row lock; ours would need a count-based variant
(positions are not commit-ordered). Deferred — measure first.

**Lock-hold duration.** The counter row is held until the message's transaction commits, including synchronous
`#[EventHandler]`s triggered by the new events. For a hot tag, keep synchronous handler chains short or make them
`#[Asynchronous]`. Aggregates do not pay this unless they opt in by tagging their events.

### 4.6 Events without an aggregate, and the projection invariant

Events returned by a decision model carry no `_aggregate_id`, `_aggregate_type` or `_aggregate_version`. The 2.0
stream schema already permits that — PostgreSQL indexes expressions over `metadata` (NULLs are distinct), and the
MySQL/MariaDB generated columns are nullable. 1.x-era tables do not (§4.7).

| Projection kind | Sees aggregate-less events? | Why |
|---|---|---|
| Global — `#[Projection]` + `#[FromStream]` | **Yes** | Tracks `no`; filters by event name |
| `#[FromAggregateStream(X::class)]`, global | **No** | Filters `_aggregate_type = X` |
| `#[Partitioned]` | **No** | Partitions are enumerated from `(aggregate_type, aggregate_id)` and the position *is* the aggregate version. An event with neither belongs to no partition |

**Made explicit:** at bootstrap, if a projection that cannot see aggregate-less events declares an `#[EventHandler]`
for an event class that some `#[DecisionModel]` handles or is known to record, raise a `ConfigurationException`
naming the projection, the event and the fix (`#[FromStream]` on a global projection). Tag-partitioned projections
(`#[Partitioned(byTag: 'course')]`) are the natural follow-up and need a per-tag sequence; out of scope here.

**SQL-side filtering for projections** — the problem found in Part 1 — is a separate work item that tags *enable*
but do not require. One constraint any implementation must respect: `GapAwarePosition` infers in-flight
transactions from holes in `no`. A filtered query makes every non-matching row look like a hole. The fix is two
steps per page — an index-only scan of `no` for gap tracking, then a filtered fetch of payloads inside that window
— which still removes the payload transfer and JSON decoding that dominate today's cost.

### 4.7 Upgrade path — expand on 1.x, then upgrade

Everything below can be applied to a database while Ecotone **1.x is still running against it**, because 1.x names
its columns explicitly on insert, never reads the new tables, and always supplies aggregate metadata.

| Step | SQL | Safe on 1.x because |
|---|---|---|
| 1. Create `ecotone_event_tags`, `ecotone_event_tag_versions` | §4.2 | 1.x never references them |
| 2. Create `ecotone_event_stream` (optional on 1.x) | upgrade guide §4 | 1.x never references it |
| 3. *Only for a 1.x table that will receive decision-model events* — PostgreSQL: `ALTER TABLE "_<sha1>" DROP CONSTRAINT aggregate_version_not_null, DROP CONSTRAINT aggregate_type_not_null, DROP CONSTRAINT aggregate_id_not_null;` | | Dropping a `CHECK` only permits more; 1.x always writes the three fields. Metadata-only, instant |
| 3′. Same, MySQL/MariaDB: `MODIFY` the three `STORED` generated columns to drop `NOT NULL` (and widen `aggregate_id` from `CHAR(36)` to `VARCHAR(150)`) | | Same reasoning — **but this rebuilds the table**. On a large stream use an online schema-change tool |

Then upgrade the code to 2.0. `ecotone:migration:database:setup` finds the tables present and does nothing.

Step 3 is needed only when a decision model must share a table with 1.x aggregate events — i.e. it declares
`#[Stream(legacyStreamName: …)]` because its boundary includes events those aggregates recorded. A decision model
whose facts are all new writes to `ecotone_event_stream` and needs steps 1–2 only. If step 3 was skipped, the first
aggregate-less insert fails on the constraint; the store translates that into a `ConfigurationException` quoting
the exact `ALTER TABLE`.

**Tag backfill.** Events written before their class had `#[Tag]` — every 1.x event, and any 2.0 event tagged later
— have no index rows, so a decision model would not see them. `ecotone:event-store:backfill-tags [--stream=]
[--event=]` walks a stream by `no`, deserializes events whose class declares tags, inserts the missing index rows
(idempotent on the primary key, resumable by position) and bumps the affected counters so any in-flight decision is
invalidated. It runs on 2.0, online, and must complete before a model that depends on that history is enabled.

### 4.8 What is deliberately not in this plan

| Item | Why it is out |
|---|---|
| `pg_snapshot_xmin` gap detection | Valid, unimplemented, and independent of tags. Own design |
| UUID v7 for the store's fallback id | One-line change, unrelated |
| Replacing `MetadataMatcher` / the `EventStore` interface | §4 promised it unchanged |
| Tag-partitioned projections | Needs a per-tag sequence; follow-up |
| Precise (type-aware) conflict re-check | Measure the over-approximation first |
| Package rename | Not DCB |

---

## Part 5 — Open decisions for the maintainer

1. **Licence.** The store level (tags, counters, `TaggedEventStore`) sits with the rest of the store: Apache-2.0.
   Is `#[DecisionModel]` open, or Enterprise like `#[Partitioned]`?
2. **Automatic retry.** A `ConcurrencyException` from a decision model always means "run it again". Built-in and
   on by default (`#[DecisionModel(concurrencyRetries: 3)]`), or left to the existing instant-retry configuration?
3. **Naming.** `#[Tag]` vs `#[EventTag]`; `#[DecisionModel]` vs `#[ConsistencyBoundary]`; `TaggedEventStore`.
4. **`consistency: false` tags** — ship in the first cut, or start with every tag being a consistency tag?

## Part 6 — Implementation plan

*(written after review — see Part 7)*

## Part 7 — Review record

*(pending: Architect, Tech Lead, Ecotone 1.x user)*
