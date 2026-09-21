# DCB on `ecotone_event_stream` — 2.0 Design

Status: **in discussion** — solution drafted and reviewed (Architect, Tech Lead, 1.x user); maintainer decisions open in Part 5
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

A **decision model** is a throwaway aggregate: rebuilt for one command from the events selected *by tag* instead of
by aggregate id, asked to decide, and discarded. `ecotone_event_stream` does not change. Two side tables are
added: **`ecotone_event_tags`** (which event carries which tag — for reads) and **`ecotone_event_tag_versions`**
(one counter per tag value — for conflict detection). Events declare tags with `#[EventTag]`. Every append bumps
the counters of the tags it carries, then writes the events and their tag rows. A decision model's events are
appended on the condition that none of the counters it depends on moved since it read. Aggregates are untouched:
same columns, same unique index, same `#[Version]`.

### 4.2 Schema

**`ecotone_event_stream` and every `#[Stream]` table: no new columns, no new indexes.** That is what makes
constraint 4 cheap. PostgreSQL:

```sql
CREATE TABLE ecotone_event_tags (
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    stream     VARCHAR(128) NOT NULL,
    event_no   BIGINT       NOT NULL,
    PRIMARY KEY (tag_key, tag_value, stream, event_no)
);

CREATE TABLE ecotone_event_tag_versions (
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT       NOT NULL,
    PRIMARY KEY (tag_key, tag_value)
) WITH (fillfactor = 70);

CREATE TABLE ecotone_event_tag_coverage (
    event_name  VARCHAR(255) NOT NULL,
    tags_hash   CHAR(40)     NOT NULL,
    covered_at  TIMESTAMP(6) NOT NULL,
    PRIMARY KEY (event_name, tags_hash)
);
```

MySQL / MariaDB: identical shape, `ENGINE=InnoDB ROW_FORMAT=DYNAMIC`, **`COLLATE utf8mb4_bin`** — the server
default `utf8mb4_0900_ai_ci` would merge `course:ABC` with `course:abc`. Worst-case primary key is
400 + 1020 + 512 + 8 bytes, inside InnoDB's 3072-byte limit and PostgreSQL's btree tuple limit.

- **One pair per connection; counters are not keyed by stream.** On 1.x every aggregate type lives in its own
  `_<sha1>` table, and upgrade guide §4 tells users to leave them there. A cross-aggregate invariant — the whole
  point of DCB — therefore spans tables. The index records *which* table each event is in; the counter is about the
  tag alone. **A boundary may span every stream on one connection.** It cannot span connections: there is no
  transaction to hold it.
- `stream` is the physical table name. `event_no` is that table's `no`.
- `ecotone_event_tag_coverage` is the guard against deciding on an incomplete index (§4.8).
- No foreign keys. `EventStore::delete($stream)` deletes that stream's index rows in the same transaction — without
  this, a re-created stream restarts `no` at 1 and stale rows join to unrelated events (every test suite that resets
  streams would hit it). Counters are left: a stale counter can only cause one spurious retry.
- Tag values are validated in PHP before they reach SQL: non-empty, ≤ 255 characters, no trailing whitespace
  (`utf8mb4_bin` is PAD SPACE — `'abc'` equals `'abc '` on MySQL but not on PostgreSQL). Non-strict MySQL would
  otherwise truncate silently and the decision would silently miss events.
- All three tables register with `ecotone:migration:database:setup` under their **own feature, `event_tags`**,
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
  decision model includes aggregate-produced facts in its boundary.
- Tags are stored in plaintext beside the payload and appear in diagnostics. Do not tag personal data directly —
  tag a hash through a method.

**Filter-only tags.** Every tag an event carries bumps a counter row that is then held until commit. A
low-cardinality tag (`tenant`, `region`) would make most writers queue behind each other. Declared once per key,
so two event classes can never disagree:

```php
#[ServiceContext]
public function eventSourcing(): EventSourcingConfiguration
{
    return EventSourcingConfiguration::createWithDefaults()->withFilterOnlyTags(['tenant']);
}
```

A filter-only tag is indexed and never counted. A decision model that depends on one is a bootstrap
`ConfigurationException`.

### 4.4 Decision models

A student may subscribe if the course has room and the student has fewer than five courses. Two entities, one
decision, no aggregate that owns it:

```php
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\MatchingTags;

#[DecisionModel]
final class CourseSubscription
{
    #[EventTag('course')]  private string $courseId;
    #[EventTag('student')] private string $studentId;

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
    public function courseDefined(CourseDefined $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler, MatchingTags('course')]
    public function seatTaken(StudentSubscribedToCourse $event): void
    {
        $this->seatsTaken++;
    }

    #[EventSourcingHandler, MatchingTags('student')]
    public function courseJoined(StudentSubscribedToCourse $event): void
    {
        $this->coursesOfStudent++;
    }

    #[EventSourcingHandler, MatchingTags('course', 'student')]
    public function subscribed(StudentSubscribedToCourse $event): void
    {
        $this->alreadySubscribed = true;
    }
}
```

No query object, no event store call, no append condition, **and no `if ($event->courseId === $this->courseId)`**.
That last point is where this beats every other single-class DCB API (Gember, Axon's `State` entities and Backslash
all make the user hand-filter inside the fold; forget one `if` and the model silently counts the wrong rows). The
composed-projection libraries (wwwision, patchlevel) avoid it only by making the user assemble projections and a
query by hand. Sending the command is unchanged: `$commandBus->send(new SubscribeStudentToCourse('c1', 's1'))`.

**Each `#[EventSourcingHandler]` is a small projection; the boundary is the union of what the handlers ask for.**

| Handler | Criterion it contributes |
|---|---|
| `courseDefined(CourseDefined)` — no `#[MatchingTags]` | every model tag the event class declares: `course:<courseId> ∧ CourseDefined` |
| `seatTaken` — `MatchingTags('course')` | `course:<courseId> ∧ StudentSubscribedToCourse` |
| `courseJoined` — `MatchingTags('student')` | `student:<studentId> ∧ StudentSubscribedToCourse` |
| `subscribed` — `MatchingTags('course','student')` | `course:<courseId> ∧ student:<studentId> ∧ StudentSubscribedToCourse` — subsumed by the two above, dropped |

Criteria are OR-ed — the dcb.events query shape, including AND within a criterion, which the first draft could not
express. On replay a handler runs only when the event's values for its listed keys equal the model's. Handlers
without `#[MatchingTags]` on an event class that declares *several* of the model's tags run when **any** matches.
A handled event declaring none of the model's tags, or an interface/union handler parameter (its event types
cannot be enumerated), is a bootstrap `ConfigurationException`.

**Tag values come from the command** in the order aggregates resolve `#[Identifier]`: a command property named like
the model's tagged property; then a command property carrying the same `#[EventTag]` key; then `identifierMapping`
/ `identifierMetadataMapping` on `#[CommandHandler]`. An array value selects several values of one key.

**The boundary is computed per `#[CommandHandler]`, not per class.** A model with `subscribe` and
`changeCapacity` shares the `capacity` fold; `ChangeCourseCapacity` cannot supply `student`, so that tag's handlers
and criteria are simply not part of that handler's boundary. One class per decision would duplicate folds — that is
where the boilerplate really accumulates.

**Escape hatch**, for a boundary the derivation cannot express:

```php
#[DecisionBoundary]
public static function boundary(RateCourse $command): EventCriteria
{
    return EventCriteria::tags(['course' => $command->courseId, 'student' => $command->studentId])
            ->ofTypes(StudentSubscribedToCourse::class)
        ->or(EventCriteria::tag('course', $command->courseId)->ofTypes(CourseRated::class));
}
```

**Also supported**, because aggregates support them and users will expect it: `#[Reference]` and `#[Header]`
parameters on the handler; `return []` as a no-op; `#[QueryHandler]` on a model (read, fold, reply — no condition,
for pure validation); `#[EventHandler]` on a model (a policy that decides when an event arrives);
`#[Asynchronous]` (tags are resolved before the channel, as aggregate identifiers are); `#[Stream]` on the model to
choose where its events go (default `ecotone_event_stream`). The class needs a public no-argument constructor —
the same rule `EventSourcingHandlerExecutor` applies to aggregates. Pointcuts target `DecisionModel::class`.

**Lifecycle:** resolve tags → capture counters → load and fold matching events → invoke handler → append returned
events under the condition → publish them on the event bus, with the same metadata propagation aggregate events
get. Decision-model events carry no aggregate id, type or version (§4.6).

**Testing** needs nothing new — tags are on the events:

```php
EcotoneLite::bootstrapFlowTesting([CourseSubscription::class])
    ->withEvents([new CourseDefined('c1', capacity: 1), new StudentSubscribedToCourse('c1', 's1')])
    ->sendCommand(new SubscribeStudentToCourse('c1', 's2'));   // expects CourseIsFull
```

`InMemoryEventStore` implements the full contract — index, counters bumped on *every* append, compare-then-append
— so flow tests exercise real conditional-append semantics without a database. A conflict is forced
deterministically by injecting a service that appends a competing event on first call.

**Without the class**, the same machinery is a gateway:

```php
$decision = $taggedEventStore->load(
    EventCriteria::tag('course', $courseId)->ofTypes(CourseDefined::class, StudentSubscribedToCourse::class)
);
// fold $decision->events
$taggedEventStore->appendTo('ecotone_event_stream', [new StudentSubscribedToCourse(...)], $decision->appendCondition);
```

`load()` returns the events **and** the ready-made condition; user code never touches a version.
`TaggedEventStore` is a **new** interface beside `Ecotone\EventSourcing\EventStore`, which stays exactly as upgrade
guide §4 promised. Both live in core (`packages/Ecotone`), as do the attributes — `InMemoryEventStore` and the
decision-model flow are core, and core cannot depend on `PdoEventSourcing`. This follows the precedent that put
`EventSourcingAggregate` in `Ecotone\Api\Attribute`. Only schema, DBAL implementation and the console commands
live in `PdoEventSourcing`.

### 4.5 Concurrency, in full

**Invariant, maintained by every append — conditional or not, decision model or aggregate:** for each distinct
counted tag the appended events carry, `ecotone_event_tag_versions.version` is incremented by one in the same
transaction as the event insert. Without the unconditional half, a conditional writer cannot see an unconditional
one ([ruby-dcb #42](https://github.com/kjeldahl/ruby-dcb/issues/42)).

**Read side — capture first, then read:**

```sql
-- 1. capture (a missing row is version 0)
SELECT tag_key, tag_value, version FROM ecotone_event_tag_versions
WHERE (tag_key, tag_value) IN ((:k1, :v1), (:k2, :v2));

-- 2. which events, in which stream, matching which criterion
SELECT stream, event_no,
       MAX(CASE WHEN tag_key = :k1 AND tag_value = :v1 THEN 1 ELSE 0 END) AS has_t1,
       MAX(CASE WHEN tag_key = :k2 AND tag_value = :v2 THEN 1 ELSE 0 END) AS has_t2
FROM ecotone_event_tags
WHERE (tag_key, tag_value) IN ((:k1, :v1), (:k2, :v2))
GROUP BY stream, event_no;

-- 3. per stream: the events themselves
SELECT no, event_name, payload, metadata, created_at FROM <stream>
WHERE no IN (:nos) AND event_name IN (:types) ORDER BY no;
```

Statement 2 drives from the primary key and yields each event **once**, however many criteria it matches — a
`UNION` would double-apply `StudentSubscribedToCourse(c1, s1)`, and `DISTINCT` over `payload` fails outright on
PostgreSQL (`json` has no equality operator). Criteria with event-type and AND conditions are evaluated in PHP from
the flags. Order: `no` within a stream; across streams `created_at`, then stream name — the rule multi-stream
projections already use.

Capture-before-read matters. A writer committing between the statements makes the events *newer* than the captured
version: a spurious retry, safe. The opposite order pairs a newer version with older events and **misses** the
conflict.

**Write side.** The store opens its own transaction if none is active — today's `appendTo()` is one atomic INSERT,
this is several statements, and handlers under `#[WithoutDatabaseTransaction]`, streams on a non-default
connection and direct gateway calls have no ambient transaction.

```sql
-- A. ONE pass over the union of (condition tags ∪ tags of the new events), sorted by (tag_key, tag_value).
--    Per tag, one of:

--    in the condition, captured > 0
UPDATE ecotone_event_tag_versions SET version = version + 1
WHERE tag_key = :k AND tag_value = :v AND version = :captured;          -- 0 rows → ConcurrencyException

--    in the condition, captured = 0
INSERT INTO ecotone_event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1)
ON CONFLICT DO NOTHING;                                                  -- 0 rows → ConcurrencyException
--    MySQL/MariaDB: plain INSERT, duplicate key → ConcurrencyException

--    not in the condition
INSERT INTO ecotone_event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1)
ON CONFLICT (tag_key, tag_value) DO UPDATE SET version = ecotone_event_tag_versions.version + 1;
--    MySQL/MariaDB: ON DUPLICATE KEY UPDATE version = version + 1

-- B. events
INSERT INTO <stream> (event_id, event_name, payload, metadata, created_at) VALUES ...;

-- C. tag index, resolving no by the unique event_id
INSERT INTO ecotone_event_tags (tag_key, tag_value, stream, event_no)
SELECT :k, :v, :stream, no FROM <stream> WHERE event_id = :eventId;
```

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

**The honest cost against constraint 1.** The only lock is the row lock the write itself takes, held to commit —
the same *kind* of lock today's unique-index insert takes. But not the same *reach*: today a writer waits only on a
writer of the identical `(type, id, version)` — one it truly conflicts with. A counter also queues **unconditional**
writers that merely share a tag: two different aggregates whose events are both tagged `course:c1` now commit one
after the other, including their synchronous `#[EventHandler]`s. No variant avoids this without reintroducing the
missed-conflict bug. It is the price of tagging an event, paid only by events that are tagged — hence filter-only
tags, and hence: **tag what a decision needs, nothing else.**

**The MySQL/MariaDB snapshot hazard.** Under `REPEATABLE READ` a transaction reads from the snapshot opened by
its first SELECT — *plus its own writes*. If transaction T opens a snapshot (counter = 7), a foreign event E
commits (8), T bumps the same tag while saving a tagged aggregate event (a current read: 9), and a synchronous
handler in T then runs a decision model on that tag — it captures 9 (its own write), reads events from the old
snapshot (no E), and its guard passes. Fix, InnoDB only: before a transaction's first bump of a tag, read the
snapshot value `s`; count own bumps `n`; a decision capture that differs from `s + n` throws `ConcurrencyException`
and the retry gets a fresh snapshot. PostgreSQL snapshots per statement and is unaffected. Recent MariaDB
(`innodb_snapshot_isolation=ON`) raises error 1020 instead — mapped below.

**Deadlocks still happen, and are conflicts.** Sorting removes cycles *within* one append. It cannot remove
them across two appends in one transaction, nor InnoDB's three-way duplicate-insert deadlock on a never-written
tag (the hot path of every uniqueness claim). MySQL 1213/1205, MariaDB 1020, PostgreSQL 40P01/40001 are mapped to
`ConcurrencyException`. Today only `UniqueConstraintViolationException` is (`DbalEventStore.php:122`).

**Retry.** A `ConcurrencyException` from a decision model always means *run the command again*. The retry must
wrap the transaction — inside it, InnoDB re-reads the same snapshot forever. Ecotone already has the right piece:
`InstantRetryInterceptor` sits at precedence −2002, outside the transaction interceptor at −2000, and steps aside
when already inside a transaction. So: `DecisionModelConcurrencyException extends ConcurrencyException`, and when
any `#[DecisionModel]` exists and the user has not configured command-bus retry, that interceptor is registered on
`CommandBus` for this exception only, three attempts. Tuning stays in `InstantRetryConfiguration`. A decision-model
command sent from *inside* another message's transaction is not retried — the exception surfaces and the outer
message fails, because on PostgreSQL the outer transaction cannot be continued anyway. Asynchronous endpoints
already retry three times by default.

**What the user sees** on exhaustion: the model class, the tag `key:value`, captured vs. current version, attempts
made — not a database error string. Each retry logs at info.

**Known over-approximation.** Counters are per tag, not per tag-and-type. An AND criterion is guarded by one of its
tags — the first listed, so list the most selective first. Any event sharing a counted tag with an in-flight
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
| 6 | on 2.0 | `ecotone:event-store:backfill-tags` | | re-runnable |
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
and why the guard in §4.8 exists: the rule is enforced, not just written down. Rolling code back to 1.x after
decision models ran means re-running the backfill before rolling forward.

### 4.8 The coverage guard and the backfill

Events recorded before their class declared its current tags — every 1.x event, and any event tagged later — have
no index rows. A model deciding on them reads too little and approves wrongly, **silently**. So:

- `ecotone_event_tag_coverage` holds one row per `(event_name, hash of the class's tag declaration)`, written when
  the index is known complete for that pair.
- A decision model checks, once per process, that every event type in its boundary has a current row. If not it
  throws a `ConfigurationException` naming the event and the command. Changing a class's `#[EventTag]`s changes the
  hash and trips the guard again — the "a year from now" case.
- With automatic table initialization (tests, dev) the backfill runs implicitly on a miss. In production it is a
  deploy step.

`ecotone:event-store:backfill-tags [--stream=] [--event=] [--batch-size=500] [--sleep-ms=] [--dry-run]`:
walks each stream by `no`; per batch, in **one transaction**, bumps the affected counters in sorted order and
inserts the missing index rows (idempotent on the primary key) — the bump invalidates any in-flight decision, and
small batches keep hot counters held briefly. Progress is persisted per stream, so it resumes. It stops behind the
gap-aware horizon and finishes with a second pass, because a lower `no` can commit after the cursor passed it —
the same problem projections have. A payload that no longer deserializes is reported with its `no` and skipped only
under `--skip-undeserializable`. Coverage rows are written last. There is no index on `event_name`, so a large
stream is a full scan: hours on tens of millions of rows, once.

### 4.9 Licence — DCB is Enterprise

Maintainer decision (2026-09-21): **the whole of DCB is under the Enterprise licence** — `#[EventTag]`,
`#[DecisionModel]`, `#[MatchingTags]`, `#[DecisionBoundary]`, `TaggedEventStore`, `EventCriteria`,
`AppendCondition`, the tag tables and the console commands. Every new class carries `licence Enterprise`.

Gated the way projections' enterprise features already are (`ProjectingModule.php:69-72`), at bootstrap, in the
module's `prepare()`:

- Any `#[EventTag]` or `#[DecisionModel]` found while `isRunningForEnterpriseLicence()` is false →
  `LicensingException` naming the class and the feature. Failing at bootstrap rather than on first command matters
  here: an application that *silently ignored* `#[EventTag]` without a licence would record events with no index
  rows, and would need a backfill the day the licence is added.
- `TaggedEventStore` is registered unconditionally (the project rule: no nullable services, gate at runtime) and
  throws `LicensingException` from `load()` and the conditional `appendTo()` without a licence.
- Consequences that fall out for free: an open-core application has no tags, so **the append path is byte-for-byte
  today's single INSERT** — no counter statements, no own-transaction wrapper, no tag tables. The store's
  behaviour for existing users does not change at all.
- Tests use the existing `LicenceTesting::VALID_LICENCE` with `EcotoneLite::bootstrapFlowTesting(...,
  enterpriseLicenceKey: ...)`.
- The 1.x expand-first runbook (§4.7) is unaffected: the DDL is published in the docs, and creating three unused
  tables needs no licence.

What stays open-core: nothing in this plan. SQL-side projection filtering (§4.6) is a separate work item and, where
it filters by event name and aggregate type, does not depend on tags or on a licence.

### 4.10 Deliberately not in this plan

| Item | Why |
|---|---|
| `pg_snapshot_xmin` gap detection | Independent of tags. Own design |
| UUID v7 for the store's fallback id | One-line change, unrelated |
| Replacing `MetadataMatcher` / the `EventStore` interface | §4 promised it unchanged |
| Tag-partitioned projections | Follow-up; made sound by counters-first ordering |
| Decision-model snapshots | Follow-up; same |
| Composable decision state — `#[DecisionState]` classes injected into a handler | Follow-up. Per-handler boundaries keep it additive |
| An aggregate command handler saving under a tag condition | Asked for by the 1.x-user review; it blends the two concurrency mechanisms constraint 2 keeps apart. Needs its own discussion |
| Precise (type-aware) conflict re-check | PostgreSQL only; measure first |
| SQL-side projection filtering | Enabled by this, specified in §4.6, separate work item |

---

## Part 5 — Open decisions for the maintainer

| # | Decision | Recommendation |
|---|---|---|
| 1 | ~~**Licence.**~~ | **Decided 2026-09-21: all of DCB is Enterprise** — §4.9. (My recommendation had been Apache-2.0 for the base layer; overruled, and the design is simpler for it: one gate, and zero change to the open-core append path) |
| 2 | **Boundaries span streams** (counters keyed by tag only). | Yes. Without it the feature is greenfield-only: 1.x users' invariants span two `_<sha1>` tables by construction. Cost: cross-stream event order is `created_at`, and a tag reused in two unrelated streams shares a counter (spurious retries only) |
| 3 | **Coverage guard on by default.** | Yes. The alternative is a silent wrong decision. Cost: one deploy step when tags change on recorded events |
| 4 | **Automatic retry** through the existing instant-retry interceptor, no new attribute parameter. | Yes |
| 5 | **Names**: `#[EventTag]`, `#[DecisionModel]`, `#[MatchingTags]`, `#[DecisionBoundary]`, `EventCriteria`, `TaggedEventStore::load/appendTo`. | `EventTag` over `Tag` (self-describing; `Tag` collides with Symfony and OpenAPI attributes; Axon and patchlevel use it). `EventCriteria` over `EventQuery` ("query" already means a CQRS message here). `MatchingTags` is the one I am least sure of |
| 6 | **Filter-only tags** declared per key in `EventSourcingConfiguration`, in the first cut. | Yes — the Architect wanted it in the first cut, the Tech Lead wanted it per key not per usage; this is both |

## Part 6 — Implementation plan

One worker session per task, test-first, sequential, in docker. Core tests use inline anonymous classes. Tasks 6–8
depend only on task 2, so the user-facing layer can be reviewed on the in-memory store while 3–5 proceed.

0. **Every task:** new classes carry `licence Enterprise`; tests bootstrap with `LicenceTesting::VALID_LICENCE`.
1. **Core — `#[EventTag]`, the tag registry, and the licence gate.** `LicensingException` at bootstrap when an
   `#[EventTag]` or `#[DecisionModel]` exists without an Enterprise licence (test it first — it is the cheapest
   test in the plan and every later task depends on it). `packages/Ecotone/Api/Attribute/EventTag.php`,
   `src/EventSourcing/Tagging/*`, a module scanning with `findClassesWithAnnotatedProperties`. Tests: property,
   promoted parameter, method, class-level literal; repeated key; array value; `null` skipped; non-scalar type and
   invalid value (empty, > 255, trailing space) rejected; filter-only keys from `EventSourcingConfiguration`.
2. **Core — `TaggedEventStore`, `EventCriteria`, `AppendCondition`, in-memory implementation.** Gateway
   registration; `withEvents()` writes to the default stream; `InMemoryEventSourcedRepository` writes through the
   store so a model can see aggregate facts in core-only tests. Tests: OR and AND criteria; type filter; an event
   matching two criteria is returned once; conditional append succeeds/fails; an unconditional `appendTo`
   invalidates a held condition; a disjoint tag does not; `delete()` clears the index.
3. **PdoEventSourcing — schema.** Tag schema classes per platform, table manager under its own `event_tags`
   feature with `isUsed()` true only when tags are declared, §8 behaviour, per-tenant ensure. Tests: an
   application with no `#[EventTag]` lists and creates no tag tables; with tags, setup creates and lists all three
   and prints them with `--sql`;
   missing table → `ConfigurationException` naming the command; `utf8mb4_bin` keeps `ABC` ≠ `abc`.
4. **PdoEventSourcing — every `appendTo` bumps counters and writes index rows.** First test: with no tags
   declared, `appendTo` issues exactly the one INSERT it issues today. Then: counters first, merged sorted
   pass, own transaction when none is active, `event_id` sub-select, tag carrier through `SerializingEventStore`,
   `delete()` cleanup. Tests on PostgreSQL, MySQL, MariaDB, inspecting the tables directly: tagged aggregate
   events; untagged aggregates write nothing; works under `#[WithoutDatabaseTransaction]` and rolls back whole.
5. **PdoEventSourcing — `load(criteria)` and conditional append. The riskiest task** — it must prove real
   concurrency on three engines, which no in-memory test can. Two-connection tests: conflict on an existing
   counter; conflict on a never-written tag; loser wrote nothing and burned no `no`; rollback lets the waiter
   succeed; opposite-order multi-tag appends do not deadlock; aggregate save `{A,B}` vs. model on `{B}` do not
   deadlock; the InnoDB own-bump snapshot hazard throws; deadlock and lock-timeout codes surface as
   `ConcurrencyException`; cross-stream boundary reads both tables.
6. **Core — `DecisionModelModule`, default derivation.** `RoutingEventHandler` like `AggregrateModule`; chain
   `ResolveTags → Load → Call → Append`; reuse `EventSourcingHandlerExecutorBuilder`,
   `SaveAggregateServiceTemplate::buildEcotoneEvents`, `CallAggregateServiceBuilder`; skip the class in
   `ServiceHandlerModule`; strip the tag header from published metadata. Tests: the canonical example; business
   exception; `[]` no-op; events reach an `#[EventHandler]` and a saga; metadata propagates; `#[Reference]`;
   `#[Asynchronous]` + `run()`; pointcut on `DecisionModel::class`; `#[QueryHandler]`/`#[EventHandler]` on a
   model; unresolvable tag; constructor rule.
7. **Core — `#[MatchingTags]`, per-handler boundary, `#[DecisionBoundary]`.** Tests: the rating example — an
   unrelated subscription does not conflict, an unrelated rating does; two command handlers with different
   boundaries; transfer with one key twice; interface handler parameter rejected; subsumed criterion dropped.
8. **Core — `DecisionModelConcurrencyException` and default command-bus retry.** Tests: injected conflict
   succeeds on the second pass; exhaustion message contents; no retry inside an outer transaction; the user's
   `InstantRetryConfiguration` wins.
9. **PdoEventSourcing — decision model end to end on DBAL.** `#[Stream]` on a model; a 1.x-shaped table raising
   the `ConfigurationException` that quotes the `ALTER`; the append-time projection invariant; multi-tenant.
10. **PdoEventSourcing — coverage guard, `backfill-tags`, `verify-schema`.** Tests: model refuses without
    coverage; changing a class's tags trips it; backfill is idempotent, resumable, bumps counters so an in-flight
    condition fails; automatic initialization backfills implicitly; `verify-schema` catches a wrong collation and a
    missing relaxation.
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
| — | Part 5, decisions 2–6 | **open** | |
