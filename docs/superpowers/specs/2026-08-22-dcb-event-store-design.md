# DCB Event Store — 2.0 Design (Group D)

Status: draft — awaiting maintainer approval
Date: 2026-08-22
Release group: D (own DBAL event store, global log, tags, conditional append, uuid7, Prooph removal)
Blocks: Group B (`docs/superpowers/specs/2026-08-22-projection-v1-removal-design.md`)
Research: `docs/superpowers/research/dcb-event-store/report.md`

## Problem

`Ecotone\EventSourcing\EventStore` is stream-centric — `create/appendTo/delete/hasStream/load(streamName,
fromNumber, count, MetadataMatcher)` — and every implementation is a thin wrapper over
`prooph/pdo-event-store` (21 files under `packages/PdoEventSourcing/src` import `Prooph\*`). No DCB concept
exists anywhere in `src`.

| # | Finding | Evidence |
|---|---|---|
| 1 | Four persistence strategies (`single`, `partition`, `aggregate`, `simple`, plus `custom`), and the physical layout **is** the consistency boundary | `LazyProophEventStore.php:64-72` |
| 2 | **Bug**: `EventSourcingConfiguration::withPartitionStreamPersistenceStrategy()` sets `SINGLE_STREAM_PERSISTENCE` | `EventSourcingConfiguration.php:83` |
| 3 | Optimistic concurrency is `_aggregate_version` metadata backed by a physical `UNIQUE(aggregate_type, aggregate_id, aggregate_version)` — not a query + position condition | `LazyProophEventStore.php:75,159,172` |
| 4 | Event ids are uuid**4** in the store's fallback path | `ProophInMemoryEventStoreAdapter`, `EcotoneEventStoreProophWrapper` |
| 5 | Gap detection is Prooph's wall-clock retry window, reimplemented in `GapAwarePosition::cleanGapsByTimeout()` — [#438](https://github.com/ecotoneframework/ecotone-dev/issues/438) calls it fragile in production | `Prooph/GapDetection.php`, `GapAwarePosition.php` |
| 6 | The v2 stream sources **already assume** a global, gappy `no` sequence, but no store underneath guarantees one; `EventStoreGlobalStreamSource::loadFromMultipleStreams()` merges several physical tables by timestamp | `EventStoreGlobalStreamSource.php` |
| 7 | Aggregate loading duplicates tag-query logic ad hoc — a stream name plus three `MetadataMatcher` matches on `_aggregate_type`, `_aggregate_id`, `_aggregate_version` | `EventStoreAggregateStreamSource::loadFromStreamFilter()` |
| 8 | Per-stream tables (`_<sha1>`) are created on the fly by Prooph's persistence strategies, so `DatabaseSetupManager` cannot pre-create them | Group F2 finding |

Verified as **not** existing today (`rg`, not assumed): no `Tag`, no `AppendCondition`, no
`LoadEventSourcingAggregateService`, no upcasting mechanism, and `AggregateVersionMismatchException` is defined
but thrown nowhere — the user-visible failure is `Ecotone\Messaging\Support\ConcurrencyException`.

## Goals / Non-goals

**Goals**
1. One global event log. Physical partitioning becomes a scaling concern, never a consistency concern.
2. Tags on events (aggregate type/id + domain tags), queryable as tags ∧ event types, ordered by a global position.
3. Conditional append with a **real, per-engine atomic mechanism** — not a re-check that races.
4. Provable gap handling on PostgreSQL; an honest, documented fallback on MySQL/MariaDB.
5. No Prooph.
6. A stable `StreamSource` seam so Group B is a rewrite of two classes, not of the projection runtime.

**Non-goals**
- First-class DCB **decision models** (`#[CommandHandler]` folding a multi-tag projection). The store API is
  designed not to block them; the ergonomic layer is a follow-up release.
- Upcasting (does not exist today).
- Exposing `global_position` as user-facing API (Open Question).

## Prior art

| Source | What it gives us |
|---|---|
| Sara Pellegrini — *Kill Aggregate* / Dynamic Consistency Boundary ([talk](https://www.youtube.com/watch?v=DhhxKoOpJe0), [articles](https://sara.event-thinking.io/)) | The core idea: the consistency boundary is a *query*, chosen per decision, not a physical stream |
| [dcb.events specification](https://dcb.events/) | `Query` (OR-ed items of AND-ed tags+types), `AppendCondition`, `expectedHighestSequenceNumber`. Our vocabulary follows it; §Decision documents where our enforcement diverges |
| [Axon Framework 5 DCB support](https://docs.axoniq.io/) | Confirms DCB is becoming mainstream, not experimental |
| [Marten](https://martendb.io/) — `mt_events`, high-water mark, `mt_dcb_tag_version` | **Two mechanisms borrowed**: never advance past a position that might still be preceded by an unresolved writer; and a per-tag version row used as a compare-and-swap target |
| [`bwaidelich/dcb-eventstore`](https://github.com/bwaidelich/dcb-eventstore) + [`-doctrine` adapter](https://github.com/bwaidelich/dcb-eventstore-doctrine) | The real PHP DCB implementation. Read its source, not just its README — see Decision for the two structural reasons we do not adopt it |
| [PostgreSQL transaction isolation](https://www.postgresql.org/docs/current/transaction-iso.html) | SSI genuinely detects this write-skew, at the cost of whole-transaction read-set tracking and mandatory retry |
| [Oskar Dudycz — Postgres outbox with `xmin`](https://event-driven.io/en/ordering_in_postgres_outbox/), [Postgres snapshots & tuple visibility](https://jnidzwetzki.github.io/2024/04/03/postgres-and-snapshots.html) | The `transaction_id < pg_snapshot_xmin(pg_current_snapshot())` technique |
| [EventStoreDB / KurrentDB](https://developers.eventstore.com/) | Optional expected-revision on append — `append()` without a condition must stay legal |
| message-db, EventSauce, Broadway, Rails Event Store, Commanded, Emmett | Surveyed; none solves the tag-query + conditional-append problem in a way that changes the recommendation |

## Decision

**Build an Ecotone-native DBAL event store: `event_log` + `event_tags` + `event_tag_versions`, one uniform
physical layout on PostgreSQL, MySQL, MariaDB and SQLite.**

| Option | Verdict |
|---|---|
| **A — Ecotone-native, normalised tags** | **Chosen.** Full control over DDL, error messages, migration mechanics and cross-engine parity, at a bounded complexity cost consistent with code `packages/Dbal` already maintains |
| B — adopt `bwaidelich/dcb-eventstore` + its DBAL adapter | Rejected on two **code-level** grounds, not vague risk: (i) its Postgres concurrency path demands its own top-level `SERIALIZABLE` transaction (it asserts `getTransactionNestingLevel() === 0`), which is incompatible with Ecotone's one-shared-transaction-per-message model; (ii) it indexes tags on Postgres only, leaving MySQL/MariaDB with unindexed `JSON_CONTAINS` table scans |
| C — fork/vendor Prooph and bolt tags on | Rejected — keeps the persistence-strategy and stream-name baggage the whole initiative exists to remove |
| D — JSONB tags + GIN on Postgres, side table elsewhere | Rejected **after initially being chosen**: `tag_local_seq` (needed for versioning and post-snapshot loads) forces the side table onto Postgres anyway, so JSONB becomes a second physical copy of the same facts — pure extra write cost. Marten's own DCB feature uses a side table (`mt_dcb_tag_version`), not its JSONB column; that is the closer analogy |

### Conditional append: `event_tag_versions` compare-and-swap

This is the load-bearing mechanism. A "re-check before insert" does **not** work: under `READ COMMITTED`
(Postgres default) or `REPEATABLE READ` (InnoDB default), two writers both see nothing and both commit.

Options weighed: `SERIALIZABLE` + retry on `40001` (correct, but tracks the *whole* transaction's read set and
requires its own top-level transaction — incompatible with Group F's model); `INSERT … SELECT … WHERE NOT EXISTS`
(a phantom hazard unless wrapped in `SERIALIZABLE`, so it inherits that cost); a unique index (cannot express an
arbitrary tag ∧ type predicate); Postgres advisory locks keyed on a hashed tag set (correct and composable — kept
as a fallback, but needs application-managed lock ordering and collision handling); `SELECT … FOR UPDATE`
(**confirmed broken** — Postgres has no gap locking outside `SERIALIZABLE`, so a scan matching zero rows takes no
lock at all).

**Chosen — UPSERT-with-guard on `event_tag_versions`, uniformly on all four engines:**

```sql
-- PostgreSQL / SQLite (3.24+)
INSERT INTO event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1)
ON CONFLICT (tag_key, tag_value)
DO UPDATE SET version = event_tag_versions.version + :count
WHERE event_tag_versions.version = :captured;

-- MySQL / MariaDB
INSERT INTO event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1)
ON DUPLICATE KEY UPDATE version = IF(version = :captured, version + :count, version);
```

It works at the engines' **default** isolation levels with no advisory lock, because an
UPSERT/`ON DUPLICATE KEY UPDATE` takes a real row lock and evaluates its guard against the *current committed*
row, not the transaction's MVCC snapshot. T2 attempting the same tag at the same captured version **blocks** on
T1's row lock; once unblocked, its `WHERE version = :captured` fails, zero rows are affected, and the store throws
the same `Ecotone\Messaging\Support\ConcurrencyException` users see today — retried by the existing
`InstantRetryConfiguration`. Tags are sorted lexicographically before the UPSERTs are issued, so multi-tag
appends cannot deadlock against each other.

**Documented divergence from dcb.events.** `event_tag_versions` is keyed `(tag_key, tag_value)`, so **any** event
carrying that tag bumps the version — including one that does not match an `ofTypes()` filter in the condition's
query. This is a **conservative over-approximation**: it never misses a real conflict, but it can raise
`ConcurrencyException` for a write the spec would allow. The alternative — version rows keyed
`(tag_key, tag_value, event_type)` — forces a condition with no type filter to CAS every type that tag has ever
carried, which is worse. 2.0's shipped surface (single-aggregate loading) never puts a type filter on its
captured tag, so the divergence is invisible until decision models ship. **Accepted, and stated in the docs.**

### Gap detection

**PostgreSQL — provable, no timeouts:**

```sql
SELECT global_position, event_id, event_type, payload, metadata, recorded_at
FROM event_log
WHERE global_position > :lastDeliveredPosition
  AND transaction_id < (SELECT pg_snapshot_xmin(pg_current_snapshot()))
ORDER BY global_position
LIMIT :limit
```

A row written by an uncommitted transaction is invisible to the reader's snapshot **at all** — so the technique
cannot be "find the in-flight minimum", it must be "withhold anything that could still be preceded by an
unresolved writer". Worked example: T1 (txid 4998) is slow writing position 100; T2 (txid 5000) commits position
101 first. `pg_snapshot_xmin()` returns 4998, so the filter withholds 101 even though it is committed. Once T1
resolves and xmin advances, both rows pass and are delivered together in position order. If T1 rolled back,
position 100 never existed and 101 is delivered as soon as xmin clears — the gap closes the instant it is
*provably* permanent, with no timeout and no re-check step.

**Consequence: `GapAwarePosition` disappears on PostgreSQL.** Delivery is strictly contiguous, so there is no gap
list. It survives **only** on the MySQL/MariaDB path, where no equivalent visibility primitive exists
(`information_schema.INNODB_TRX` is privileged, non-portable, and its trx ids do not map to `AUTO_INCREMENT`
values) and a bounded wall-clock window remains the only option — with a loud log line whenever a gap is closed
by timeout rather than by proof.

This means the opaque `ProjectionStateTableManager.last_position TEXT` holds **different encodings per engine**
(a bare integer on Postgres, `"pos:g1,g2"` on MySQL/MariaDB). Positions are not portable across an engine
migration. Documented, not hidden.

Rejected: monotonic allocation via a singleton advisory lock (kills write concurrency globally — kept as an opt-in
`withSerializedAppend()` escape hatch for low-throughput deployments); a commit-time trigger populating a second
ordering table (fires inside the transaction, so it does not avoid the same race).

## Public API

Namespace: `Ecotone\EventSourcing\Api\*` per Group H (public surface a decision model is written against);
`TagCriteria` and the SQL compilation stay `@internal` to the store.

```php
namespace Ecotone\EventSourcing\Api\Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $literalValue = null,
    ) {}
}
```

```php
use Ecotone\EventSourcing\Api\Attribute\Tag;

#[Tag('context', 'ticketing')]
final class TicketReserved
{
    public function __construct(
        #[Tag('ticket')] public readonly string $ticketId,
        #[Tag('event')]  public readonly string $eventId,
        public readonly string $customerId,
    ) {}
}
```

Aggregate-derived tags need **no** attribute: any event raised inside an `#[EventSourcingAggregate]` is
automatically tagged `aggregateType` and `aggregateId` by the same mechanism that populates
`MessageHeaders::EVENT_AGGREGATE_TYPE`/`EVENT_AGGREGATE_ID` today — a renaming of a working mechanism, not new
runtime work. `#[FromStream]` stays optional on aggregates, satisfying the wiki note.

```php
namespace Ecotone\EventSourcing\Api;

final class Query
{
    public static function forTags(array $tags): self;          // ['tagKey' => 'tagValue', ...] — one AND-ed criterion
    public function ofTypes(string ...$eventClass): self;       // narrows the last criterion by event type
    public function or(self $other): self;                      // OR another criterion group in
}

final class AppendCondition
{
    /** @param array<string,int> $capturedTagVersions ["tagKey:tagValue" => version] */
    public function __construct(
        public readonly Query $query,
        public readonly array $capturedTagVersions,
    ) {}
}

final class GlobalPosition
{
    public static function fromString(string $value): self;
    public static function start(): self;
    public function __toString(): string;
}

interface EventStore
{
    public function read(Query $query, ?GlobalPosition $from = null, int $limit = 1000): EventPage;

    /**
     * Current event_tag_versions value per tag (0 for a never-appended tag).
     * @param array<string,string> $tags
     * @return array<string,int> ["tagKey:tagValue" => version]
     */
    public function captureTagVersions(array $tags): array;

    /** @throws ConcurrencyException when any captured tag version no longer matches */
    public function append(array $events, ?AppendCondition $condition = null): GlobalPosition;
}

final class EventPage
{
    /** @param Event[] $events eagerly materialised, at most $limit long */
    public function __construct(
        public readonly array $events,
        public readonly GlobalPosition $lastPosition,
        public readonly bool $hasMore,
    ) {}
}
```

`AppendCondition` carries **captured per-tag versions**, not a `GlobalPosition` — a global position cannot drive a
per-tag CAS. `GlobalPosition` belongs to `read()`/`EventPage` (paging and gap tracking) only.
`append($events, null)` is an unconditional append, as in EventStoreDB and dcb.events.

`EventPage` replaces the lazy-iterable-plus-position shape, which could never be legally constructed (the last
position is unknowable until a lazy iterable is consumed). It mirrors
`Ecotone\Projecting\StreamPage` exactly, so Group B learns no new pagination concept. "Load everything for this
aggregate" is a loop over `read()`, like today's `LazyProophEventStore::LOAD_BATCH_SIZE = 1000`.

**Which tags get CAS'd is caller-chosen, never derived from the query.** For an aggregate load the query filters
on both `aggregateType` and `aggregateId` (needed to scope the *read*), but neither is safe to CAS —
`aggregateType` is shared by every instance (a hot row serializing unrelated aggregates), and `aggregateId` alone
is only unique *within* a type. The CAS target is a framework-internal compound tag
`_aggregateInstance: "{aggregateType}:{aggregateId}"`, invisible to application code exactly as
`_aggregate_version` metadata is today.

### Aggregate loading and saving

```php
public function findBy(string $aggregateClassName, array $identifiers): EventPage
{
    return $this->eventStore->read(Query::forTags([
        'aggregateType' => $this->getAggregateType($aggregateClassName),
        'aggregateId'   => (string) reset($identifiers),
    ]));
}

public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
{
    $aggregateType = $this->getAggregateType($aggregateClassName);
    $aggregateId   = (string) reset($identifiers);

    $this->eventStore->append($events, new AppendCondition(
        Query::forTags(['aggregateType' => $aggregateType, 'aggregateId' => $aggregateId]),
        ["_aggregateInstance:{$aggregateType}:{$aggregateId}" => $versionBeforeHandling],
    ));
}
```

No extra round trip for the common case: `$versionBeforeHandling` is already computed today by
`AggregateResolver::getVersionBeforeHandling()` (`AggregateResolver.php:201-224`) for an unrelated reason.
`EventStore::captureTagVersions()` exists for the general/multi-tag case (a future decision model) — one cheap
point-`SELECT`, race-free by construction, since a stale capture simply fails the guard.

### Extension object

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function eventSourcing(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults()
            ->withEventLogTableName('event_log')
            ->withSnapshotsFor(Ticket::class, thresholdTrigger: 100);
    }
}
```

The four `with*StreamPersistenceStrategy()` methods and `withCustomPersistenceStrategy()` (with its
`Prooph\EventStore\Pdo\PersistenceStrategy` type) are **removed**. There is no consistency-boundary concept left
to select via a strategy enum. Physical partitioning gets its own `withPhysicalPartitioning(...)` knob that does
not change query semantics.

### CLI

| Command | Purpose |
|---|---|
| `ecotone:migration:database:setup --feature=event_log` | Create `event_log`/`event_tags`/`event_tag_versions` (registers as `DbalTableManager`s with Group F2's existing `DatabaseSetupManager`) |
| `ecotone:event-store:migrate-aggregate-streams` | One-time migration from the Prooph layouts into the global log; resumable, idempotent, `--dry-run`, `--commit` |
| `ecotone:event-store:audit-legacy-metadata` | Dry-run diagnostic: how many aggregates lack `_aggregate_type`/`_aggregate_id`/`_aggregate_version` metadata, before committing to a migration |
| `ecotone:event-store:verify-gaps` | Engine-branching. MySQL/MariaDB: list positions still open past `gapTimeout`. **PostgreSQL: a stall diagnostic** — how far `pg_snapshot_xmin()` trails the maximum `global_position`, cross-referenced with `pg_stat_activity`. There are no literal gaps to report there |

## Internals

### Schema — PostgreSQL

```sql
CREATE TABLE event_log (
    global_position   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id          UUID NOT NULL UNIQUE,
    event_type        VARCHAR(255) NOT NULL,
    payload           JSONB NOT NULL,
    metadata          JSONB NOT NULL,
    recorded_at       TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    transaction_id    XID8 NOT NULL DEFAULT pg_current_xact_id()
);
CREATE INDEX ix_event_log_type ON event_log (event_type);
CREATE INDEX ix_event_log_txid ON event_log (transaction_id);

CREATE TABLE event_tags (
    global_position   BIGINT NOT NULL,
    tag_key           VARCHAR(100) NOT NULL,
    tag_value         VARCHAR(255) NOT NULL,
    tag_local_seq     BIGINT NOT NULL,
    PRIMARY KEY (tag_key, tag_value, global_position)
);
CREATE INDEX ix_event_tags_position ON event_tags (global_position);

CREATE TABLE event_tag_versions (
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_key, tag_value)
);
```

`GENERATED ALWAYS AS IDENTITY` (not `BIGSERIAL`) forbids explicit inserts into `global_position`, closing off a
class of bulk-loader bugs that would corrupt monotonicity. **No foreign key** from `event_tags` to `event_log`:
Postgres 12+ supports FKs to partitioned tables, but detaching a still-referenced partition is blocked outright,
defeating the archival use case partitioning exists for. Integrity is enforced by the single `append()` path that
writes both tables in one transaction and never updates or deletes them.

### Schema — MySQL 8 / MariaDB

Identical shape. `global_position BIGINT UNSIGNED AUTO_INCREMENT`, `event_id BINARY(16)` (packed uuid7, not
`CHAR(36)`), `payload`/`metadata` as `JSON`, and — **critically** — an explicit
`COLLATE=utf8mb4_0900_bin` on `event_tags` and `event_tag_versions`. MySQL 8's default
`utf8mb4_0900_ai_ci` is accent- and case-**insensitive**, which would silently equate `'Ticket-1'` and
`'ticket-1'` and merge two distinct aggregates' event streams. Prooph's existing table managers already use
`utf8_bin` for the same reason. There is no `transaction_id` column — the xmin technique has no equivalent here.

### Schema — SQLite (tests)

`INTEGER PRIMARY KEY` rowid alias, JSON1 for payload/metadata, the same two side tables. SQLite serialises
writers, so the gap problem does not exist there — which is exactly why gap-handling logic **cannot** be validated
by the in-memory/SQLite suite and needs a real Postgres/MySQL container test that injects concurrent and
rolled-back transactions.

### Query execution

Portable across all four engines — **no `INTERSECT`** (MySQL only gained it in 8.0.31):

```sql
SELECT e.global_position, e.event_id, e.event_type, e.payload, e.metadata, e.recorded_at
FROM event_log e
JOIN event_tags t ON t.global_position = e.global_position
WHERE (t.tag_key, t.tag_value) IN ((:k1, :v1), (:k2, :v2))
  AND e.global_position > :fromPosition
  AND e.event_type IN (:types)
GROUP BY e.global_position, e.event_id, e.event_type, e.payload, e.metadata, e.recorded_at
HAVING COUNT(DISTINCT t.tag_key) = :tagCount
ORDER BY e.global_position
LIMIT :limit
```

For the hot 1–2 tag case (every aggregate load) this is one or two tight index range scans on
`event_tags`'s primary key — the same complexity class as today's `UNIQUE(aggregate_id, aggregate_version)`
lookup, not a regression. On PostgreSQL the `transaction_id < pg_snapshot_xmin(...)` predicate is added for
projection reads (not for aggregate loads, which are point queries by tag).

### Write shape

One `append()` with K events and T distinct tags issues: the `event_log` inserts, **one** multi-row `INSERT` for
all `event_tags` rows, and **T** `event_tag_versions` UPSERTs (one per distinct tag across the whole batch, not
per event). Round trips are O(1) in the number of events, not O(events × tags).

### UUID v7

`SaveAggregateServiceTemplate` already mints `Uuid::v7()` (Symfony UID) for aggregate-originated events. Only the
store's own fallback (when no `MESSAGE_ID` is pre-supplied) needs to move from `ramsey/uuid`'s `uuid4()` to
`uuid7()`, bumping `ramsey/uuid` to `^4.7`. v7 gives time-ordered ids for index locality and debuggability;
`global_position` remains the sole authority for ordering — v7's millisecond precision is not a total order.

### `#[Version]` / `#[TargetVersion]`

Today, `AggregateResolver::getVersionBeforeHandling()` (`:201-224`) takes a user-supplied `#[TargetVersion]` value
if present, else the freshly-loaded `#[Version]` property; `SaveAggregateServiceTemplate::enrichAggregateEvents()`
(`:169-183`) then stamps `++$version` per event, and the physical unique constraint rejects collisions. The
version number **is** the concurrency token today.

An earlier draft proposed deriving the version by counting matching events. That is wrong: today's
`EventStream::getAggregateVersion()` is an O(1) read of `_aggregate_version` off the last loaded event, and it is
only correct because the load is never type-filtered and never starts after a snapshot boundary without knowing
that snapshot's version. Both of those assumptions break under a tag query.

**The `event_tag_versions` row for `_aggregateInstance` *is* the aggregate's version.** No new write, no new
table, no count. `save()` issues one UPSERT with `SET version = version + :K WHERE version = :versionBeforeHandling`
and assigns the K events `versionBeforeHandling + 1 … + K`, storing each in `event_tags.tag_local_seq`.
`#[Version]` and `#[TargetVersion]` keep their exact meaning from the outside; `#[TargetVersion]`'s stale value
plugs straight into the CAS guard. The per-aggregate write serialisation this creates is not new — today's
`UNIQUE(aggregate_type, aggregate_id, aggregate_version)` already imposes it; the design just makes the
serialisation point an explicit small row instead of an emergent property of a large index.

### Snapshots

- A snapshot records the aggregate's `#[Version]` value — unchanged.
- The post-snapshot load filters `event_tags.tag_local_seq > :snapshotVersion` for the aggregate's tag pair — an
  index range scan, not a count. Store-internal; not part of the public DCB query language (a multi-tag decision
  model has no single well-defined version to filter on).
- The "every N events" threshold keeps its exact meaning: `EventSourcedRepositoryAdapter::save()` snapshots when
  `$version % $threshold === 0`, and `$version` is still `EVENT_AGGREGATE_VERSION`.
- **Existing snapshots survive the migration** — `_aggregate_version` metadata is already numerically what
  `tag_local_seq` needs to be — **provided** the migration tool backfills `event_tag_versions`/`tag_local_seq`
  from it before any post-migration append. Exception: `simple`/`custom`-strategy stores never populated that
  metadata; those aggregates' snapshots must be treated as invalid.
- Snapshotting stays scoped to single-aggregate loads in 2.0. A decision model's boundary is chosen per command,
  so there is no stable cache key.

### Lock-hold duration — read from source, stated honestly

`SaveAggregateService::process()` (`:34-68`) runs two loops: first `save()` for every resolved aggregate (`:44`) —
where `append()` and its CAS row lock happen — and **only then** `publishEvents()` (`:58-60`, `:73-78`), which
synchronously invokes every non-`#[Asynchronous]` `#[EventHandler]`. `DbalTransactionInterceptor` (`:41-107`)
begins the transaction, runs `proceed()` (`:96`) over the **whole** chain, and commits only afterwards (`:99`).

So `append()` is near the *start* of the transaction, and its row lock is held through every synchronous handler
the message triggers. Is that worse than today? Nuanced: both mechanisms hold locks until the same commit, but
today's INSERT locks a **brand-new** index entry, so two sequential non-racing saves for the same aggregate never
contend on the same physical row. The CAS touches the **same** row on every save for that aggregate, forever — so
two ordinary, closely-timed messages for the same order can serialise, and a slow synchronous projection triggered
by message 1 now delays message 2's `append()` from even succeeding.

Once Group F2 removes implicit commit and one-transaction-per-message becomes unconditional, nothing in the store
design can shorten that window. The mitigation is architectural and belongs in the docs: keep synchronous
`#[EventHandler]` chains short, or move reactive work behind `#[Asynchronous]` channels, which commit at the save
rather than after a cascade.

### ProjectionV2 stream sources — the Group B seam

`Ecotone\Projecting\StreamSource` (`canHandle` / `load(name, lastPosition, count, partitionKey): StreamPage`)
needs **no signature change**. `partitionKey` stays an opaque string; only its meaning changes.

| Today | New store |
|---|---|
| `EventStoreGlobalStreamSource` reads raw SQL against a Prooph table via `PdoStreamTableNameProvider` | `EventStore::read(Query::forTags([])->ofTypes(...), from: GlobalPosition::fromString($lastPosition))`. `loadFromMultipleStreams()`'s merge-by-timestamp machinery disappears entirely |
| `GapAwarePosition` | Postgres: collapses to a bare integer. MySQL/MariaDB: retained. The two engines write mutually unreadable encodings into the same `last_position TEXT` column |
| `EventStoreAggregateStreamSource` partition key `"streamName:aggregateType:aggregateId"` | `"aggregateType:aggregateId"`; loads via a tag query |
| `StreamFilter{streamName, aggregateType, eventStoreReferenceName, eventNames}` | `StreamFilter{tags, eventStoreReferenceName, eventNames}` — `streamName` removed |
| `#[FromStream(stream: 'x')]` | A new `#[FromTags([...])]` for cross-aggregate projections; `#[FromStream]` kept only as a thin shim mapping to `['stream' => $name]` for migrated data |
| `AggregateIdPartitionProvider`'s per-platform `metadata->>'_aggregate_id'` SQL | `SELECT DISTINCT tag_value FROM event_tags WHERE tag_key = 'aggregateId'` — portable, no platform branch |

**Existing `ecotone_projection_state.last_position` values are not uniformly safe to reuse** — this is a hard
Group B coordination point:

| Prior strategy | Position handling |
|---|---|
| `single` / `partition` | The Prooph `no` column *is* the sequence `global_position` continues from (same physical table, reinterpreted). Mechanically translatable: parse the leading integer, discard the old gap list, re-encode per the target engine. No replay. |
| `aggregate` | Old `no` values are local to per-id tables with no relation to the new shared sequence. **Force-reset and rebuild.** |
| `simple` / `custom` | Same as `aggregate` — these strategies do not guarantee the aggregate metadata the migration relies on. **Force-reset and rebuild.** |

The migration tool therefore carries two code paths and must run the reset **before** the first post-migration
append.

### DCB decision models — follow-up scope

```php
final class TicketSaleDecision
{
    #[CommandHandler]
    public function reserve(ReserveTicket $command, EventStore $eventStore): array
    {
        $query = Query::forTags(['ticket' => $command->ticketId])
            ->or(Query::forTags(['customer' => $command->customerId])->ofTypes(CustomerBlocked::class));

        $captured = $eventStore->captureTagVersions(['ticket' => $command->ticketId]);
        $state    = TicketSaleState::foldFrom($eventStore->read($query)->events);

        if ($state->isReserved()) {
            throw new TicketNotAvailable();
        }

        $eventStore->append(
            [new TicketReserved($command->ticketId, $command->customerId)],
            new AppendCondition($query, $captured),
        );

        return [];
    }
}
```

**Recommendation: out of 2.0 scope.** Group D's own scope (new DDL, CAS mechanism, gap detection, Prooph removal,
migration tooling) is already substantial, and single-aggregate handlers get the new store's safety for free
without this layer. `Query`/`AppendCondition`/`EventStore` are public and stable enough that the layer is purely
additive later.

## Edge cases

| Case | Behaviour |
|---|---|
| Concurrent appends with disjoint captured tags | No conflict — the CAS touches only the tags each caller captured |
| Concurrent appends protecting the same tag at the same captured version | The second UPSERT affects 0 rows → `ConcurrencyException`, retried by `InstantRetryConfiguration` |
| `ofTypes()`-filtered condition on a tag also touched by other event types | Conservative over-approximation — may raise a spurious `ConcurrencyException`, never misses a real conflict. Invisible in 2.0's shipped surface; relevant only to decision models |
| Reader polls mid-transaction | Postgres: the xmin filter withholds anything not provably contiguous. MySQL/MariaDB: bounded timeout, can rarely skip an event held open past the window — logged loudly |
| Transaction rolls back after allocating a position | Postgres: closed the instant xmin clears the writer, no re-check step. MySQL/MariaDB: closed after `gapTimeout` |
| **A single long-open transaction anywhere in the Postgres database** (idle-in-transaction session, `pg_dump`, a stuck lock) — even one that never touches `event_log` | Stalls delivery for **every** projection on that store, database-wide. `pg_snapshot_xmin()` cannot distinguish writers from any other open transaction. `idle_in_transaction_session_timeout` and `statement_timeout` are **required operational settings** for this design, not optional tuning |
| Slow synchronous handler chain after a save | Extends the `_aggregateInstance` row lock past `append()` until the whole message transaction commits. Mitigate with short synchronous chains or `#[Asynchronous]` channels |
| Non-string / composite tag values | Cast via `(string)` as aggregate ids already are. A `#[Tag]` property whose type is neither scalar nor `Stringable` fails fast at bootstrap with a `ConfigurationException`. `null` is rejected at append; empty string is permitted but discouraged |
| Tag collation | MySQL/MariaDB DDL specifies `utf8mb4_0900_bin` explicitly; the default `utf8mb4_0900_ai_ci` would silently merge case/accent-variant tag values. Postgres is byte-exact by default |
| Tag extraction timing | `#[Tag]` reflection runs on the live PHP object, alongside `SaveAggregateServiceTemplate::enrichAggregateEvents()`, before `ConversionService::convert()`. No upcasting exists today; if it ships, it must not retroactively change stored tags |
| DB version floors | Postgres **13+** (`xid8`, `pg_current_snapshot`). MySQL **8.0+**, MariaDB **10.6+**. CI runs Postgres 16.1 and MySQL 8.0 — comfortably above — but **no CI job tests MariaDB at all** (only `docker-compose.yml` does). No floor is documented anywhere today |
| `event_tags` ↔ `event_log` foreign key + partitioning | Deliberately no FK — detaching a referenced partition is blocked, defeating archival. Enforced by the single `append()` path instead |
| `event_tag_versions` growth | One row per distinct tag *value* ever appended; for `aggregateId`, one per aggregate instance, never deleted. ~50–100 bytes each, so 100M instances ≈ single-digit GB — far smaller than `event_log`/`event_tags`. No pruning in 2.0; accepted, not overlooked |
| Multi-tenancy | Tag tables are per tenant connection. `global_position` is totally ordered only **within** one tenant's tables; cross-tenant comparison is never meaningful. Must be documented — a naive reader will assume one global order |
| Multiple DB connections | Each `EventStore` binds to one `DbalConnectionReference`, as today. `global_position`/`AppendCondition` are meaningless across two stores; a decision model cannot span them atomically. Same limitation as today |
| Very large logs | Aggregate loads stay tight index probes on `event_tags`'s PK regardless of size. `event_log` is the candidate for range partitioning by `global_position` (§Internals) for archival and vacuum cost |
| Replay & rebuild | Unaffected at the `StreamSource`/`ProjectionStateStorage` seam — `prepareRebuild()`/`executePartitionBatch(shouldReset: true)` already reset `lastPosition`; only the per-page SQL changes |
| Failure mid-migration | The tool tracks migrated-up-to per source table and uses `INSERT … ON CONFLICT (event_id) DO NOTHING` / `INSERT IGNORE` keyed on the original event uuid, so re-running after a crash never duplicates. Source tables are never dropped before an explicit `--commit` |
| Transactional boundaries | `append()` runs in the same DBAL transaction as the rest of the unit of work, so a handler exception rolls back events and other writes together. Verify against `DbalTransactionInterceptor` once Group F2 lands |
| Tests: in-memory vs real DB | The in-memory store must implement the **same** `Query`/`AppendCondition`/`GlobalPosition` contract, so `bootstrapFlowTesting()` exercises real conditional-append semantics. Gap handling is untestable in-memory and needs a dedicated container suite |
| Existing snapshots after migration | Survive, if the tool backfills `event_tag_versions`/`tag_local_seq` from `_aggregate_version` before any post-migration append. `simple`/`custom`-strategy aggregates lacking that metadata: snapshots invalid, full replay |
| Existing projection positions after migration | `single`/`partition`: mechanically translatable. `aggregate`/`simple`/`custom`: force-reset + rebuild |
| `ecotone:event-store:verify-gaps` on Postgres | Reports a **stall diagnostic**, not a gap list — gaps no longer exist there. Document the behaviour change so operators do not expect the old output |
| Licence gating | The store (global log, tags, conditional append) is **core**, Apache-2.0. Today's docblocks are inconsistent: `EventStreamTableManager.php:17` says `licence Enterprise` while `EventSourcingRepository.php:18` says `Apache-2.0`. Resolve deliberately, do not carry forward |

## Migration / upgrade notes

> ### Event Store: single global event log, DCB-ready, no Prooph
>
> Ecotone 2.0 replaces `prooph/pdo-event-store` with its own DBAL event store. Events are no longer written into
> per-stream tables; there is **one global event log**, and consistency boundaries are expressed as **tag
> queries** rather than as physical streams.
>
> **New tables:** `event_log`, `event_tags`, `event_tag_versions`. Create them with
> `bin/console ecotone:migration:database:setup --initialize` (see §8) — Ecotone no longer creates tables on the
> fly.
>
> **Configuration**
>
> ```diff
>  #[ServiceContext]
>  public function eventSourcing(): EventSourcingConfiguration
>  {
>      return EventSourcingConfiguration::createWithDefaults()
> -        ->withPartitionStreamPersistenceStrategy()
> -        ->withSingleStreamPersistenceStrategy()
> -        ->withCustomPersistenceStrategy($strategy)
> +        ->withEventLogTableName('event_log')
>          ->withSnapshotsFor(Ticket::class, thresholdTrigger: 100);
>  }
> ```
>
> `withSingleStreamPersistenceStrategy()`, `withPartitionStreamPersistenceStrategy()`,
> `withAggregateStreamPersistenceStrategy()`, `withSimpleStreamPersistenceStrategy()` and
> `withCustomPersistenceStrategy()` are **removed** — there is only one layout. (Note: the old
> `withPartitionStreamPersistenceStrategy()` actually set the *single*-stream strategy, a long-standing bug.)
> `MetadataMatcher`, `FieldType` and `Operator` are replaced by `Query`.
>
> **Tagging your events**
>
> Aggregate events need no change — `aggregateType` and `aggregateId` tags are derived automatically, exactly as
> `_aggregate_type`/`_aggregate_id` metadata is today. Add `#[Tag]` only for cross-aggregate domain tags:
>
> ```php
> use Ecotone\EventSourcing\Api\Attribute\Tag;
>
> #[Tag('context', 'ticketing')]
> final class TicketReserved
> {
>     public function __construct(
>         #[Tag('ticket')] public readonly string $ticketId,
>         public readonly string $customerId,
>     ) {}
> }
> ```
>
> **Optimistic locking is unchanged from the outside.** `#[Version]` and `#[TargetVersion]` keep their meaning;
> a conflict still throws `Ecotone\Messaging\Support\ConcurrencyException`. Internally the version is now a row in
> `event_tag_versions` rather than a unique index on the event table.
>
> **Migrating existing data**
>
> ```bash
> bin/console ecotone:event-store:audit-legacy-metadata          # what will and won't migrate cleanly
> bin/console ecotone:event-store:migrate-aggregate-streams --dry-run
> bin/console ecotone:event-store:migrate-aggregate-streams --commit
> ```
>
> The migration is resumable and idempotent, and never drops source tables before `--commit`. It backfills tags
> from your existing `_aggregate_type`/`_aggregate_id` metadata and version counters from `_aggregate_version`.
>
> - If you used the **single** or **partition** strategy, existing snapshots and projection positions are carried
>   over; projections do not need rebuilding.
> - If you used the **aggregate**, **simple** or **custom** strategy, every projection position is reset and
>   every projection must be rebuilt (`ecotone:projection:rebuild <name>`, see §3). Snapshots for aggregates whose
>   events lack `_aggregate_version` metadata are discarded and replayed.
>
> **PostgreSQL: two required operational settings.** Projection delivery now waits until an event's writing
> transaction is provably complete, which makes gap handling exact instead of timeout-based — but it means **any**
> long-open transaction in the database (an idle-in-transaction session, a long `pg_dump`) stalls projection
> delivery for as long as it is open. Set `idle_in_transaction_session_timeout` and `statement_timeout`.
>
> **MySQL / MariaDB: gap detection remains timeout-based.** Neither engine exposes PostgreSQL's transaction
> visibility primitives, so a transaction held open longer than the configured window can, rarely, cause a
> projection to skip an event. Ecotone logs loudly when a gap is closed by timeout rather than by proof.
> PostgreSQL is the recommended engine for event sourcing in 2.0.
>
> **Minimum database versions:** PostgreSQL 13+, MySQL 8.0+, MariaDB 10.6+.
>
> **Write-concurrency note.** A message's whole handler chain — including every synchronous `#[EventHandler]` it
> triggers — runs inside one transaction, and the aggregate's concurrency row is locked from the moment its events
> are saved. If you have aggregates with high write concurrency and slow synchronous event handlers, move that
> reactive work behind `#[Asynchronous]` channels.
>
> **Package rename:** `ecotone/pdo-event-sourcing` → `ecotone/event-sourcing`.

## Implementation plan

Each task is a single focused session; later tasks depend on earlier ones being merged.

1. **Value objects** — `Query`, `TagCriteria`, `AppendCondition`, `GlobalPosition`, `EventPage`, and the
   `Ecotone\EventSourcing\Api\EventStore` interface. Pure PHP, unit-testable.
2. **`#[Tag]` attribute + tag resolution** — read `#[Tag]` off event classes via `ClassDefinition`/reflection at
   the same pipeline point as `SaveAggregateServiceTemplate::enrichAggregateEvents()`; derive `aggregateType`,
   `aggregateId` and the internal `_aggregateInstance` tag from the existing mapping resolution.
3. **In-memory `EventStore`** against the new interface — needed early so every later task's `EcotoneLite` tests
   run without a DB. Replaces `EventStore\InMemoryEventStore`.
4. **`event_tag_versions` CAS primitive**, standalone and per engine (Postgres/SQLite `ON CONFLICT … WHERE`,
   MySQL/MariaDB `ON DUPLICATE KEY UPDATE` with the exact affected-rows contract), **including deterministic lock
   ordering** (sort captured tag keys before issuing UPSERTs) and a multi-tag deadlock test. Both `AppendCondition`
   and `#[Version]` depend on it, so build and test it once, first.
5. **PostgreSQL store** — DDL + `DbalTableManager` implementations for all three tables (following the existing
   pattern) + `read()` and unconditional `append()`.
6. **PostgreSQL conditional append** — wire task 4 into `append()`; integration tests with two real overlapping
   transactions asserting the loser's `ConcurrencyException`, plus an explicit test for the type-filter
   over-approximation.
7. **PostgreSQL gap detection** — the `pg_snapshot_xmin` filtered read; tests that a slow transaction around a
   fast one skips nothing, and that an idle-in-transaction session on an unrelated table stalls delivery as
   documented.
8. **MySQL + MariaDB store** — DDL with explicit `utf8mb4_0900_bin`, the portable query, task 4's CAS, and
   bounded-timeout gap detection retaining `GapAwarePosition`, with a loud log line on timeout-closed gaps.
9. **UUID v7** — change the store's fallback id generation; bump `ramsey/uuid` to `^4.7`.
10. **`EventSourcingRepository` / `EventSourcedRepositoryAdapter` rewire** — tag-query load, `AppendCondition`
    save, `EVENT_AGGREGATE_VERSION` from the CAS return value, `tag_local_seq`-filtered post-snapshot load.
11. **`EventSourcingConfiguration` rewrite** — drop the strategy surface, add `withPhysicalPartitioning()`.
12. **ProjectionV2 stream sources** — rewrite `EventStoreGlobalStreamSource`, `EventStoreAggregateStreamSource`
    and `AggregateIdPartitionProvider`; change `StreamFilter`'s `streamName` → `tags`. **Land with or adjacent to
    Group B's rename PR.**
13. **CLI** — register the three table managers with `DatabaseSetupManager`; add
    `ecotone:event-store:migrate-aggregate-streams`, `…:audit-legacy-metadata`, and the engine-branching
    `…:verify-gaps`.
14. **Migration tool** — resumable, idempotent, `--dry-run`/`--commit`, plus: (a) tag backfill from
    `_aggregate_type`/`_aggregate_id` with explicit handling for events lacking it; (b)
    `event_tag_versions`/`tag_local_seq` backfill from `_aggregate_version`, non-skippable, **plus a defensive
    runtime check** — `append()` refuses a first write for an `_aggregateInstance` tag that has `event_tags`
    history but no `event_tag_versions` row, raising a named `ConfigurationException` rather than silently
    mis-numbering; (c) projection-position translation for `single`/`partition` and force-reset for the rest
    (**coordinate with Group B** — it touches their state table and rebuild command).
15. **Delete Prooph** — remove `prooph/pdo-event-store` from composer, delete everything under `Prooph/`, delete
    `EventStore\{MetadataMatcher,FieldType,Operator}`. **Last**, so a working non-Prooph path exists before the
    old one is removed.
16. **Package rename** — `ecotone/pdo-event-sourcing` → `ecotone/event-sourcing`, including quickstart path-repo
    entries.

## Open questions for the maintainer

1. **Confirm the uniform normalised layout.** This flipped twice during review: side table → JSONB+GIN hybrid →
   back to a uniform side table, once it became clear `tag_local_seq` forces the side table onto Postgres anyway.
   The recommendation is firm; flag only if you disagree that `event_tag_versions` is worth having at all — in
   which case the whole CAS mechanism needs revisiting, not just the DDL.
2. **MySQL/MariaDB gap timeout: what default, and should Ecotone warn at bootstrap** when event sourcing is
   configured on those engines? Recommendation: warn (not fail), pointing at the docs section explaining the
   asymmetry.
3. **Is the over-approximation in conditional append acceptable?** Type-filtered conditions can raise spurious
   `ConcurrencyException`s. Recommendation: yes for 2.0 — invisible in the shipped surface, and the per-type
   alternative is worse. Revisit when decision models ship.
4. **`#[Tag]` attribute-only, or also a `HasTags` interface escape hatch?** Recommendation: attribute-only in 2.0;
   add the interface later if a real computed-tag use case appears.
5. **DCB decision models confirmed as follow-up?** Recommendation: yes — but flag it to whoever scopes the next
   release; it is the most-requested DCB capability in the literature.
6. **Licence line.** `EventStreamTableManager.php:17` says `licence Enterprise`, `EventSourcingRepository.php:18`
   says `Apache-2.0`. Recommendation: the event store is core/Apache-2.0. This also decides whether the new
   `#[FromTags]` inherits `#[FromStream]`'s `Enterprise` docblock — see the Group B spec, which recommends free.
7. **MariaDB CI.** No workflow tests MariaDB at all; only `docker-compose.yml` does. Add a MariaDB job in Group
   D's PR, or ship MariaDB labelled best-effort until that gap is closed separately?
8. **Package rename: hard break or a transitional composer `replace`?** Recommendation: hard break, consistent
   with 2.0's stated posture — but composer renames hurt automated tooling (Dependabot, lockfiles) more than
   namespace renames, so confirm.
9. **Expose `global_position` to user code** (a `#[Header]`-injectable, store-assigned value) the way
   `EVENT_AGGREGATE_VERSION` is exposed today? Recommendation: yes, read-only, for audit and debuggability — but
   it is added public surface.
10. **Two UUID libraries.** `ramsey/uuid` (store fallback) and `symfony/uid` (already used on the hot
    aggregate-save path). Consolidate on `symfony/uid` for anything this rewrite touches, or accept both?
11. **`Ecotone\EventSourcing\Api\*` placement timing.** Group H is scoped "last, touches everything" and may land
    after Group D. Ship these value objects under bare `Ecotone\EventSourcing\*` and move them in the Group H
    pass, or place them in `Api` from day one? Recommendation: `Api` from day one — they are new classes, so
    there is no BC cost, and moving them later would be a second break.
12. **`upgrade-2.0.md:223` says `ecotone:database:setup`; the actual command is
    `ecotone:migration:database:setup`.** Is Group F2 renaming it, or is the guide simply wrong? The new
    event-store commands should match whatever the final convention is. (Also raised in the Group F2 spec.)
