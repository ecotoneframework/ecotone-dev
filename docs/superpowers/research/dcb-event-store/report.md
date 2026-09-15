# DCB Event Store — Design Research (Release Group D)

Written by a research worker for Ecotone 2.0 planning (Release Group D). All code references were verified against
this checkout (branch `dgafka/ecotone-2-0-work`) with `rg`/`Read` at the time of writing; all web sources in §3 were
fetched directly (`WebSearch`/`WebFetch`) rather than recalled from training data. Anything not independently
verified is flagged explicitly in place rather than asserted.

**Revision 2 (challenge round 1).** The most significant change: §6.1.5 replaces a "re-check at commit time"
hand-wave with a concrete, cited, per-engine atomic-append mechanism (a Marten-style `event_tag_versions`
UPSERT-with-guard, working at plain `READ COMMITTED`/`REPEATABLE READ` on every engine, chosen over
`SERIALIZABLE`+retry and advisory locks specifically because it composes with Ecotone's one-shared-transaction-
per-message architecture). §6.5's gap-detection SQL had its filter direction inverted — fixed, with the
consequences (Postgres-only lag cost, `GapAwarePosition` collapsing to a bare integer on Postgres but not
MySQL/MariaDB) followed through into §6.7's projection-position handling. §6.9 (new) designs
`#[Version]`/`#[TargetVersion]` properly instead of hand-waving it away — the same `event_tag_versions` mechanism
turns out to be the answer. §6.10 (new) designs snapshots concretely, including a materially better-than-expected
answer on existing-snapshot validity after migration. §6.1 is revised from a uniform normalised-tag-table design to
a hybrid (JSONB+GIN on Postgres, side table on MySQL/MariaDB) after reading a real DCB reference implementation's
actual source rather than only its interface package (§3.4 Addendum 2) — which also closes what was previously an
open spike question (§10). Collation, `INTERSECT` portability, the `EventStream`/`StreamPage` contract, the
`event_tags`↔`event_log` foreign key vs. partitioning conflict, and the Prooph→new-store migration's handling of
existing projection positions were each real gaps, not nitpicks, and are fixed in place — search for "C1" through
"C9" for the specific fixes. Sections not touched by these findings (§1-§3 aside from the two addenda, most of
§7-§9 structurally) are carried over from the first draft.

**Revision 3 (challenge round 2, final).** Fixes six internal contradictions round 1's own changes introduced.
Most significant: `AppendCondition` (§5.2) carried a `GlobalPosition` even though §6.1.5/§6.9's mechanism enforces
per-tag captured versions, not a store-wide position — it now carries `array $capturedTagVersions`, with an
explicit rule for which tags get captured (never the shared `aggregateType` tag; not bare `aggregateId` either,
which turns out to collide across aggregate types — fixed with a new internal `_aggregateInstance` compound tag,
found while tightening this, not asked for directly). §6.1's round-1 hybrid layout (JSONB+GIN on Postgres, side
table on MySQL/MariaDB) is reversed back to a uniform side table everywhere once it became clear Postgres needs
the side table's `tag_local_seq` column regardless, for versioning — JSONB would have been pure added cost, not a
simplification; §4's comparison table and every DDL/query section are brought back into consistency with this.
§6.1.5 now states explicitly that the CAS mechanism is a conservative over-approximation of dcb.events' exact
type∧tag conflict semantics (never misses a real conflict, can raise a spurious one) and adds the deterministic
lock-ordering the chosen mechanism needs but round 1 only specified for a rejected alternative. A new §6.11 reads
`SaveAggregateService`/`DbalTransactionInterceptor` source directly to state honestly how long the CAS row lock is
actually held inside Ecotone's one-transaction-per-message model (through any synchronous event-handler cascade
after the save, not just the append itself). The migration tool's tag-version backfill, previously described as a
soft ordering requirement, is now a numbered, enforced sequence with a stated failure mode and a defensive runtime
check. Database version floors move from an open question to a stated recommendation, with the gap that MariaDB
is untested in this repo's CI made explicit. Search "D1" through "D6" for the specific fixes.

## 1. Problem

Ecotone's event sourcing package (`ecotone/pdo-event-sourcing`, PSR-4 `Ecotone\EventSourcing\*`, living at
`packages/PdoEventSourcing/src`) is a thin wrapper around `prooph/pdo-event-store`. This creates several concrete
problems, each verified against the current checkout at `packages/PdoEventSourcing/src` and `packages/Ecotone/src`:

### 1.1 Stream-centric model, no DCB concepts exist

`Ecotone\EventSourcing\EventStore` (`packages/Ecotone/src/EventSourcing/EventStore.php:11-40`) is:

```php
interface EventStore
{
    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void;
    public function appendTo(string $streamName, array $streamEvents): void;
    public function delete(string $streamName): void;
    public function hasStream(string $streamName): bool;
    public function load(string $streamName, int $fromNumber = 1, ?int $count = null, ?MetadataMatcher $metadataMatcher = null, bool $deserialize = true): iterable;
}
```

There is no concept of "tags", no query object independent of a stream name, no `AppendCondition`, no global
position type. Every operation is scoped to a `streamName` string. `Ecotone\EventSourcing\EventStore\MetadataMatcher`
(`packages/Ecotone/src/EventSourcing/EventStore/MetadataMatcher.php`) with `FieldType` (`METADATA`/`MESSAGE_PROPERTY`)
and `Operator` (`EQUALS`, `GREATER_THAN[_EQUALS]`, `LOWER_THAN[_EQUALS]`, `IN`, `NOT_IN`, `NOT_EQUALS`, `REGEX`) is a
generic "match some metadata fields" DSL, not a tag/type query — it is used today to filter by `_aggregate_type` /
`_aggregate_id` / `_aggregate_version`, i.e. it re-derives aggregate concepts through a generic filter API rather
than exposing tags as first-class data.

### 1.2 Four persistence strategies, one is buggy, none give a true global log by default

`Ecotone\EventSourcing\EventSourcingConfiguration` (`packages/PdoEventSourcing/src/EventSourcingConfiguration.php`)
exposes:

- `withSingleStreamPersistenceStrategy()` (line 74-79, `@deprecated Ecotone 2.0`) → sets `LazyProophEventStore::SINGLE_STREAM_PERSISTENCE`.
- `withPartitionStreamPersistenceStrategy()` (line 81-86) → **also** sets `LazyProophEventStore::SINGLE_STREAM_PERSISTENCE`.
  This is the bug called out in the task: the "partition" method is dead code / a copy-paste bug — it does not set
  `PARTITION_STREAM_PERSISTENCE` (`LazyProophEventStore.php:70`), it silently reuses the deprecated single-stream
  constant. Verified by direct read; both methods currently produce identical behaviour.
- `withStreamPerAggregatePersistenceStrategy()` (line 92-97) → `AGGREGATE_STREAM_PERSISTENCE`: one physical DB table
  *per aggregate instance* (`LazyProophEventStore.php:279,291,303` map this to Prooph's `MySqlAggregateStreamStrategy`
  / `MariaDbAggregateStreamStrategy` / `PostgresAggregateStreamStrategy`, and `EventSourcingRepository::getStreamName()`
  (`packages/PdoEventSourcing/src/EventSourcingRepository.php:96-98`) appends `-{$aggregateId}` to the stream name
  when this strategy is active). This does not scale operationally (unbounded table count) and cannot express
  "one transaction touches two aggregates" (DCB's core promise).
- `withSimpleStreamPersistenceStrategy()` (line 102-107) → `SIMPLE_STREAM_PERSISTENCE`, which does not enforce
  `_aggregate_id`/`_aggregate_version`/`_aggregate_type` metadata presence at all.
- `withCustomPersistenceStrategy(PersistenceStrategy $persistenceStrategy)` (line 124-130) accepts a raw
  `Prooph\EventStore\Pdo\PersistenceStrategy` instance — a direct Prooph type leak into Ecotone's public
  configuration API.

None of the four is "one global, tag-queryable log" by construction; `SINGLE`/`PARTITION` come closest (one physical
table for many streams — `MySqlSingleStreamStrategy`/`PostgresSingleStreamStrategy` in Prooph) but querying is still
by `streamName` + `MetadataMatcher`, not by tag set.

### 1.3 Optimistic concurrency via `_aggregate_version` metadata, not a query+position condition

`LazyProophEventStore::AGGREGATE_VERSION = '_aggregate_version'` (line 75) is a **metadata field**, not a store-level
concept. Concurrency is enforced by Prooph's persistence strategy generating a `UNIQUE(aggregate_id, aggregate_version)`
constraint on the physical table and catching the resulting unique-violation as `Prooph\EventStore\Exception\ConcurrencyException`.
`LazyProophEventStore::create()` (line 155-162) and `appendTo()` (line 164-175) both catch
`Prooph\EventStore\Exception\ConcurrencyException` and rethrow `Ecotone\Messaging\Support\ConcurrencyException`.
This means:
- concurrency detection is a side effect of a DB unique constraint on a single aggregate's version column, not an
  explicit `AppendCondition(query, lastKnownPosition)` a caller states up front;
- it is inherently single-aggregate — there is no way to express "these two aggregates' event sets must not have
  changed since I read them" (a DCB decision-model precondition spanning multiple tags).

### 1.4 UUID v4 event ids

Every event id minted by the write path is `Ramsey\Uuid\Uuid::uuid4()`:
- `packages/PdoEventSourcing/src/Prooph/EcotoneEventStoreProophWrapper.php:76` (the "real" DB-backed path — used
  whenever the caller doesn't already supply `MessageHeaders::MESSAGE_ID` in event metadata).
- `packages/PdoEventSourcing/src/Prooph/ProophInMemoryEventStoreAdapter.php:211` (the in-memory/testing path).

uuid4 is unordered random data: it cannot be used as a secondary sort key, defeats time-locality of B-tree/clustered
primary keys, and causes worse index write amplification than a time-ordered id at high write volumes. Ecotone
2.0's global log needs event ids that are monotonic-ish across the fleet without a central sequence allocator,
which uuid7 gives for free (§6.3).

### 1.5 Gap detection is Prooph's fragile time-window retry, not a real solution

`Ecotone\EventSourcing\Prooph\GapDetection` (`packages/PdoEventSourcing/src/Prooph/GapDetection.php`) is a thin
wrapper that constructs `Prooph\EventStore\Pdo\Projection\GapDetection` from a `retryConfig` array and a
`Ecotone\EventSourcing\Prooph\GapDetection\DateInterval` value object. Upstream Prooph's gap detection works by:
re-querying the same `no` range after a configurable sleep/retry schedule, and giving up (treating the gap as
permanent) once a configured time window has elapsed since the gap's expected position was first observed. This is
exactly the "fragile" mechanism GitHub issue #438 (cited in the release-design spec) complains about: it is
wall-clock based (sensitive to slow transactions, clock skew, GC pauses), it has no way to *know* a gap is closed
(it can only time out), and a projection can silently skip an event if a producing transaction takes longer than
the configured window. Ecotone 2.0's own `GapAwarePosition` for the *v2 projection* stream source
(`packages/PdoEventSourcing/src/Projecting/StreamSource/GapAwarePosition.php`, used by
`EventStoreGlobalStreamSource`, see §1.6) already reimplements a similar time-window heuristic
(`cleanGapsByTimeout()` in `EventStoreGlobalStreamSource.php:196-239`) independently of Prooph — i.e. Ecotone has
*two* independent, both wall-clock-based gap-closing heuristics today. The new event store should replace both with
one first-class mechanism (§6.5, §4).

### 1.6 The v2 projection stream sources already assume a global, gappy `no` sequence — but there's no store underneath that guarantees it

`Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource`
(`packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreGlobalStreamSource.php`) reads directly against
the *physical* Prooph stream table with hand-rolled SQL (`SELECT no, event_name, payload, metadata, created_at
FROM {$proophStreamTable} WHERE no > :position ... ORDER BY no LIMIT {$count}`, line 89-98) — it bypasses the
`EventStore`/`MetadataMatcher` abstraction entirely and talks straight to the `event_streams`-style table via
`PdoStreamTableNameProvider::generateTableNameForStream()`. It already carries a `GapAwarePosition` (position +
list of not-yet-filled gap numbers, serialized as `"<position>:<gap1>,<gap2>,..."`) and a `maxGapOffset`/`gapTimeout`
cutoff. This is real, working evidence that Ecotone's *design intent* is already a global sequence with gap
tracking — the new event store just needs to be the thing that actually *produces* that sequence correctly
(monotonic allocation + a reliable "safe to read up to here" position), instead of a stream source
reverse-engineering gaps from a Prooph table it wasn't designed to expose that way.

### 1.7 Aggregate loading duplicates tag-query logic ad hoc, using stream name and 3 metadata matches

`Ecotone\EventSourcing\EventSourcingRepository::findBy()` (`packages/PdoEventSourcing/src/EventSourcingRepository.php:39-77`)
builds a `MetadataMatcher` with `_aggregate_type = X AND _aggregate_id = Y AND _aggregate_version >= fromVersion`
and calls `$this->eventStore->load($streamName, 1, null, $metadataMatcher)` — i.e. even the *simplest* possible DCB
tag query (`tags: {aggregateType: X, aggregateId: Y}`) is expressed as a stream name plus a 3-clause metadata filter,
tied to the physical persistence strategy in play (`getStreamName()` appends `-{id}` only under the aggregate-stream
strategy, line 89-101). **There is no `LoadEventSourcingAggregateService` class in the codebase** — the task
brief's name for this concept does not exist verbatim; the equivalent real class is
`Ecotone\EventSourcing\EventSourcedRepositoryAdapter` (`packages/Ecotone/src/EventSourcing/EventSourcedRepositoryAdapter.php`,
implements `Ecotone\Modelling\Repository\AggregateRepository`), which delegates to the `EventSourcedRepository`
interface (`packages/Ecotone/src/Modelling/EventSourcedRepository.php`) that `EventSourcingRepository` implements.
This is called out explicitly because the task brief names an API that had to be verified rather than assumed —
`rg -l "LoadEventSourcingAggregateService" packages` returns no matches anywhere in the monorepo.

### 1.8 Deep coupling to Prooph types across 20+ files

`rg -l 'Prooph\\\\' packages/PdoEventSourcing/src` matches every file under `Prooph/`, plus `EventSourcingConfiguration.php`,
`EventSourcingRepository.php`, `EventStreamEmitter.php`, `ProjectionManager.php`, `ProjectionRunningConfiguration.php`,
`ProjectionSetupConfiguration.php`, `Config/EventSourcingModule.php`, `Config/EventStoreBuilder.php`,
`Config/ProophProjectingModule.php`, `InMemory/*ProjectionManager.php`, `InMemory/InMemoryEventStoreReadModelProjector.php`
— consistent with the release-design spec's "21 files" figure. `Prooph\Common\Messaging\MessageConverter`,
`Prooph\EventStore\Stream`, `Prooph\EventStore\StreamName`, `Prooph\EventStore\Metadata\MetadataMatcher`,
`Prooph\EventStore\Pdo\*EventStore`, `Prooph\EventStore\Pdo\PersistenceStrategy`,
`Prooph\EventStore\Pdo\WriteLockStrategy\*` are all imported directly. Any of these being renamed/removed upstream
is an operational risk Ecotone currently owns without controlling the code (see §3.7 for prooph's maintenance state).


---

## 2. Current state inventory

### 2.1 Classes / interfaces

| Class / Interface | Path | Role today |
|---|---|---|
| `Ecotone\EventSourcing\EventStore` | `packages/Ecotone/src/EventSourcing/EventStore.php` | Public interface: `create/appendTo/delete/hasStream/load` by stream name |
| `Ecotone\EventSourcing\EventStore\InMemoryEventStore` | `packages/Ecotone/src/EventSourcing/EventStore/InMemoryEventStore.php` | Ecotone-native in-memory implementation of the interface above, stream-keyed array, no tags/global position |
| `Ecotone\EventSourcing\EventStore\MetadataMatcher` | `packages/Ecotone/src/EventSourcing/EventStore/MetadataMatcher.php` | Generic metadata/property filter DSL used as a stand-in for queries |
| `Ecotone\EventSourcing\EventStore\FieldType` | `packages/Ecotone/src/EventSourcing/EventStore/FieldType.php` | enum `METADATA`\|`MESSAGE_PROPERTY` |
| `Ecotone\EventSourcing\EventStore\Operator` | `packages/Ecotone/src/EventSourcing/EventStore/Operator.php` | enum of 9 comparison operators |
| `Ecotone\EventSourcing\Prooph\LazyProophEventStore` | `packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php` | Lazily builds & caches a real `Prooph\EventStore\Pdo\{MySql,MariaDb,Postgres}EventStore` per connection/stream; owns the 5 persistence-strategy constants and table init |
| `Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper` | `packages/PdoEventSourcing/src/Prooph/EcotoneEventStoreProophWrapper.php` | Adapts `Ecotone\Modelling\Event`/arrays ⇄ `Prooph\EventStore\Stream`/`ProophMessage`; mints uuid4 event ids (line 76) |
| `Ecotone\EventSourcing\Prooph\ProophInMemoryEventStoreAdapter` | `packages/PdoEventSourcing/src/Prooph/ProophInMemoryEventStoreAdapter.php` | In-memory `Prooph\EventStore\EventStore` implementation for `EventSourcingConfiguration::createInMemory()`; mints uuid4 (line 211) |
| `Ecotone\EventSourcing\Prooph\Metadata\{MetadataMatcher,FieldType,Operator}` | `packages/PdoEventSourcing/src/Prooph/Metadata/*` | Converts Ecotone's `MetadataMatcher` DSL into Prooph's own equivalent types |
| `Ecotone\EventSourcing\ProophEventMapper` | `packages/PdoEventSourcing/src/ProophEventMapper.php` | Class-name ⇄ event-name mapping, reconstructs `Uuid::fromString($messageData['uuid'])` (line 27) |
| `Ecotone\EventSourcing\EventSourcingConfiguration` | `packages/PdoEventSourcing/src/EventSourcingConfiguration.php` | Public `#[ServiceContext]` extension object; persistence-strategy selection, table names, batch size, write-lock toggle, in-memory factory |
| `Ecotone\EventSourcing\EventSourcingRepository` | `packages/PdoEventSourcing/src/EventSourcingRepository.php` | Implements `Ecotone\Modelling\EventSourcedRepository`; stream-name + 3-clause `MetadataMatcher` load, plain `appendTo` save |
| `Ecotone\EventSourcing\EventSourcingRepositoryBuilder` | `packages/PdoEventSourcing/src/EventSourcingRepositoryBuilder.php` | Container wiring for the repository above |
| `Ecotone\EventSourcing\AggregateStreamMapping` / `AggregateTypeMapping` | `packages/PdoEventSourcing/src/AggregateStreamMapping.php` / `AggregateTypeMapping.php` | Class-name → custom stream/type-name overrides, `CompilableBuilder` |
| `Ecotone\EventSourcing\Database\EventStreamTableManager` | `packages/PdoEventSourcing/src/Database/EventStreamTableManager.php` | Owns the `event_streams` (Prooph-catalogue) table DDL for Postgres/MariaDB/MySQL; implements `Ecotone\Dbal\Database\DbalTableManager` |
| `Ecotone\EventSourcing\Database\LegacyProjectionsTableManager` | `packages/PdoEventSourcing/src/Database/LegacyProjectionsTableManager.php` | Prooph v1 `projections` table DDL — slated for removal with Group B |
| `Ecotone\EventSourcing\Database\ProjectionStateTableManager` | `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php` | `ecotone_projection_state` table: `(projection_name, partition_key) → last_position TEXT, metadata JSON, user_state JSON` — v2 projection position store, format-agnostic (§6.7 seam) |
| `Ecotone\Modelling\EventSourcedRepository` | `packages/Ecotone/src/Modelling/EventSourcedRepository.php` | Interface `canHandle/findBy/save`, the seam `EventSourcingRepository` implements |
| `Ecotone\EventSourcing\EventSourcedRepositoryAdapter` | `packages/Ecotone/src/EventSourcing/EventSourcedRepositoryAdapter.php` | Implements `Ecotone\Modelling\Repository\AggregateRepository`; owns snapshot read/write (`DocumentStore`), delegates event load/save to `EventSourcedRepository` |
| `Ecotone\Modelling\BaseEventSourcingConfiguration` | `packages/Ecotone/src/Modelling/BaseEventSourcingConfiguration.php` | `withSnapshotsFor($class, $thresholdTrigger, $documentStore)`, snapshot config storage — package-agnostic base that `EventSourcingConfiguration` extends |
| `Ecotone\EventSourcing\Projecting\StreamSource\EventStoreGlobalStreamSource` | `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreGlobalStreamSource.php` | ProjectionV2 global-log stream source: raw SQL against the physical stream table(s), `GapAwarePosition`-based tracking, multi-stream merge-by-timestamp |
| `Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSource` | `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreAggregateStreamSource.php` | ProjectionV2 per-aggregate stream source: uses `EventStore::load()` + `MetadataMatcher`, partition key format `"streamName:aggregateType:aggregateId"` |
| `Ecotone\EventSourcing\Projecting\StreamSource\GapAwarePosition` | `packages/PdoEventSourcing/src/Projecting/StreamSource/GapAwarePosition.php` | Value object: `position:int` + `gaps:list<int>`, serializes as `"pos:g1,g2"`, `advanceTo()`, `cleanByMaxOffset()`, `cutoffGapsBelow()` |
| `Ecotone\Projecting\StreamSource` (interface) | `packages/Ecotone/src/Projecting/StreamSource.php` | `canHandle(name): bool`, `load(name, lastPosition, count, partitionKey): StreamPage` — the seam Group B/D share |
| `Ecotone\Projecting\StreamPage` | `packages/Ecotone/src/Projecting/StreamPage.php` | `{events: Event[], lastPosition: string}` — opaque position string |
| `Ecotone\Projecting\StreamFilter` | `packages/Ecotone/src/Projecting/StreamFilter.php` | `{streamName, aggregateType?, eventStoreReferenceName, eventNames[]}` |
| `Ecotone\Projecting\ProjectingManager` | `packages/Ecotone/src/Projecting/ProjectingManager.php` | Runtime driver: `execute/executePartitionBatch/prepareBackfill/prepareRebuild/loadState/init/delete` — talks to `StreamSource` + `ProjectionStateStorage`, format-agnostic on position |
| `Ecotone\Dbal\Database\DbalTableManager` (interface) | `packages/Dbal/src/Database/DbalTableManager.php` | `getFeatureName/isUsed/getCreateTableSql/getDropTableSql/createTable/dropTable/isInitialized/shouldBeInitializedAutomatically` — the table-manager contract the new event/tag tables must implement |
| `Ecotone\Dbal\Database\DatabaseSetupManager` / `DatabaseSetupCommand` | `packages/Dbal/src/Database/DatabaseSetupManager.php` / `DatabaseSetupCommand.php` | Already-shipped generic CLI: `ecotone:migration:database:setup [--feature=] [--initialize] [--sql] [--onlyUsed]` driving any registered `DbalTableManager` |
| `Ecotone\EventSourcing\Attribute\{FromStream,FromAggregateStream}` | `packages/Ecotone/src/EventSourcing/Attribute/*.php` | v2 projection stream-source attributes (Enterprise-licensed, per docblock `licence Enterprise`) |
| `Ecotone\EventSourcing\Attribute\AggregateType` | `packages/Ecotone/src/EventSourcing/Attribute/AggregateType.php` | `#[AggregateType('name')]` — overrides the `_aggregate_type` metadata value for an aggregate class |

### 2.2 Constants / magic strings

| Constant | Path:line | Value / meaning |
|---|---|---|
| `LazyProophEventStore::SINGLE_STREAM_PERSISTENCE` | `Prooph/LazyProophEventStore.php:66` | `'single'`, `@deprecated Ecotone 2.0` |
| `LazyProophEventStore::PARTITION_STREAM_PERSISTENCE` | `Prooph/LazyProophEventStore.php:70` | `'partition'` — never actually reachable via `EventSourcingConfiguration::withPartitionStreamPersistenceStrategy()` (bug, §1.2) |
| `LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE` | `Prooph/LazyProophEventStore.php:71` | `'aggregate'` — 1 table per aggregate id |
| `LazyProophEventStore::SIMPLE_STREAM_PERSISTENCE` | `Prooph/LazyProophEventStore.php:72` | `'simple'` |
| `LazyProophEventStore::CUSTOM_STREAM_PERSISTENCE` | `Prooph/LazyProophEventStore.php:73` | `'custom'` |
| `LazyProophEventStore::AGGREGATE_VERSION/TYPE/ID` | `Prooph/LazyProophEventStore.php:75-77` | `'_aggregate_version'`, `'_aggregate_type'`, `'_aggregate_id'` — metadata keys, mirrored in `MessageHeaders::EVENT_AGGREGATE_{TYPE,ID,VERSION}` (`packages/Ecotone/src/Messaging/MessageHeaders.php:124-128`) |
| `LazyProophEventStore::DEFAULT_STREAM_TABLE` | `Prooph/LazyProophEventStore.php:54` | `'event_streams'` |
| `LazyProophEventStore::DEFAULT_PROJECTIONS_TABLE` | `Prooph/LazyProophEventStore.php:55` | `'projections'` (v1 only) |
| `LazyProophEventStore::LOAD_BATCH_SIZE` | `Prooph/LazyProophEventStore.php:52` | `1000` |

### 2.3 CLI commands (v1, all slated for removal with Group B, confirmed in `Config/EventSourcingModule.php`)

| Constant | Command string | Registered at |
|---|---|---|
| `ECOTONE_ES_STOP_PROJECTION` | `ecotone:es:stop-projection` | `EventSourcingModule.php:92` |
| `ECOTONE_ES_RESET_PROJECTION` | `ecotone:es:reset-projection` | `EventSourcingModule.php:93` |
| `ECOTONE_ES_DELETE_PROJECTION` | `ecotone:es:delete-projection` | `EventSourcingModule.php:94` |
| `ECOTONE_ES_INITIALIZE_PROJECTION` | `ecotone:es:initialize-projection` | `EventSourcingModule.php:95` |
| `ECOTONE_ES_TRIGGER_PROJECTION` | `ecotone:es:trigger-projection` | `EventSourcingModule.php:96` |

Already-shipped, DCB-store-agnostic CLI to build on: `ecotone:migration:database:setup` (`Ecotone\Dbal\Database\DatabaseSetupCommand`,
`packages/Dbal/src/Database/DatabaseSetupCommand.php:25`), which drives any `DbalTableManager` (`--feature=`, `--sql`,
`--initialize`, `--onlyUsed`). Note this differs from the command name `ecotone:database:setup` used in
`upgrade-2.0.md:223` — the actual current attribute is `#[ConsoleCommand('ecotone:migration:database:setup')]`;
either the upgrade guide needs correcting or Group F intends to rename it — flagged for the maintainer (§10).

### 2.4 Tests referencing today's event-store internals (non-exhaustive)

`packages/PdoEventSourcing/tests/` contains suites exercising `LazyProophEventStore`, persistence strategies,
`MetadataMatcher`, `GapAwarePosition`/`EventStoreGlobalStreamSource`, `EventSourcingRepository`, and snapshotting
(`tests/Integration/SnapshotsTest.php`, called out with a `fixme` at line 42 in the release-design spec re:
`classesToResolve`). These were not individually re-read for this report — they are Group D/B's implementation
concern, flagged here as inventory, not verified line-by-line.

### 2.5 What does NOT exist today (verified by `rg`, not assumed)

- No `Tags`/`#[EventTag]`/`#[Tags]` class or attribute anywhere in `packages/Ecotone/src` or `packages/PdoEventSourcing/src`
  (`rg -l "class Tags|interface Tags|#\[Tags\]|EventTag"` → no matches).
- No `LoadEventSourcingAggregateService` class (task brief's assumed name; see §1.7).
- No `AppendCondition`/`ConcurrencyException`-from-query concept — `Ecotone\Messaging\Support\ConcurrencyException`
  (`packages/Ecotone/src/Messaging/Support/ConcurrencyException.php:12`) exists today only as the class thrown when
  Prooph's own unique-constraint-triggered exception is caught (§1.3); it carries no query/position context.
- No `ecotone/event-sourcing` composer package — current package name is `ecotone/pdo-event-sourcing`
  (directory `packages/PdoEventSourcing`).

---

## 3. Prior art / web research

### 3.1 Sara Pellegrini — the origin of DCB

Sara Pellegrini coined "Dynamic Consistency Boundary" in her talk "Kill Aggregate!" and the follow-up blog series;
the canonical write-up is ["A name for an idea: Dynamic Consistency Boundary"](https://sara.event-thinking.io/2023/05/dynamic-consistency-boundary.html)
(sara.event-thinking.io, 2023). Core idea, confirmed by direct fetch of that article: a "decision model" is a pure
function taking an ordered stream of events as input and producing new events as output; the event store's job is
to (1) let the decision model query the specific slice of events it cares about — by tag, not by a fixed aggregate
stream — and (2) let it append conditionally: "verifying the last event's match ensures the entire stream hasn't
changed, eliminating the need for aggregate consistency boundaries while maintaining strong consistency guarantees
where needed." The consistency boundary is thus decided *per decision*, dynamically, rather than fixed at the
aggregate's design time — this is exactly what §5.2-§5.3 and §6.8's proposal implement. Also see the December 2025
retrospective interview ["Kill Aggregate? An Interview on Dynamic Consistency Boundaries"](https://docs.eventsourcingdb.io/blog/2025/12/15/kill-aggregate-an-interview-on-dynamic-consistency-boundaries/)
(eventsourcingdb.io) and the community hub site [dcb.events](https://dcb.events/), which now aggregates the wider
DCB conversation (implementations, FAQ, projections guidance) beyond Pellegrini's original posts.

### 3.2 The dcb.events specification

[dcb.events/specification](https://dcb.events/specification/) is a community-maintained, language-agnostic
specification (fetched directly for this report). Its core API shape, confirmed verbatim from the spec:

```
read(query: Query, options?: ReadOptions): SequencedEvents
append(events: Events|Event, condition?: AppendCondition): void
```

- **`Query`** — filters by event type and/or tags; built from `QueryItem`s, OR-ed together, where each `QueryItem`
  matches "events where type matches ONE provided type AND tags contain ALL specified tags." Factory methods
  `Query.fromItems(items)` / `Query.all()`.
- **`Tags`** — a domain-specific metadata set attached to events, e.g. `product:p123` — i.e. exactly the
  `{tagKey: tagValue}` shape §5.1/§5.2 propose.
- **`SequencePosition`** — "unique, monotonically increasing identifier assigned during append; may contain gaps" —
  the spec itself acknowledges gaps as an expected, not exceptional, property of the position space, consistent
  with §6.5's approach (detect and eventually fill/skip gaps, don't try to make positions gapless).
- **`AppendCondition`** — `{failIfEventsMatch: Query, after?: SequencePosition}`; append fails if the store contains
  any event matching `failIfEventsMatch` with a position after `after`. §5.2's `AppendCondition{query,
  lastKnownPosition}` proposal is a direct, near-1:1 mapping onto this — Ecotone should adopt the same two-field
  shape rather than inventing a divergent one, for conceptual compatibility with the wider DCB ecosystem.
- The spec explicitly supports **unconditional append** (`condition` optional) "when importing data or for testing
  purposes" — validating §5.2's `$condition = null` unconditional-append path.

**A PHP reference implementation already exists**: [github.com/bwaidelich/dcb-eventstore](https://github.com/bwaidelich/dcb-eventstore)
(also published as [`wwwision/dcb-eventstore`](https://packagist.org/packages/wwwision/dcb-eventstore) on
Packagist). Directly fetched and confirmed: it is a **specification/interface package, not a concrete database
implementation** — it defines `Event`/`Events`, `Query`/`QueryItem`, `AppendCondition`, `SequencePosition`,
`ReadOptions`, `SequencedEvents` as PHP interfaces/value objects, ships an in-memory test implementation, and
expects separate adapter packages to provide real persistence (the project's own ecosystem lists a Doctrine DBAL
adapter covering SQLite/MySQL/PostgreSQL, a Laravel adapter, and adapters for two closed/other-language backends).
This is a serious "build vs adopt" data point for §4 — see §4.2.

Other DCB-flavoured implementations found while researching, noted for completeness but not deeply evaluated:
[m1l4n54v1c/event-store](https://github.com/m1l4n54v1c/event-store) ("naiive implementation of the Event Store
supporting DCB concept", PHP), and the Python
[`eventsourcing` library's DCB module](https://eventsourcing.readthedocs.io/en/latest/topics/dcb.html), which
documents the same Query/Tags/AppendCondition vocabulary in a different language, reinforcing that this is
converging into a genuine cross-language pattern, not one vendor's idea.

### 3.3 Axon Framework / Axon Server — DCB support

Confirmed via direct search: **Axon Framework 5 and Axon Server 2025.1+ ship DCB support as of 2025**, not merely
a proposal. AxonIQ's own posts: ["Dynamic Consistency Boundary (DCB) in Axon Framework 5 — Event Sourcing That's
Adaptable"](https://www.axoniq.io/blog/dcb-in-af-5) and ["Announcing Axon Server 2025.1 with Dynamic Consistency
Boundary (DCB) — An Event Store that is future-proof"](https://www.axoniq.io/blog/axon-server-future-proof-event-store),
plus a broader piece, ["Rethinking microservices architecture through Dynamic Consistency
Boundaries"](https://www.axoniq.io/blog/rethinking-microservices-architecture-through-dynamic-consistency-boundaries),
and a runnable sample, [AxonIQ/university-demo](https://github.com/AxonIQ/university-demo) ("Sample app to show how
one can use Axon Framework 5 along with DCB"). AxonIQ's framing: DCB creates a "temporary consistency bubble" — "a
boundary created on demand for a specific operation or transaction that includes just the data you need" — enabling
code organised around features/use-cases ("Vertical Slice Architecture") rather than fixed aggregate classes. As of
the sources found, **Axon Server 2025.1 with DCB is explicitly stated as being for experimentation/early feedback,
not production use** — i.e. even the most resourced commercial JVM event-sourcing vendor treats DCB-at-the-store-level
as still maturing. This is a useful calibration for Ecotone: DCB is real, vendor-validated, and worth building
towards, but no mainstream implementation yet claims production-hardened status for the full decision-model layer
(§6.8's "store now, decision-model layer later" recommendation is consistent with where the rest of the industry is).

### 3.4 Marten — Postgres event store, gap/high-water-mark handling

[martendb.io](https://martendb.io/) (.NET, Postgres-backed). Confirmed via direct search of Marten's own docs
(`martendb.io/events/appending`, `martendb.io/events/projections/async-daemon.html`, `martendb.io/events/configuration.html`):

- Marten's async projection daemon tracks a **"high water mark"**: "the furthest known event sequence that the
  daemon 'knows' that all events with that sequence or lower can be safely processed in order by projections."
- The high-water mark deliberately lags the highest *allocated* sequence number whenever a gap is suspected — the
  daemon "constantly watches your database to know where the high water mark is."
- **Tombstone events** (introduced in Marten v4): on a failed/rolled-back transaction, Marten inserts a placeholder
  ("tombstone") row at the sequence number(s) that would otherwise be permanently missing, specifically so the
  gap-detection logic has a real row to observe rather than having to infer absence — this is functionally
  equivalent to what §6.5 calls "closing a gap once its transaction is provably finished," except Marten does it by
  writing a marker row instead of re-checking transaction visibility.
- Marten's own docs recommend the "QuickAppend" append mode over "gap-prone" alternatives specifically because it
  is "substantially less likely to lead to gaps in the event sequence," i.e. Marten treats gap *frequency* as a
  tunable trade-off, not just gap *detection* as a solved problem — worth carrying into Ecotone's own docs (§7).
- Independently, Oskar Dudycz's ["How Postgres sequences issues can impact your messaging
  guarantees"](https://event-driven.io/en/ordering_in_postgres_outbox/) (event-driven.io) — fetched directly for
  this report — describes the identical problem for a Postgres outbox table and evaluates the same three fixes
  §6.5 considered: (1) a gapless singleton-counter sequence ("nuke option," serializes all writes, rejected for the
  same throughput reason §6.5 rejects it), (2) Marten-style gap detection with tombstones ("high complexity"), and
  (3) **filtering by transaction id via `pg_snapshot_xmin(pg_current_snapshot())`** — recommended by that article as
  the best trade-off, with the exact SQL pattern:
  ```sql
  SELECT position, message_id, message_type, data
  FROM outbox
  WHERE transaction_id < pg_snapshot_xmin(pg_current_snapshot())
  ORDER BY transaction_id ASC, position ASC
  LIMIT 100;
  ```
  This independently corroborates §6.5's recommendation (Postgres transaction-visibility filtering, not wall-clock
  timeout) from a second, unrelated source outside the event-sourcing-vendor space.

**Addendum — Marten ships a dedicated DCB feature with a concrete, race-free conditional-append primitive**
(confirmed via [martendb.io/events/dcb.html](https://martendb.io/events/dcb.html), distinct from the
general-purpose async-daemon high-water-mark mechanism described above): tag types are registered per store
(`opts.Events.RegisterTagType<StudentId>("student")`), each getting its own Postgres table keyed
`(value, seq_id)`; cross-stream reads go through `FetchForWritingByTags<T>(query)`, and the write side is backed
by a side table `mt_dcb_tag_version(tag, version)`. On save, Marten issues **one `INSERT ... ON CONFLICT DO
UPDATE SET version = version + 1 WHERE version = $capturedVersion` per tag** captured at read time; if the
`WHERE` predicate doesn't match (another writer already bumped that tag's version), the statement affects 0 rows
and Marten raises `DcbConcurrencyException`. This is a materially different, and materially stronger, guarantee
than a plain "re-check at commit time" `SELECT` (§5.2/§6.2's `AppendCondition` design, line ~1008's edge-case
entry): a bare `SELECT ... WHERE query MATCHES AND global_position > :lastKnownPosition` run inside the writer's
own transaction, under ordinary `READ COMMITTED`, does **not** see another concurrent transaction's not-yet-committed
insert — so two transactions can both run that check, both see "no conflict," and both commit, silently violating
the consistency boundary DCB exists to guarantee. The `INSERT ... ON CONFLICT ... WHERE version = $captured`
form avoids this because the `UPDATE`'s row-level lock makes the version bump itself atomic and mutually
exclusive across concurrent writers touching the same tag — no `SELECT FOR UPDATE`, advisory lock, or
`SERIALIZABLE` isolation is needed. **Recommendation: adopt this exact mechanism as the implementation behind
`AppendCondition` in §5.2/§6** — maintain a small `event_tag_versions(tag PK, version)` table alongside
`event_tags`, and implement `EventStore::append()`'s conflict check as one `INSERT ... ON CONFLICT DO
UPDATE ... WHERE version = :captured` per tag in the `AppendCondition`'s query, portable to MySQL 8/MariaDB via
`INSERT ... ON DUPLICATE KEY UPDATE version = IF(version = :captured, version + 1, version)` — MySQL's own
documented affected-rows contract for this statement shape is precise and directly usable: 1 row affected means a
genuinely new row was inserted, 2 means an existing row was updated to a new value, 0 means an existing row
matched but its value did not change (["MySQL 8.0 Reference Manual — INSERT ... ON DUPLICATE KEY
UPDATE"](https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html): *"the affected-rows value per row is
1 if the row is inserted as a new row, 2 if an existing row is updated, and 0 if an existing row is set to its
current values"*) — so a captured-version mismatch (the `IF` guard leaves `version` unchanged) is unambiguously
distinguishable from a successful bump purely from the driver's reported affected-row count, with **no**
`ROW_COUNT()`/`CLIENT_FOUND_ROWS` caveat needed as long as the PHP DBAL driver does not request the
`CLIENT_FOUND_ROWS` connection flag (which flips the 0 case to 1 and would break this check — verify Ecotone's
MySQL DBAL connection setup does not set it, as part of implementation task 6/7 in §9). This closes what would
otherwise be a genuine correctness gap in the "re-check at commit time" description elsewhere in this report —
the check must be an atomic, lock-implying write, not a plain `SELECT`, or the boundary it's supposed to enforce
doesn't actually hold under concurrent load. Full per-engine mechanism, cost, and failure-mode analysis — including
why this table-based CAS is recommended over both Postgres `SERIALIZABLE`+retry and a MySQL advisory lock
(§3.4 Addendum 2) — is in §6.1.5.

**Addendum 2 — what the actual PHP DCB reference *implementation* does (`bwaidelich/dcb-eventstore-doctrine`),
fetched and read directly from source for this revision** (`gh api repos/bwaidelich/dcb-eventstore-doctrine/contents/src/DoctrineEventStore.php`,
current `main` as of this research pass — §3.2's earlier pass only read the interface package, not this adapter's
actual code; that gap is closed here). Contrary to this report's original §4/§6.1 assumption of a normalised tag
table, the reference implementation uses a **single table**, `tags` stored as one `JSON`/`JSONB` column, no side
table at all:

```php
new Column('sequence_number', ...)->setAutoincrement(true),   // PK
new Column('type', ...),
new Column('data', Types::TEXT),
new Column('metadata', Types::JSON)->setPlatformOptions(['jsonb' => true]),  // Postgres only
new Column('tags', Types::JSON)->setPlatformOptions(['jsonb' => true]),      // Postgres only
new Column('recorded_at', Types::DATETIME_IMMUTABLE),
```
with `new Index('idx_type', ...)`, `new Index('idx_type_sequence_number', ...)`, and, **Postgres only**,
`CREATE INDEX ... USING gin (tags jsonb_path_ops)`. Tag matching is `tags @> :tags::jsonb` on Postgres,
`JSON_CONTAINS(tags, :tags)` on MySQL/MariaDB (**no index created for it at all** — confirmed by reading the
`setup()` method: the GIN-index branch is `if ($this->config->isPostgreSQL())`, nothing else), and a correlated
`JSON_EACH` subquery on SQLite. This is a direct, load-bearing data point for open question 10 (§10) and revises
this report's §4/§6.1 comparison against option (D) — see §6.1 for the resulting change.

Concurrency is enforced by `commitStatement()`, read directly from source:
- **Postgres**: `BEGIN ISOLATION LEVEL SERIALIZABLE`, then a single
  `INSERT INTO events (...) SELECT * FROM (...) new_events WHERE NOT EXISTS (<condition query>)`, checking
  `affectedRows === 0` to detect a failed condition, `COMMIT`/`ROLLBACK`, retrying on `DeadlockException`
  (`SQLSTATE 40001`/serialization failure) with exponential backoff (10 attempts, starting at 5ms).
- **MySQL**: the code comments its own reason for *not* trusting InnoDB locking here — `"MySQL's SERIALIZABLE
  isolation does not acquire gap locks for complex INSERT...WHERE NOT EXISTS queries with derived subqueries,
  allowing phantom reads. We use an advisory lock instead"` — `SELECT GET_LOCK('dcb_<table>', 30)` taken before
  the statement, `RELEASE_LOCK` in a `finally` block. This is primary-source confirmation, from a real production
  DCB implementation's own commit history, of exactly the InnoDB gap-lock unreliability C1/§6.1.5 has to reason
  about for the *specific* `INSERT...SELECT...WHERE NOT EXISTS` statement shape (distinct from a plain
  `SELECT...FOR UPDATE` re-check, which behaves differently — §6.1.5 spells out the distinction).
- Critically, `commitStatement()` opens **its own top-level transaction**
  (`Assert::eq($connection->getTransactionNestingLevel(), 0, 'Failed to commit events because a database
  transaction is active already')`) — `append()` cannot be called from inside a transaction the caller already
  started. This is a real architectural constraint this report's §4 recommendation (A) must reckon with — see
  §6.1.5.

### 3.5 EventStoreDB / KurrentDB

Confirmed via search (EventStoreDB/KurrentDB official docs, `docs.kurrent.io`, `developers.eventstore.com`):
appending supports an optional **expected revision/stream-state** check — "you can supply a stream state or stream
revision to tell EventStoreDB what state or version you expect the stream to be in when you append, and if the
stream isn't in that state then an exception will be thrown" — i.e. conditional append keyed to a *stream-local*
revision, not a store-wide tag query (EventStoreDB has no tag/DCB concept as of the sources found — its consistency
boundary is still the single stream). Two distinct position systems exist and must not be conflated: the
per-stream **revision** (0-based sequential index within one stream) and the **global position** in the `$all`
stream, itself a pair `(commitPosition, preparePosition)` describing where the transaction was committed/prepared
in the transaction log. `$all` is the globally-ordered log across every stream — "if you subscribe to `$all`, you
will receive events from multiple streams respecting the global order in which they occur," including from
soft-deleted streams. Ecotone's proposed `GlobalPosition` (§5.2/§6) plays the same conceptual role as EventStoreDB's
`$all` position, but EventStoreDB does not need gap-handling logic for it the way Postgres/MySQL DBAL stores do,
because it is a purpose-built log-structured store with a single writer per partition, not a general-purpose RDBMS
with arbitrary concurrent transactions — the gap problem (§6.5) is specific to building an event log *on top of* a
general-purpose transactional database, which is Ecotone's situation and not EventStoreDB's.

### 3.6 Postgres-specific gap-handling primitives

Directly confirmed (search + the Dudycz article above, plus general Postgres documentation matches in results):
`pg_current_snapshot()` / legacy `txid_current_snapshot()` return the current MVCC snapshot; `pg_snapshot_xmin()`
extracts the oldest transaction id still considered "in progress" as of that snapshot. Because Postgres transaction
ids are assigned in monotonic, gapless order (unlike the `event_log.global_position` sequence, which can have gaps
from rollbacks), comparing a row's recorded `transaction_id` against `pg_snapshot_xmin(pg_current_snapshot())`
answers "is it certain no older, still-uncommitted transaction could still write a lower position than this row?" —
which is precisely the question §6.5's Postgres recommendation needs answered, and is the same technique both
Marten-adjacent commentary (Dudycz, §3.4) and general Postgres MVCC documentation
([jnidzwetzki.github.io — "Introduction to Snapshots and Tuple Visibility in
PostgreSQL"](https://jnidzwetzki.github.io/2024/04/03/postgres-and-snapshots.html)) describe as the standard
mechanism for this exact class of problem.

### 3.7 prooph — GapDetection and project status

`prooph/pdo-event-store`'s `GapDetection` (source: [prooph/pdo-event-store on
GitHub](https://github.com/prooph/pdo-event-store), specifically
`Prooph\EventStore\Pdo\Projection\GapDetection`, mirrored by Ecotone's own wrapper, §1.5) is confirmed
(search results referencing the class and its docs at the now-defunct `docs.getprooph.org`) to work by retry +
`DateInterval` **detection window**: "gap detection is only performed on events not older than NOW - window,"
e.g. a `PT60S` window checks only events created within the last 60 seconds. This is wall-clock/event-timestamp
based, not transaction-visibility based (§1.5, §6.5) — confirming the report's characterisation of it as fragile
under clock skew or long-running transactions. A related open upstream issue referenced in search results,
`prooph/pdo-event-store#189` ("MySQL Projections skipping events (SingleStreamStrategy)"), is direct evidence of
this exact failure mode occurring in production against MySQL. No evidence was found of a "prooph v8 roadmap" or
any public discussion of DCB from the prooph project itself; the GitHub repository shows no recent release activity
in the search results returned, consistent with the release-design spec's characterisation of prooph as a
maintenance risk (§1.8) rather than an actively evolving project Ecotone could instead contribute DCB support to.

**Primary-source confirmation from Ecotone's own issue tracker.** [GitHub issue
#438](https://github.com/ecotoneframework/ecotone-dev/issues/438) is not secondary commentary — it is Ecotone's
own maintainer (`dgafka`) responding to a question from contributor `lifinsky` about gap-detection risk for
synchronous projections lagging behind an event-sourced aggregate stream. Quoted verbatim, because it is the
strongest evidence in this entire research pass that a wall-clock retry mechanism is the wrong long-term design
— it comes from inside the project, about this project's own code, not an outside critique:

> "Prooph projections work on the global level (they do not follow given event stream for aggregate, but whole
> event log) — the problem may manifest in case of concurrent access between Aggregates. In that situation if
> one transaction started first, yet committed after the second transaction (which started after) it will
> create gap in sequence numbers. In such situation the second transaction will actually wait for up [to] 8
> seconds for the first one to finish... So the problem could be created if after those 8 seconds, first
> transaction has actually not committed and if it's in some idle stay and get committed after that 8 second, it
> will create gap in projection... You can increase the timing of 8 seconds, however that comes with cost of
> performance... **The aim of new projecting system is actually to solve this problem on the root level without
> fragile gap detection system.**"

This independently corroborates both this report's characterisation of the mechanism as fragile (§1.5) and its
own stated design goal — "solve this problem on the root level," i.e. exactly the transaction-evidence-based
high-water mark §6.5 proposes, not a re-tuned retry window. The same thread also notes a **documentation/code
drift**: the retry window documented at
[docs.ecotone.tech](https://docs.ecotone.tech/modelling/event-sourcing/setting-up-projections/executing-and-managing/running-projections)
did not match the shipped default at the time — a further, concrete symptom of how easy this mechanism is to get
subtly wrong, worth a maintainer sanity-check once the new mechanism's docs are written (§10).

### 3.8 Other language/library event stores

- **EventSauce (PHP)** — [eventsauce.io](https://eventsauce.io/) / [GitHub](https://github.com/eventsaucephp/eventsauce).
  "A pragmatic event sourcing library for PHP with a focus on developer experience"; concurrency is handled via
  aggregate-root version numbers enforced by a unique DB constraint (confirmed via
  [EventSauce issue #47, "How does EventSauce handle concurrency issues?"](https://github.com/EventSaucePHP/EventSauce/issues/47))
  — i.e. the same single-aggregate optimistic-locking model Ecotone has today (§1.3), no DCB/tag concept.
- **Broadway (PHP)** — provides Doctrine DBAL and MongoDB event store implementations plus CQRS/read-model
  infrastructure; no evidence found of tag-based/DCB querying — stream-per-aggregate like Ecotone's current
  `aggregate` strategy (§1.2).
- **Rails Event Store** — no DCB-specific material surfaced in this search pass; flagged as not independently
  verified for this report (the general search results did not return Rails-Event-Store-specific detail beyond
  general listings).
- **Commanded (Elixir)**, backed by [commanded/eventstore](https://github.com/commanded/eventstore) (Postgres) —
  per-stream `expected_version` optimistic concurrency ("before appending, we verify that no other process has
  written to this stream since we last read it"); Commanded's own consistency model defaults to eventual
  consistency between command dispatch and event-handler completion, with an explicit opt-in to strong (blocking)
  consistency per dispatch — a different axis of "consistency boundary" (command/handler synchronicity) than DCB's
  (which tags belong to one append), not directly comparable.
- **Emmett (TypeScript)** — not independently confirmed in this search pass (no Emmett-specific results surfaced);
  a related TypeScript project *was* found —
  [ricofritzsche/eventstore-typescript](https://github.com/ricofritzsche/eventstore-typescript), which explicitly
  implements "atomic consistency through optimistic locking using Common Table Expressions (CTEs with Postgres),
  ensuring that concurrent operations only conflict when they actually depend on the same event context, rather
  than using traditional aggregate-level locking" — i.e. a DCB-flavoured, non-aggregate-scoped conditional append,
  independently arriving at the same idea via a CTE-based Postgres approach. Worth a closer look at implementation
  time given the similarity to §6.2's proposed query shape, but not verified in depth here.
- **message-db (Postgres)** — [github.com/message-db/message-db](https://github.com/message-db/message-db)
  ("microservice native message and event store for Postgres", extracted from the Eventide project — see the
  [announcement](https://blog.eventide-project.org/articles/announcing-message-db/)). Confirmed distinction
  directly relevant to Ecotone's design: **within a single stream, message-db guarantees gapless positions; within
  a *category* (a group of related streams, message-db's closest analogue to Ecotone's tags), positions "possibly
  [have] gaps."** This is independent, real-world confirmation that a global/category-level position sequence
  having gaps — while single-stream positions stay gapless — is normal, expected behaviour in a mature production
  Postgres event store, not a defect to be engineered away; it directly supports §6's design (gaps are expected and
  handled, not eliminated) and the dcb.events spec's own framing (§3.2: "may contain gaps").

### 3.9 JSONB/GIN vs normalised join table for tag queries

Multiple independent sources confirm the trade-off assumed in §4/§6.1: JSONB with a GIN index is excellent for
*existence*/*containment* queries (`@>`, `?`) but has real, documented costs — "GIN indexes do not accelerate `->>`
extraction queries" (a common misconception per
[dev.to — "PostgreSQL JSONB Indexing: GIN, Expression & Partial Index
Strategies"](https://dev.to/philip_mcclarence_2ef9475/postgresql-jsonb-indexing-gin-expression-partial-index-strategies-i11)),
and — most relevant to an *append-heavy* event log — "PostgreSQL cannot perform partial updates for JSONB at the
storage level, so even for small changes, it must read the entire document, modify it in memory, and write a new
full copy," causing write amplification and TOAST bloat at scale (per
[sitepoint.com — "PostgreSQL JSONB Performance Guide"](https://www.sitepoint.com/postgresql-jsonb-query-performance-indexing/)
and a Medium benchmark write-up,
["Comparing query performance in PostgreSQL: JSONB vs Join queries"](https://medium.com/@sruthiganesh/comparing-query-performance-in-postgresql-jsonb-vs-join-queries-e4832342d750)).
Event rows in Ecotone's design are append-only/immutable (tags for an event never change after insert), which
avoids JSONB's update-amplification problem specifically — but the write-side cost of GIN index maintenance on
every insert (GIN indexes are more expensive to maintain per write than a plain B-tree, independent of whether the
underlying row is later updated) still applies to every single event append, which is Ecotone's hottest path. This
is the concrete reasoning behind §6.1's recommendation to keep JSONB for the free-form `payload`/`metadata` columns
(read/written whole, once, per event — JSONB's actual sweet spot) but use a plain-B-tree-indexed normalised side
table for `tags` (written once per tag per event, queried by exact key+value match constantly) — separating "data
you read as a blob" from "data you query by exact match," rather than putting both in the same JSONB-and-GIN
bucket.

### 3.10 MySQL 8 / MariaDB — no Postgres-equivalent visibility primitive; multi-valued indexes exist but don't replace a join table

Confirmed: MySQL 8.0.17+ supports **multi-valued indexes** — `CREATE INDEX ... ((CAST(json_col AS <type> ARRAY)))`
— letting `MEMBER OF`/`JSON_CONTAINS`/`JSON_OVERLAPS` predicates use an index instead of a table scan (per
[Mydbops — "Master Multi Valued Indexing for Faster Queries in MySQL 8.0"](https://medium.com/@mydbopsdatabasemanagement/master-multi-valued-indexing-for-faster-queries-in-mysql-8-0-2b8155fb2268)
and [jusdb.com's guide](https://www.jusdb.com/blog/improving-query-performance-with-multi-valued-indexing-in-mysql-80)).
This is MySQL's closest analogue to Postgres GIN-on-JSONB — but confirmed limitations make it unsuitable as
Ecotone's primary tag index: **multi-valued indexes cannot be a primary key and don't support ordering**, so a
tag-then-position range scan (the query shape §6.2 needs for "give me this aggregate's events in order") cannot be
served by a multi-valued index alone; it would still need a second lookup/sort step. This is exactly why §6.1's
revised, hybrid recommendation gives MySQL/MariaDB a normalised `event_tags` side table while Postgres uses
JSONB+GIN directly (§6.1's original "same side table on both engines" framing is superseded there — MySQL/MariaDB
need the side table on its own merits, independent of what Postgres does) rather than a JSON-array-plus-multi-valued-index
scheme that would only handle the "does event X have tag Y" existence check, not the ordered range scan the store
actually needs on its hot path. No MySQL/MariaDB equivalent of
`pg_snapshot_xmin`/`txid_current_snapshot` was found in any source consulted for this report — confirming §6.5's
conclusion that MySQL/MariaDB gap detection has no choice but to fall back to a bounded, honestly-documented
wall-clock window.


---

## 4. Alternative approaches compared

Four approaches were compared: (A) build an Ecotone-native DBAL store with a normalised tag table and
Postgres-snapshot-based gap detection (this report's recommendation, detailed in §5-§6); (B) adopt
`bwaidelich/dcb-eventstore` (§3.2) as a dependency and write only its Doctrine-DBAL adapter, wiring it under
Ecotone's own `EventStore`/`EventSourcedRepository` interfaces; (C) fork/vendor `prooph/pdo-event-store` in place
(the way `Enqueue\Dbal` was already vendored, per Group C) and extend it with a tag table bolted onto its existing
schema; (D) a JSONB-tags-plus-GIN-index design (Marten/EventStoreDB-adjacent — one JSONB `tags` column per event,
no side table) instead of the normalised table in (A).

| | (A) Ecotone-native, normalised tags — **recommended** | (B) Adopt `dcb-eventstore` + write DBAL adapter | (C) Fork/vendor prooph, bolt on tags | (D) JSONB tags + GIN, no side table |
|---|---|---|---|---|
| DX | Full control over error messages, extension-object shape, and attribute ergonomics matching Ecotone's existing conventions (§5) | Good — spec-shaped API is clean, but two vocabularies to reconcile (dcb.events' `Query`/`Tags` vs Ecotone's `MetadataMatcher` heritage and `#[ServiceContext]` extension-object style) unless a translation layer is added, which itself is extra surface | Poor — inherits Prooph's `PersistenceStrategy`/`WriteLockStrategy` vocabulary (§1.2, §1.8) that 2.0 is explicitly trying to remove from the public API | Similar to (A) at the API layer (JSONB is an internal detail); no DX difference from (A) at the `Query`/`Tag` level |
| Performance | Tight index range scans on `(tag_key, tag_value, global_position)`/`(tag_key, tag_value, tag_local_seq)` on all four engines uniformly (§6.1 — reverted to a uniform side table, D4 in the round-2 challenge review; see below); write cost = 1 narrow insert per tag per event, which the design needs regardless for versioning (§6.1.5/§6.9) | **Independently verified (§3.4 Addendum 2)**: single JSONB `tags` column, GIN-indexed **on Postgres only** — confirmed by reading `DoctrineEventStore::setup()` directly, MySQL/MariaDB get **no tag index at all** (`JSON_CONTAINS` full-scan). Concurrency is `SERIALIZABLE`+retry on Postgres and an application-level advisory lock (`GET_LOCK`) on MySQL — both real costs (§6.1.5). Crucially, `bwaidelich/dcb-eventstore-doctrine` has **no per-tag version counter at all** — its concurrency model needs no `tag_local_seq`-equivalent, which is exactly why JSONB-only was viable *for it* and is not directly transferable to Ecotone's design (D4) | Comparable to (A) if tags are added as a genuinely new normalised table, but Prooph's persistence-strategy abstraction (`generateTableName`, per-strategy classes) resists being reshaped around "one global log," fighting the grain of the library rather than working with it | Read: fast for "does event have tag X" (GIN, Postgres only); slow relative to (A) for exact-match range scans and for intersections across 2+ tags on MySQL/MariaDB, which have no comparable index. Write: GIN index maintenance cost on every append + JSONB write-amplification risk if tags are ever mutated post-hoc (§3.9). **Disqualifying for Ecotone specifically (D4, new in this revision)**: even on Postgres, JSONB cannot serve the per-tag CAS/versioning table `event_tag_versions` needs (§6.1.5/§6.9) — that table has to exist regardless, as a normalised `(tag_key, tag_value)` structure, so a JSONB `tags` column would be pure *additional* write cost (a second, redundant physical representation of the same tags) rather than a simplification. This is the concrete reason (D), tempting on read performance alone, is rejected even for the one engine where it looks attractive |
| Migration cost (from today's Prooph tables) | Documented, first-party migration tool (§8, §9 task 13) — full control over the copy semantics, resumability, and event-id preservation | Same migration problem as (A), *plus* a second migration: existing Ecotone `Event`/metadata shapes must also be translated into `dcb-eventstore`'s own `Event`/`Tags` value objects, doubling the transformation surface | Lowest short-term migration cost (existing Prooph tables mostly keep their shape; only a tag table is added) — but this is exactly why it doesn't solve the actual problem: the four persistence strategies (§1.2) and Prooph's stream-name-centric model remain, so "migration cost" is low only because "how much actually changed" is also low | Same as (A) — same migration tool, only the target table's internal tag storage format differs |
| DB portability (PG / MySQL 8 / MariaDB / SQLite) | One physical layout, one query shape, on all four engines (§6.1.1-6.1.3, D4) — the earlier hybrid design's "match what Postgres does well" argument no longer applies once JSONB is ruled out for Ecotone specifically (see Performance row) | **Verified, not assumed**: `DoctrineEventStoreConfiguration::isPostgreSQL()/isMySQL()/isSQLite()` branches confirm SQLite/MySQL/PostgreSQL support (MariaDB rides the `isMySQL()` branch, untested separately in what was read) — real portability, but with the tag-index gap above baked in for two of the three non-SQLite engines | Prooph's existing MySQL/MariaDB/Postgres persistence strategies already exist (§1.2) — nominally good portability, but inherited, not designed for the tag-query access pattern | JSONB is Postgres-only in its efficient form; MySQL/MariaDB would need an entirely different (multi-valued-index, §3.10) implementation anyway, so portability effectively collapses back to something resembling (A)'s per-engine split, without gaining anything for the Postgres case |
| Transactional fit with Ecotone's architecture | `append()` runs inside the same shared per-message DBAL transaction as every other write in the handler (Group F: "one transaction wraps the whole message on every driver") — the tag-version CAS mechanism (§6.1.5) needs no special isolation level, so this is a non-issue | **Disqualifying, not just a cost** (§3.4 Addendum 2, verified from source): `DoctrineEventStore::commitStatement()` asserts `getTransactionNestingLevel() === 0` and opens its **own** top-level `SERIALIZABLE` transaction per `append()` call — it cannot be called from inside a transaction Ecotone's `DbalTransactionInterceptor` already started for the rest of the message. Adopting it as-is would require either running the *entire* handler transaction at `SERIALIZABLE` (sweeping unrelated writes into the same isolation/retry cost) or a second, separate connection/transaction for the event append alone (breaking the "one transaction, one message" invariant Group F is establishing) | N/A — same as (A), a from-scratch table added to a transaction Ecotone already controls | N/A — same as (A) |
| Complexity / ongoing maintenance | Ecotone owns 100% of the code, but it is bounded, well-understood CRUD-shaped DBAL code following patterns (`DbalTableManager`, §2.1) already established elsewhere in the codebase | Ecotone depends on an external, community-sized project's release cadence, its own bug/security posture, and its adapter package's maturity (the adapter list found, §3.2, includes small/experimental-sounding entries like "UmaDB via gRPC or Rust FFI" alongside the DBAL one — this is not (yet) a widely-adopted, heavily-battle-tested dependency at the scale Ecotone would be trusting) | Trades one unmaintained-risk dependency (today's `prooph/pdo-event-store`, §1.8, §3.7) for a vendored fork Ecotone must now maintain itself indefinitely, while *also* carrying Prooph's existing complexity (persistence strategies, write-lock strategies) forward — worst of both worlds | Same ownership profile as (A); the only complexity delta is the query/index strategy, which is lower complexity code-wise but has worse worst-case query plans (§3.9) that will eventually need workarounds anyway (e.g. adding expression indexes per hot tag key), converging back toward (A)'s design piecemeal |

**Recommendation: (A) — now closed, not just leaning, per the evidence in §3.4 Addendum 2 (open question 10, §10, is
resolved rather than left as a spike).** Reasoning: (B) looked like the most interesting alternative going into this
revision — a spec-conformant PHP DCB implementation already exists, adopting it would buy cross-ecosystem
credibility — but reading its actual adapter source rules it out on **two independent, concrete grounds**, not
just "unaudited risk": (i) its concurrency mechanism (`SERIALIZABLE`+retry on Postgres, an app-level advisory lock
on MySQL, both requiring their own top-level transaction) is structurally incompatible with Ecotone's "one shared
transaction per message" architecture (Group F) without either widening `SERIALIZABLE` to the whole handler or
breaking that invariant; (ii) its schema indexes tags on Postgres only — MySQL/MariaDB get zero tag-query index,
which fails this project's "smart, reliable-by-default... no hidden magic that bites in production" bar outright
for two of Ecotone's three supported production databases. Neither of these is a documentation gap that a future
"deeper audit" would resolve differently — they are read directly from the adapter's source. (C) is rejected
outright: it keeps exactly the coupling and persistence-strategy baggage this whole initiative exists to remove
(§1), for a short-term migration saving that doesn't offset the long-term cost. **(D) is rejected outright, on
all engines including Postgres — a reversal from the round-1 revision of this report, corrected in round 2 (D4)**:
round 1 recommended a hybrid (JSONB+GIN on Postgres, matching Marten and the reference implementation) reasoning
that Postgres could have "one fewer table." That reasoning didn't account for §6.1.5/§6.9's `event_tag_versions`
requirement, which Marten and the reference implementation don't have an equivalent of (Marten's own DCB
mechanism, §3.4 Addendum 1, *does* use a side table — `mt_dcb_tag_version` — for exactly this purpose; it was
already the closer analogy all along). Once that table exists on Postgres regardless, JSONB stops being "instead
of a side table" and becomes "in addition to one," which is pure added write cost for no remaining benefit — see
the Performance row above. §6.1 is corrected to a single, uniform normalised `event_tags` layout on all four
engines. (A) is the only option where Ecotone fully controls the concurrency mechanism's transactional fit,
migration mechanics, and cross-engine parity simultaneously.


---

## 5. Proposed public API

### 5.1 Tags: attribute-first proposal

Recommendation: tags are declared with a `#[Tag]` attribute placed on the **event class**, resolved either from a
literal string or from an event property, plus automatic tags derived from the aggregate the event was raised on
(aggregate type + aggregate id, exactly as `_aggregate_type`/`_aggregate_id` are derived today — see §1.7). This
keeps the "smart by default" principle (§ project design principles): a plain `#[EventSourcingAggregate]` /
`#[Aggregate]` class that raises events gets correct tags with zero extra annotation, matching today's behaviour
where `EVENT_AGGREGATE_TYPE`/`EVENT_AGGREGATE_ID` are populated automatically. `#[Tag]` is additive, for
cross-aggregate / domain tags (the DCB use case).

```php
use Ecotone\Api\Attribute\Tag;

final class TicketReserved
{
    public function __construct(
        #[Tag('ticket')] public readonly string $ticketId,
        #[Tag('event')] public readonly string $eventId,
        public readonly string $customerId,
    ) {
    }
}
```

`#[Tag(string $key)]` on a constructor-promoted property (or plain property) means: "the runtime value of this
property is a tag value under key `$key`". Multiple `#[Tag]` on different properties of the same event are
additive; the same key can appear on properties of different event classes freely (that's the whole point — it's
how two aggregates share a tag). A class-level `#[Tag('key', 'literal-value')]` variant covers static/constant tags
(e.g. tagging every event in a bounded context with `context: 'ticketing'`) without a dedicated property:

```php
#[Tag('context', 'ticketing')]
final class TicketReserved { /* ... */ }
```

Aggregate-derived tags are automatic and need no attribute: any event raised inside a class carrying
`#[EventSourcingAggregate]` (or `#[Aggregate]` with event sourcing behaviour) is tagged with
`aggregateType: <class or #[AggregateType] override>` and `aggregateId: <resolved identifier>` by the same
mechanism that populates `MessageHeaders::EVENT_AGGREGATE_TYPE`/`EVENT_AGGREGATE_ID` today — this is not new
runtime work, it is a renaming of an existing, working mechanism into the tag vocabulary.

Why not a `Tags` value object returned from the event, or a class implementing a `HasTags` interface? Both were
considered:
- A `getTags(): array` method the event class must implement is more flexible (computed tags, cross-property
  logic) but breaks the "plain PHP value object" ergonomics Ecotone events have today (events are typically
  readonly DTOs with no framework-imposed methods) and cannot be read without instantiating the event, which
  matters for tooling (static analysis of what an event class is tagged with, `ecotone:event-store:*` CLI
  commands that need tag info without a live instance).
- Reserve `HasTags`/`getTags()` as an **escape hatch** for cases `#[Tag]` genuinely cannot express (a tag whose
  value is a computed hash of two properties, tags conditional on payload). Recommendation: support both — attribute
  is the default, documented path; interface implementation is checked first if present and skips attribute
  resolution for that event class, with a clear exception if both are used together ambiguously.

### 5.2 Query object: tags ∧ event types, returning global position

**Namespace decision (C9 in the challenge review; previously left implicit/inconsistent).** Group H's rule is
"every attribute users put on their code lives in `<Package>\Api\Attribute\*`; every extension object... in
`<Package>\Api\ExtensionObject\*`; everything else is `@internal`." `#[Tag]` is squarely the first case. `Query`,
`TagCriteria`, `AppendCondition`, `GlobalPosition` and `EventStore` are **not** extension objects — but §6.8 shows
user code (a future decision-model command handler) calling `EventStore::read()`/`append()` and constructing
`Query`/`AppendCondition` directly, exactly like `CommandBus`/`QueryBus` (already listed as `Ecotone\Api\Gateway\*`
in `upgrade-2.0.md` §13). **Decision: these are public API and belong under a package-scoped `Api` umbrella**,
consistent with `upgrade-2.0.md`'s own example (`Ecotone\Dbal\Configuration\DbalConfiguration` →
`Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration`): `Ecotone\EventSourcing\Api\Attribute\Tag`,
`Ecotone\EventSourcing\Api\Query`, `Ecotone\EventSourcing\Api\TagCriteria`, `Ecotone\EventSourcing\Api\AppendCondition`,
`Ecotone\EventSourcing\Api\GlobalPosition`, `Ecotone\EventSourcing\Api\EventStore`. `TagCriteria` and the internal
compilation of `Query` into SQL stay `@internal` implementation detail *of the store*, but the `Query`/`AppendCondition`
*shapes themselves* are the public surface a decision model is written against, so they live in `Api`, not bare
`Ecotone\EventSourcing\*`. This corrects the inconsistency in the snippets below (written before this decision was
made) — treat every `namespace Ecotone\EventSourcing;` comment in this report as shorthand for
`Ecotone\EventSourcing\Api`.

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
namespace Ecotone\EventSourcing\Api;

final class Query
{
    /** @param array<TagCriteria> $criteria one TagCriteria == one AND-ed group of tags+types, OR-ed across the array (dcb.events "query items") */
    private function __construct(private array $criteria) {}

    public static function forTags(array $tags): self { /* [tagKey => tagValue, ...] shorthand for a single AND-of-tags criterion */ }
    public function ofTypes(string ...$eventClass): self { /* narrows the last-added criterion by event type */ }
    public function or(self $other): self { /* OR another TagCriteria group in, for decision models spanning unrelated tag sets */ }
}

final class TagCriteria
{
    public function __construct(
        /** @var array<string,string> */ public readonly array $tags,
        /** @var list<class-string> */ public readonly array $eventTypes = [],
    ) {}
}

final class AppendCondition
{
    /**
     * @param array<string,int> $capturedTagVersions the *specific* tags to protect via §6.1.5's CAS mechanism,
     *        keyed "tagKey:tagValue" => the `event_tag_versions` value captured at read time. Deliberately a
     *        caller-chosen subset of $query's tags, not "every tag $query mentions" — see the note below this
     *        block (D1/D3 in the round-2 challenge review) for why that distinction is load-bearing, not stylistic.
     */
    public function __construct(
        public readonly Query $query,
        public readonly array $capturedTagVersions,
    ) {}

    public static function noConflictExpected(Query $query, array $capturedTagVersions): self { return new self($query, $capturedTagVersions); }
}

final class GlobalPosition
{
    private function __construct(private string $value) {} // opaque, string-serializable — mirrors StreamPage::$lastPosition today
    public static function fromString(string $value): self { /* ... */ }
    public static function start(): self { /* ... */ }
    public function __toString(): string { return $this->value; }
}

interface EventStore
{
    /** Bounded, eagerly-materialised page — see §6 "EventStream contract" note below for why this is not a lazy iterable+position pair */
    public function read(Query $query, ?GlobalPosition $from = null, int $limit = 1000): EventPage;

    /**
     * Current `event_tag_versions` value for each given tag (0/absent for a never-before-appended tag) — the
     * building block for constructing an `AppendCondition`'s `$capturedTagVersions`, §6.1.5.
     * @param array<string,string> $tags ["tagKey" => "tagValue", ...]
     * @return array<string,int> ["tagKey:tagValue" => version, ...]
     */
    public function captureTagVersions(array $tags): array;

    /** @throws ConcurrencyException when any tag in AppendCondition::$capturedTagVersions no longer matches (§6.1.5) */
    public function append(array $events, ?AppendCondition $condition = null): GlobalPosition;
}

final class EventPage
{
    /** @param Event[] $events eagerly materialised, at most $limit long */
    public function __construct(
        public readonly array $events,
        public readonly GlobalPosition $lastPosition, // position of the last event in $events, or the requested $from if $events is empty
        public readonly bool $hasMore,
    ) {}
}
```

**`AppendCondition` fixed to carry what §6.1.5's mechanism actually needs (D1 in the round-2 challenge review;
this section corrects the round-1 draft's `AppendCondition(Query $query, ?GlobalPosition $lastKnownPosition)`,
which cannot drive a per-tag CAS at all).** `GlobalPosition` is a single, store-wide integer — the CAS mechanism
(§6.1.5) enforces the condition per `(tag_key, tag_value)` via `event_tag_versions.version`, which has no
relationship to `global_position` whatsoever (a tag's version is "how many times has *this tag* been touched,"
not "where in the log are we"). `AppendCondition` now carries `array $capturedTagVersions` instead —
`GlobalPosition` moves entirely to `read()`/`EventPage` (pagination/gap-tracking only) and is **removed** from
`AppendCondition`.

Where the captured values come from — two genuinely different cases, not one:
- **Single-aggregate loading (§5.3, §6.9) — no extra round trip.** The captured value for the aggregate's
  `aggregateId` tag is exactly `versionBeforeHandling` (§6.9: either user-supplied via `#[TargetVersion]`, or read
  off the just-loaded aggregate's `#[Version]` property) — a value the aggregate flow **already computes today**
  for an entirely different reason (building the reply message / enriching the saved events' version numbers,
  `AggregateResolver::getVersionBeforeHandling()`, §6.9). No new query is needed for this, the overwhelmingly
  common case.
- **General/multi-tag reads (a future decision model, §6.8) — one extra round trip, and it is race-free by
  construction.** `EventStore::captureTagVersions(array $tags): array` (added to the interface above) issues a
  cheap point-`SELECT` against `event_tag_versions` per referenced tag — the same table §6.1.5's CAS already
  touches, just read instead of written. **Why the extra round trip doesn't introduce a race**: capturing a value
  that goes stale between the read and the later `append()` is exactly what the CAS guard is *for* — a stale
  capture doesn't cause incorrect behaviour, it just causes the guard to fail cleanly (`ConcurrencyException`,
  §6.1.5) and the caller retries. There is no window where a stale capture could cause a false *success*.

**Which tags actually get CAS'd is a deliberate, caller-chosen subset of `$query`'s tags — not automatically "every
tag the query mentions" (D1/D3).** This distinction matters concretely: for aggregate loading, `$query` filters on
*both* `aggregateType` and `aggregateId` (§5.3, needed to scope the *read*), but neither alone is safe to CAS —
`aggregateType` is shared by every instance of that class (CAS-ing it would serialize unrelated aggregates against
each other on one hot row, correctness-preserving but performance-catastrophic), and `aggregateId` alone is only
unique **within** one aggregate type, not across types (two different aggregate classes can share an identifier
value). The tag actually captured/CAS'd is a third, automatically-derived, framework-internal tag —
`_aggregateInstance: "{aggregateType}:{aggregateId}"` — unique per instance regardless of cross-type collisions;
§6.9 has the full reasoning and why `aggregateId` alone was the wrong choice. `AppendCondition::$capturedTagVersions`
is therefore always the *narrowest* tag set that actually needs protecting, decided by the caller
(`EventSourcingRepository::save()` for aggregates, §5.3; a decision model author for §6.8), never derived
automatically from `$query`. §6.1.5 and §6.9 make this
concrete for the shipped 2.0 surface (aggregate loading); §6.1.5 also covers the `Query::or()` case (D3: which
tags from *which* OR-ed branch get protected, and the lock-ordering this implies across multiple CAS'd rows in one
`append()` call).

**`EventStream` contract bug, fixed (C6 in the challenge review).** The original design had
`EventStream(iterable $events, GlobalPosition $lastPosition)` — `$events` was meant to be lazy for large streams,
but `$lastPosition` cannot be known until the (lazy) iterable is fully consumed, so the constructor could never
legally be called with both arguments populated ahead of time. Fixed by reusing exactly the shape
`Ecotone\Projecting\StreamPage` already uses for this same problem (§2.1: `{events: Event[], lastPosition: string}`)
— `EventPage` above **is** that shape (renamed for the store's own package, `bool $hasMore` added since the store,
unlike `StreamPage`, needs to tell a caller whether to page again without a separate count check). `read()` is now
paged, mirroring `StreamSource::load($name, $lastPosition, $count, $partitionKey): StreamPage` (§2.1) exactly — no
new pagination concept for Group B to learn. "Load everything for this aggregate" (§5.3, `EventSourcingRepository`)
is implemented as a small loop over `read()` calls internally, the same way `LazyProophEventStore::LOAD_BATCH_SIZE
= 1000` already batches loads today (§2.2) — not a new capability, a like-for-like replacement.

`append()` with `$condition = null` is unconditional append (equivalent to today's `appendTo()` with no version
check — used for pure event-sourcing where the aggregate already re-derived its own version-based condition
internally, or for fire-and-forget domain events with no consistency requirement). This mirrors dcb.events'
`AppendCondition` (§3.2) and EventStoreDB's optional expected-revision append (§3.5) rather than forcing every
caller to pass a condition.

### 5.3 Loading an aggregate — same shape as today, expressed as a tag query

Note: `Ecotone\Modelling\EventStream` (§2.1 — `createWith(int $aggregateVersion, array $events)`) already exists
and is a *different* class from the store-level `EventPage` introduced in §5.2 (the rename in §5.2 was chosen
specifically to avoid this collision, not only to fix the lazy-position bug). `EventSourcingRepository::findBy()`
still returns `Ecotone\Modelling\EventStream` to its callers — internally it now builds that value from one or
more `EventPage`s read from the store, rather than from a single `EventStore::load()` call:

```php
// EventSourcingRepository::findBy(), rewritten (illustrative, not the literal diff)
public function findBy(string $aggregateClassName, array $identifiers, int $fromVersion = 1): \Ecotone\Modelling\EventStream
{
    $aggregateId = reset($identifiers);
    $tags = ['aggregateType' => $this->getAggregateType($aggregateClassName), 'aggregateId' => (string) $aggregateId];
    $query = Query::forTags($tags);

    [$events, $version] = $this->eventStore->readAggregateEvents($query, afterTagVersion: $fromVersion - 1);

    return \Ecotone\Modelling\EventStream::createWith($version, $events);
}

public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
{
    $aggregateId = (string) reset($identifiers);
    $aggregateType = $this->getAggregateType($aggregateClassName);

    // $query scopes the READ-side conflict check via the documented, queryable tags; $capturedTagVersions is
    // deliberately narrower and uses the internal per-instance tag, never the shared aggregateType tag and never
    // aggregateId alone (which collides across aggregate types) — §5.2's "which tags get CAS'd" note, §6.9
    $query = Query::forTags(['aggregateType' => $aggregateType, 'aggregateId' => $aggregateId]);
    $capturedTagVersions = ["_aggregateInstance:{$aggregateType}:{$aggregateId}" => $versionBeforeHandling];

    $this->eventStore->append($events, AppendCondition::noConflictExpected($query, $capturedTagVersions));
}
```

`readAggregateEvents()`/the `expectedTagVersion` parameter are a small, *aggregate-loading-specific* convenience
on top of the generic `read()`/`append()` API — not part of the general DCB `Query` language, and not exposed to
decision-model code (§6.8). Why aggregate loading needs this, and exactly what it does, is §6.9 (`#[Version]`
design) — summarised here: the per-tag CAS counter §6.1.5 introduces for conditional append (`event_tag_versions`)
is *also* the authoritative, O(1)-to-read "current version of this aggregate," so `EVENT_AGGREGATE_VERSION` and
`#[Version]`/`#[TargetVersion]` keep working unchanged from the outside, backed by a real counter rather than by
counting loaded events (§6.9 explains why counting is wrong the moment a query is event-type-filtered or
snapshot-bounded — exactly the flaw in this report's original text here, which said the version was "computed at
read time (count of matching events...)" — that line is corrected by §6.9, not repeated here).

`#[FromStream]` stays optional on aggregates, confirming the wiki note ("do not require `#[FromStream]` for
Aggregates"): when absent, the aggregate class name + `#[AggregateType]` override (if any) is the default tag set,
exactly mirroring how `AggregateTypeMapping`/`AggregateStreamMapping` already default to the class name today
(`EventSourcingRepository.php:89-101,103-110`).

### 5.4 Extension object — `EventSourcingConfiguration`

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function eventSourcing(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults()
            ->withEventStreamTableName('event_log')
            ->withSnapshotsFor(Ticket::class, thresholdTrigger: 100);
    }
}
```

Only one physical layout exists by default (the current "partition"/global-log physical table); the four-strategy
`with*StreamPersistenceStrategy()` surface (§1.2) is removed entirely — there is no consistency-boundary concept
left to select via a strategy enum, because DCB replaces "which physical layout expresses my consistency boundary"
with "which tag query expresses my consistency boundary", decided per read/append call, not per store.
`withCustomPersistenceStrategy()` and its `Prooph\EventStore\Pdo\PersistenceStrategy` type both disappear —
partitioning becomes a **physical** concern only (§6.6: table partitioning by e.g. `aggregate_type` or hash of
tag, transparent to the query layer), configured (if at all) via a dedicated `withPhysicalPartitioning(...)`
call that does not change query semantics, addressing the goal statement's "partitioning as a physical concern,
not a consistency concern" directly.

### 5.5 CLI commands

Building on the already-shipped `Ecotone\Dbal\Database\DbalTableManager`/`DatabaseSetupManager` pattern (§2.3):

| Command | Purpose |
|---|---|
| `ecotone:migration:database:setup --feature=event_log` | Create the event log + tag tables (existing generic command, new `DbalTableManager` registered for the feature) |
| `ecotone:event-store:migrate-aggregate-streams` | One-time migration from `aggregate`-strategy per-id tables into the global log (§8) |
| `ecotone:event-store:verify-gaps` | Diagnostic: scan for any position still open past the configured safety window, for operational alerting |


---

## 6. Internals

### 6.1 Table layout — uniform normalised `event_tags` side table on all four engines (reverted from round 1's hybrid — D4)

**This section reverses round 1's revision, which itself had reversed the original design — settled here (D4 in
the round-2 challenge review).** Round 1 moved Postgres to `tags JSONB` + GIN, reasoning that Marten and the real
`bwaidelich/dcb-eventstore-doctrine` reference implementation (§3.4 Addendum 2) both use JSONB successfully on
Postgres. That reasoning didn't account for what §6.1.4/§6.1.5/§6.9 (introduced later in round 1, without being
checked against §6.1.1's DDL) actually require: a normalised, per-`(tag_key, tag_value)` **`event_tags`** side
table carrying a `tag_local_seq` column, needed on **every** engine for the CAS/versioning mechanism, independent
of how tags are *queried*. Once that table has to exist and be written on every append regardless, a Postgres-only
`tags JSONB` column stops being "instead of a side table" and becomes "*in addition to* one" — pure extra write
cost (a second physical representation of the same facts, plus GIN maintenance) with no remaining benefit, since
the side table already serves every query `read()` needs (§6.2's portable query works identically on all four
engines, including Postgres). §4's comparison table is updated to match: this is a genuine reversal, not a
wording fix, and it is why Marten's own DCB feature (§3.4 Addendum 1, `mt_dcb_tag_version` — a side table) turns
out to be the closer analogy all along, not Marten's or `bwaidelich`'s plain JSONB-tags-column choice, which
neither of those systems needed a version-counter side table underneath. **Recommendation: one physical layout —
`event_log` + `event_tags` + `event_tag_versions` — identically shaped on Postgres/MySQL/MariaDB/SQLite**, with
only per-engine SQL-dialect differences (types, collation syntax).

#### 6.1.1 PostgreSQL

```sql
CREATE TABLE event_log (
    global_position   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id          UUID NOT NULL UNIQUE,          -- uuid7, §6.3
    event_type        VARCHAR(255) NOT NULL,
    payload           JSONB NOT NULL,
    metadata          JSONB NOT NULL,
    recorded_at       TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    transaction_id    XID8 NOT NULL DEFAULT pg_current_xact_id() -- for the visibility-based gap technique, §6.5
);
CREATE INDEX ix_event_log_type ON event_log (event_type);
CREATE INDEX ix_event_log_txid ON event_log (transaction_id);

CREATE TABLE event_tags (
    global_position   BIGINT NOT NULL,
    tag_key           VARCHAR(100) NOT NULL,
    tag_value         VARCHAR(255) NOT NULL,
    tag_local_seq     BIGINT NOT NULL,       -- per-(tag_key,tag_value) sequence at append time, §6.1.5/§6.9
    PRIMARY KEY (tag_key, tag_value, global_position)
    -- no FK to event_log — see §6.6 (partition-detach conflict)
);
CREATE INDEX ix_event_tags_position ON event_tags (global_position);
```

`WHERE tag_key = 'aggregateId' AND tag_value = '42' ORDER BY global_position` (or `tag_local_seq`, for
post-snapshot loads, §6.10) is a pure index range scan into `event_tags`'s primary key — no JSONB, no GIN, and
the same query shape as MySQL/MariaDB (§6.2), one code path for all engines. `GENERATED ALWAYS AS IDENTITY` (not
`BIGSERIAL`) is used deliberately — it forbids explicit inserts into `global_position`, closing off a class of
migration-script bugs where a bulk loader accidentally supplies its own numbers and corrupts monotonicity. Tags
remain single scalar `(key, value)` facts (not nested structured data), consistent with the dcb.events/Marten/
`bwaidelich` convention (§3.2/§3.4) even though the physical *storage* shape (normalised row vs. JSON array
element) no longer matches those systems' choice — the concept (a flat set of key/value facts per event) is the
same; only Ecotone's extra `tag_local_seq` requirement changes which physical shape is worth paying for.

#### 6.1.2 MySQL 8 / MariaDB

MySQL 8.0.17+ and MariaDB 10.6+ support multi-valued functional indexes on `CAST(... AS ... ARRAY)`, the closest
analogue to Postgres GIN-on-JSONB — but §3.10 already found these don't support the composite range scan a
tag-then-position lookup needs, and §3.4 Addendum 2 confirms the most prominent real PHP DCB adapter doesn't even
attempt to index `JSON_CONTAINS` on this engine (full scan). MySQL/MariaDB were never going to use anything other
than a normalised side table (§3.10's conclusion, unchanged since round 1); §6.1's revision (D4) is that Postgres
now uses the identical shape, not a different one:

```sql
CREATE TABLE event_log (
    global_position   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id          BINARY(16) NOT NULL,           -- uuid7 stored as packed binary, not char(36)
    event_type        VARCHAR(255) NOT NULL,
    payload           JSON NOT NULL,
    metadata          JSON NOT NULL,
    recorded_at       TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY ux_event_log_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE event_tags (
    global_position   BIGINT UNSIGNED NOT NULL,
    tag_key           VARCHAR(100) NOT NULL,
    tag_value         VARCHAR(255) NOT NULL,
    tag_local_seq     BIGINT UNSIGNED NOT NULL,       -- per-(tag_key,tag_value) sequence at append time, §6.1.5/§6.9
    PRIMARY KEY (tag_key, tag_value, global_position)
    -- no FK to event_log — see §6.6 for why (partition-detach conflict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
```

**Collation fix (C8 in the challenge review — a real bug in the original DDL).** The original DDL specified
`DEFAULT CHARSET=utf8mb4` with no explicit collation, which resolves to MySQL 8's default,
`utf8mb4_0900_ai_ci` — **accent-insensitive, case-insensitive**. Under that collation, `tag_value = 'Ticket-1'`
and `tag_value = 'ticket-1'` (or `'café'` vs `'cafe'`) compare **equal**, which would silently merge two distinct
aggregate ids' event streams the first time two identifiers differed only by case or accent — a correctness bug,
not a performance nit. Fixed by specifying `COLLATE=utf8mb4_0900_bin` (MySQL 8) explicitly on both tables — byte-exact
comparison, matching what Prooph's own existing MariaDB/MySQL table managers already do (`COLLATE=utf8_bin`,
confirmed in the current codebase's `EventStreamTableManager`, §2.1) and what §6.1.3 needs to be consistent with
for cross-engine tag equality to mean the same thing everywhere. Postgres's plain `text`/`varchar` comparison is
already byte-exact/case-sensitive by default (`C`-like collation for `=`) — no equivalent fix needed there, but
worth stating explicitly rather than leaving it implicit.

MySQL/MariaDB `AUTO_INCREMENT` under InnoDB with the default `innodb_autoinc_lock_mode=2` (interleaved) allocates
numbers without holding a table-level lock across the whole transaction, which is good for throughput but means
gaps from rolled-back transactions are common and — critically — MySQL/MariaDB have **no equivalent** of Postgres's
`txid_current_snapshot()` to detect "an earlier-numbered transaction is still in flight" (§6.5, §4 costs this out
per-engine).

#### 6.1.3 SQLite (tests only)

SQLite has `INTEGER PRIMARY KEY` as a rowid alias (monotonic, gapless only in the single-writer case — true for
Ecotone's test process) and JSON1 for payload/metadata; a plain `event_tags` side table with a composite index
works identically to MySQL's (or a `tags` TEXT column with correlated `JSON_EACH` matching, as §3.4 Addendum 2's
reference adapter does — either is fine for a test-only path with no performance requirement; recommend the same
side-table shape as MySQL/MariaDB for one fewer code path). Specify `COLLATE BINARY` explicitly on `tag_key`/
`tag_value` (SQLite's own default is `BINARY`, so this is redundant but should still be written explicitly per
the collation lesson above — cheap insurance against a future default change). SQLite has no concept of
concurrent-transaction visibility windows at all (it serializes writers), so the entire gap-detection problem
(§6.5) does not exist there — which is exactly why the in-memory/SQLite path must not be trusted to validate
gap-handling logic; that must be tested against a real Postgres/MySQL container (§7 "tests" row).

#### 6.1.4 `event_tag_versions` — the concurrency/versioning table, all engines identically

```sql
-- Postgres
CREATE TABLE event_tag_versions (
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_key, tag_value)
);

-- MySQL / MariaDB — identical shape, explicit binary collation (same reasoning as 6.1.2)
CREATE TABLE event_tag_versions (
    tag_key    VARCHAR(100) NOT NULL,
    tag_value  VARCHAR(255) NOT NULL,
    version    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tag_key, tag_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
```

One row per distinct tag *value* ever appended (not per event) — small relative to `event_log`/`event_tags`, hot
on write (every append touches every tag it carries), read-light. What it is for and how it is used is §6.1.5
(conditional append) and §6.9 (`#[Version]`/`#[TargetVersion]`) — introduced here because it is schema, not just
mechanism.

**Growth and pruning (D6, new in this revision).** For an `aggregateId` tag specifically, this is one row **per
aggregate instance ever created**, and it is never deleted by anything in this design — event-sourced aggregates
are essentially never physically deleted (a "deletion" is normally recorded as an event, not a row removal), so a
decommissioned aggregate's `event_tag_versions` row is retained forever, occupying a small, fixed amount of space
indefinitely. At scale this is still modest in absolute terms — the row is narrow (`tag_key`, `tag_value`,
`version`, roughly 50-100 bytes with index overhead), so even 100 million distinct aggregate instances is a
single-digit-gigabyte table, far smaller than `event_log`/`event_tags` (which grow with *events*, not instances).
No pruning mechanism is proposed for 2.0 — this is an explicit, accepted characteristic, not an oversight. A
manual cleanup path (deleting rows for tags provably unreferenced by any live aggregate, e.g. as part of
decommissioning a multi-tenant customer) is a plausible follow-up if a deployment's scale ever makes it worth the
operational complexity, but is out of scope here.

### 6.1.5 Atomic conditional append — the mechanism `AppendCondition` actually runs on (C1, the blocker)

**The gap this closes.** This report's earlier drafts defined `append($events, AppendCondition{query,
lastKnownPosition})` and described enforcement as "a re-check at commit time... throws `ConcurrencyException`" —
that is a restatement of the goal, not a mechanism. Under plain `READ COMMITTED` (Postgres's default; MySQL/InnoDB
defaults to `REPEATABLE READ`), a bare `SELECT` re-check inside the writer's own transaction cannot see another
concurrent transaction's not-yet-committed insert — two writers can both run the check, both see nothing, and
both commit, silently violating the exact consistency boundary DCB exists to guarantee. This section replaces the
hand-wave with a concrete, per-engine, cited mechanism.

**Options considered:**

1. **`SERIALIZABLE` isolation + retry on `40001`.** Correct (Postgres's Serializable Snapshot Isolation genuinely
   detects this exact write-skew pattern — [PostgreSQL docs, Transaction
   Isolation](https://www.postgresql.org/docs/current/transaction-iso.html): *"applications using this level must
   be prepared to retry transactions due to serialization failures"*) and is what the real reference implementation
   uses on Postgres (§3.4 Addendum 2). Real costs, not hypothetical: PostgreSQL's own docs note *"the monitoring
   of read/write dependencies has a cost, as does the restart of transactions which are terminated with a
   serialization failure"* — SSI tracks the *entire* transaction's read set, not just the tags in the
   `AppendCondition`, so unrelated reads/writes in the same handler transaction inflate the false-positive-abort
   rate as concurrency rises. Worse for Ecotone specifically: it requires the **whole** transaction to run at
   `SERIALIZABLE` from its first statement (`SET TRANSACTION ISOLATION LEVEL` must be set before any query), and
   the reference implementation's own code asserts `getTransactionNestingLevel() === 0` — it demands its **own**
   top-level transaction, which conflicts directly with Group F's "one shared transaction wraps the whole message"
   architecture (§4's new "Transactional fit" row). Rejected as the default for this reason specifically, not a
   general performance objection.
2. **`INSERT ... SELECT ... WHERE NOT EXISTS (...)`, checking affected rows.** This is exactly what the reference
   implementation's Postgres path does (§3.4 Addendum 2) *underneath* its `SERIALIZABLE` wrapper — and that
   wrapper is load-bearing: at plain `READ COMMITTED`, `WHERE NOT EXISTS` is a classic phantom-read hazard (two
   concurrent statements can each evaluate `NOT EXISTS` true against data as it stood at statement-start and both
   insert) unless *something else* forces serialization. On its own, without `SERIALIZABLE`, this option is
   unsafe — it inherits option 1's transaction-boundary cost to actually work.
3. **A unique index expressing the constraint.** Doesn't generalise: a unique constraint needs a fixed column
   shape, but `AppendCondition.query` is an arbitrary tag ∧ type predicate. The one case this *does* work for —
   "one event per (aggregateType, aggregateId, eventType)" — is just today's single-aggregate optimistic lock
   again (§1.3), not a DCB-general mechanism.
4. **Postgres advisory locks keyed on a hash of the condition's tag set** (`pg_advisory_xact_lock(hashtext(...))`
   per tag, sorted before acquisition to avoid deadlocks, held to `COMMIT`). Correct and composable inside an
   existing transaction (no `SERIALIZABLE` needed) — a legitimate alternative to option 6 below, but requires the
   application to manage lock ordering and hash-collision risk itself; option 6 gets the same correctness from a
   single ordinary SQL statement with the database enforcing the compare-and-swap, so it is preferred when
   available. Worth keeping in mind as a fallback if a future engine lacks a working upsert-with-guard primitive.
5. **`SELECT ... FOR UPDATE` on the tag rows, then insert.** **Confirmed broken on Postgres, exactly as the
   challenge describes**: `FOR UPDATE` takes row locks on *existing* rows only. Postgres has no gap-lock/next-key
   concept outside `SERIALIZABLE`'s predicate locking — a `FOR UPDATE` scan that matches zero rows takes *no*
   lock at all, so it cannot block a concurrent transaction from inserting a brand-new row into the "gap" the
   query would have matched. This is the textbook phantom-read failure mode and this design must not rely on it
   on Postgres.
6. **`event_tag_versions` UPSERT-with-guard (Marten's mechanism, §3.4 Addendum 1) — recommended.**
   `INSERT INTO event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1) ON CONFLICT (tag_key,
   tag_value) DO UPDATE SET version = event_tag_versions.version + 1 WHERE event_tag_versions.version = :captured`
   (Postgres) / `INSERT INTO event_tag_versions (tag_key, tag_value, version) VALUES (:k, :v, 1) ON DUPLICATE KEY
   UPDATE version = IF(version = :captured, version + 1, version)` (MySQL/MariaDB, affected-rows semantics
   confirmed exact in §3.4's addendum) / `INSERT ... ON CONFLICT(tag_key, tag_value) DO UPDATE SET version =
   version + 1 WHERE version = :captured` (SQLite, supported since 3.24). **This works at plain `READ COMMITTED`/
   `REPEATABLE READ` on every engine, with no elevated isolation level and no advisory lock**, because an
   `UPSERT`/`ON DUPLICATE KEY UPDATE` statement is documented, standard behaviour on all three engines to take a
   real row lock and evaluate against the *current committed* row, not the transaction's MVCC snapshot — this is
   precisely why these statements are the standard "compare-and-swap" primitive for exactly this problem, on every
   engine that has one. Mechanically: T1 executes the UPSERT for tag `(aggregateId,42)` captured at version 5 —
   InnoDB/Postgres take an exclusive row lock on that `(tag_key,tag_value)` row (or insert it if absent) and
   commit. T2, concurrently attempting the same UPSERT with the same captured version 5, **blocks on the row
   lock** until T1 commits or rolls back; once unblocked, its `WHERE version = 5` guard re-evaluates against
   fresh data — T1 already bumped it to 6, so the guard fails, the statement affects 0 rows, and the store raises
   `ConcurrencyException`. This is genuinely pessimistic at the single-row level (T2 physically waits, it doesn't
   race) while remaining optimistic in spirit from the caller's point of view (no explicit lock-acquisition step
   in application code).

**Recommendation: option 6, uniformly across Postgres/MySQL/MariaDB/SQLite.** `AppendCondition::$capturedTagVersions`
(§5.2/D1) with N entries becomes N UPSERT statements (each cheap — a single-row point lookup/lock, not a table or
predicate scan) inside the *same* transaction as the rest of the message handler — no isolation-level change, no
dedicated top-level transaction, composes cleanly with Group F's one-transaction-per-message model, and is
strictly cheaper than option 1's whole-transaction SSI tracking cost since only the tags actually referenced are
touched. Cost model: write amplification is O(N) — for a plain aggregate load this is **N=1** (the internal
`_aggregateInstance` tag only, §5.2/D1's "which tags get CAS'd" note and §6.9 — neither `aggregateType` nor
`aggregateId` alone is captured; CAS-ing the shared `aggregateType` tag would serialize every instance of an
aggregate class against every other instance, and `aggregateId` alone collides across aggregate types, which is
why §6.9 introduces the compound internal tag specifically for this) — not O(transaction's total
read/write footprint) the way `SERIALIZABLE` is. **What a user sees when it fails**: the same
`Ecotone\Messaging\Support\ConcurrencyException` thrown today (§1.3, §2.5) — no new exception type, and it
composes with `InstantRetryConfiguration`'s existing retry mechanism (Group F: async retries enabled by default,
3 attempts) rather than requiring bespoke retry code. A domain tag with very high write fan-in deliberately
included in a decision-model `AppendCondition`'s captured set (§6.8, follow-up scope) becomes a write bottleneck
under this scheme — an honest cost inherent to *any* correct mechanism here (today's single
`UNIQUE(aggregate_id,aggregate_version)` constraint already serializes writes per aggregate id the same way), and
exactly why DCB's own guidance is to keep consistency boundaries — and therefore which tags are ever placed in
`$capturedTagVersions` — as narrow as possible; §5.2/D1 makes this the caller's explicit choice for exactly this
reason.

**D2 (round-2 challenge review): the mechanism is a conservative over-approximation of DCB's exact conflict
semantics — stated explicitly, not left implicit.** `event_tag_versions` is keyed `(tag_key, tag_value)` only —
**any** event carrying a captured tag bumps its version, including one that would not have matched the
condition's `Query::ofTypes(...)` filter. dcb.events' own semantics are narrower: "conflict only if an event
matching the query — tags **and** types — appeared" (§3.2). This mechanism therefore sometimes raises
`ConcurrencyException` for a write DCB itself would have allowed — it never *misses* a real conflict (safe), but
it can manufacture a spurious one (a false positive, not a false negative). Concretely: a `customer:42` tag
touched by `CustomerBlocked`, `CustomerAddressChanged`, and `CustomerEmailVerified` events — a decision model
conditioning only on `CustomerBlocked` (`Query::forTags(['customer'=>'42'])->ofTypes(CustomerBlocked::class)`)
would still see its append fail if an unrelated `CustomerAddressChanged` was written to the same tag in between,
even though DCB's own spec would allow that append to proceed. **Alternative considered**: key
`event_tag_versions` by `(tag_key, tag_value, event_type)` instead, CAS-ing only the specific type(s) named in the
condition. This is exactly precise (no false positives) but costs more on every append that carries a type-scoped
tag: a query with *no* `ofTypes()` filter (the common single-aggregate-load case, which intentionally wants "any
event for this aggregate" semantics, §6.9) would have to enumerate and CAS *every distinct event type the
aggregate can ever raise* to stay correct — turning a 1-row CAS into potentially a dozen, for the case that
matters most in 2.0. **Recommendation: keep the coarser `(tag_key, tag_value)` keying.** For 2.0's actual shipped
surface — single-aggregate loading (§5.3/§6.9) — this is not an approximation at all: an aggregate's version is
*supposed* to increment on every one of its events regardless of type, so coarse keying matches the correct
semantics exactly, at zero extra cost. The over-approximation only bites for the not-yet-shipped decision-model
layer (§6.8) using a type-narrowed condition on a tag with multiple event types — quantified: it bites in
proportion to (event-type diversity on the tag) × (write frequency on the tag) × (how narrow the condition's
`ofTypes()` filter is relative to that diversity); a tag touched by one dominant event type and a handful of rare
ones sees this rarely, a tag touched by many roughly-equal-frequency event types under a single-type filter sees
it often. The user-visible effect is bounded and self-healing either way: a spurious `ConcurrencyException`
triggers exactly the same `InstantRetryConfiguration`-driven retry as a real conflict (above) — extra retries
under contention, not a correctness bug — so this is deferred to when the decision-model layer actually ships
(§6.8, §10) rather than paid for now.

**D3 (round-2 challenge review): `Query::or()` and lock ordering — which tags get CAS'd, and in what order.**
With OR-ed `TagCriteria` (`Query::forTags([...])->or(Query::forTags([...])->ofTypes(...))`), §5.2/D1 already
establishes that `$capturedTagVersions` is a caller-chosen subset, not automatically derived from `$query` — so
the ambiguity the challenge raises is resolved by construction: the caller must explicitly list every tag it
wants protected across *every* OR-ed branch it cares about, because CAS-ing only a subset would silently let a
conflict on the un-protected branch through undetected — a correctness bug, not a performance trade, so there is
only one safe choice once a branch's tag is included in `$capturedTagVersions` at all. What §6.1.5 owes on top of
that (and did not have until this revision): **deterministic lock ordering.** A single `append()` call issuing N
UPSERTs against N different `event_tag_versions` rows has exactly the multi-lock deadlock exposure the rejected
advisory-lock option (4, above) was flagged for — this applies equally to option 6, and round 1 failed to carry
the same fix over. Two concurrent `append()` calls referencing overlapping tag sets in different program order
(writer A: tags `[X, Y]`; writer B: tags `[Y, X]`) can deadlock: A locks X and waits on Y, B locks Y and waits on
X. **Fix: sort `$capturedTagVersions`'s keys lexicographically (on the `"tagKey:tagValue"` string) before issuing
the UPSERT statements, for every `append()` call, unconditionally.** This makes lock acquisition order identical
across all callers regardless of the order tags were supplied in, eliminating this deadlock class entirely rather
than merely mitigating it. Postgres and InnoDB both still detect and abort genuine deadlocks automatically as a
safety net (existing retry path handles this, same as any other transient DB error) — sorting removes the
*routine* deadlock-abort-retry churn under contention, it is not the only thing standing between this design and
data corruption.

### 6.2 Query execution shape

**One query shape, identical on Postgres/MySQL/MariaDB/SQLite (§6.1's uniform layout, D4) — `INTERSECT` removed
(C5, round 1)**:
```sql
SELECT e.global_position, e.event_id, e.event_type, e.payload, e.metadata, e.recorded_at
FROM event_log e
WHERE e.global_position IN (
    SELECT t.global_position
    FROM event_tags t
    WHERE (t.tag_key, t.tag_value) IN (('aggregateType','Ticket'), ('aggregateId','42'))
    GROUP BY t.global_position
    HAVING COUNT(DISTINCT t.tag_key) = 2
)
AND e.event_type = 'TicketReserved'
AND e.global_position > :fromPosition
ORDER BY e.global_position
LIMIT :limit
```

**`INTERSECT` portability, corrected (C5 in the challenge review).** The original query shape used `INTERSECT`,
which MySQL only gained in **8.0.31** (November 2022) and MariaDB in **10.3**. Neither `upgrade-2.0.md` nor
`composer.json`/CI documents a MySQL or MariaDB minimum version today (`rg -n "Minimum requirements"
upgrade-2.0.md` → only PHP/Symfony/Laravel/DBAL floors are stated; `docker-compose.yml`/`.github/workflows/test-monorepo.yml`
run `mysql:8.0` and `mariadb:11.4`, both comfortably past the `INTERSECT` floor, but a floating `:8.0` tag and
"what CI happens to run" are not a documented minimum — flagged as a new open question, §10). Rather than making
this query's correctness depend on a version floor Ecotone has never committed to, the `GROUP BY ... HAVING
COUNT(DISTINCT tag_key) = :n` formulation above is portable back to any MySQL 5.7+/old MariaDB and avoids the
question entirely. **Re-verified complexity claim**: this is still an index range scan into `event_tags` per
distinct `(tag_key,tag_value)` pair inside the `IN` subquery, followed by a hash/sort aggregate on
`global_position` to apply the `HAVING` filter — the same execution shape a query planner builds for `INTERSECT`
internally, so "same complexity class as today's lookup" still holds; the SQL text changed, the plan shape did
not. This is now the query Postgres runs too (§6.1's uniform layout, D4) — Postgres's native `INTERSECT` support
has no version-floor problem the way MySQL/MariaDB's does, so a Postgres-specific optimisation using it instead of
the `IN`/`HAVING` form remains available later as a pure engine-specific tuning, without being required for
correctness or for the "one query shape" default this section ships with.

### 6.3 UUID v7 event ids

UUID v7 (RFC 9562) embeds a 48-bit millisecond Unix timestamp in the high bits followed by random low bits, so
ids sort approximately chronologically while remaining globally unique without a coordinator. Replacing uuid4
(§1.4) with uuid7 for `event_id`:
- makes `event_id` a legitimate secondary sort/covering-index key (`ORDER BY event_id` roughly tracks insertion
  order — useful for debugging/audit tooling even though `global_position` remains the authoritative order),
- avoids the random-insert B-tree fragmentation uuid4 causes on the `UNIQUE(event_id)` index at scale,
- is **not** a substitute for `global_position`: uuid7's millisecond resolution and multi-writer clock skew make
  it unsuitable as the ordering key for gap detection or `AppendCondition` — `global_position` (a real DB
  sequence) remains the only source of total, gap-detectable order. This mirrors the relationship Marten
  maintains between its Postgres-generated `mt_events.seq_id` (authoritative order) and its ULID/GUID stream ids
  (identity only) — see §3.4.
- `ramsey/uuid` (already a dependency, `Ramsey\Uuid\Uuid`) added `uuid7()` in 4.7.0; the root `composer.json:152`
  and `packages/Ecotone/composer.json:65` both constrain it as `^4.0`, which is satisfied by any 4.7+ install but
  does **not guarantee** an existing lockfile has resolved to ≥4.7 — bump the constraint to `^4.7` explicitly as
  part of implementation task 8 (§9) rather than relying on the existing `^4.0` floor.

**Correction verified while implementing this revision**: the claim "every event id minted by the write path is
uuid4" (§1.4) is narrower than it reads. `SaveAggregateServiceTemplate::buildEcotoneEvents()`
(`packages/Ecotone/src/Modelling/AggregateFlow/SaveAggregate/SaveAggregateServiceTemplate.php`) — the code path
every `#[EventSourcingAggregate]`/`#[Aggregate]` command handler goes through before an event ever reaches the
store — already does `$eventMetadata[MessageHeaders::MESSAGE_ID] ??= Uuid::v7()->toRfc4122()`, using **Symfony's**
`Symfony\Component\Uid\Uuid::v7()` (a second, independent UUID library already a dependency, distinct from
`ramsey/uuid`). `EcotoneEventStoreProophWrapper.php:76`'s `Uuid::uuid4()` (ramsey) is a **fallback**, only reached
when `MessageHeaders::MESSAGE_ID` is absent from the event's metadata — which, for aggregate-originated events, it
no longer is. So aggregate-sourced events are *already* uuid7-identified today; task 8 (§9) is narrower than
originally scoped — it only needs to change the fallback path (`EcotoneEventStoreProophWrapper`'s ramsey-uuid4
default, and the equivalent in-memory-adapter default) for the minority of events appended without a pre-assigned
`MESSAGE_ID` (raw non-aggregate `EventStore::append()` calls, e.g. from a future decision model, §6.8), not the
whole write path. Two UUID libraries doing overlapping work (`ramsey/uuid` for the store's own fallback,
`symfony/uid` for the aggregate flow) is itself worth a maintainer note (§10) — not a blocker, but worth
consolidating on one during this rewrite rather than carrying two accidentally.

### 6.4 UUID v7 vs global position — division of labour

| Concern | Field | Why |
|---|---|---|
| Total order / gap detection / `AppendCondition` | `global_position` (DB sequence) | Only the DB can allocate a monotonic, gap-detectable number under concurrent transactions |
| Event identity / idempotency key / external correlation | `event_id` (uuid7) | Globally unique without a DB round-trip; caller can mint it before the transaction opens |
| Rough chronological sort for tooling/debugging | `event_id` (uuid7) | Free side-effect of the timestamp prefix; not authoritative |

### 6.5 Gap detection under concurrent transactions — the hard part

The problem: `global_position` is allocated by `GENERATED ALWAYS AS IDENTITY`/`AUTO_INCREMENT` **at INSERT time**,
before COMMIT. Two concurrent transactions T1 (slow, allocates position 100) and T2 (fast, allocates position 101,
commits first) mean a reader polling `WHERE global_position > :last ORDER BY global_position` at that instant sees
101 but not 100 — then, once T1 commits, position 100 becomes visible *behind* the reader's already-advanced
cursor. If the reader naively advances its cursor to 101, it permanently skips event 100 the moment T1 commits
after it has moved on. This is the exact production failure mode issue #438 cites Prooph's time-window retry as
handling poorly (§1.5, §3.7).

**Recommendation: Postgres — `pg_snapshot`-based low-water-mark, not wall-clock retry. This section corrects a
direction error in an earlier draft (C2 in the challenge review) — the query below is the fix, not the original.**
An earlier draft of this section computed `min(global_position) - 1 ... WHERE transaction_id >=
pg_snapshot_xmin(...)` — that is backwards and cannot work: a row written by a transaction that has **not yet
committed is invisible to the reader's snapshot at all**, full stop, regardless of any `transaction_id` filter —
you cannot `SELECT` it, so a query trying to find "the position such-and-such in-flight transaction will use" is
searching for rows that, by definition, are not there yet. §3.6 already had the correct direction (`WHERE
transaction_id < pg_snapshot_xmin(...)`, sourced from Dudycz's outbox article) — §6.5 simply failed to apply it
consistently. Corrected version:

```sql
SELECT global_position, event_id, event_type, payload, metadata, recorded_at
FROM event_log
WHERE global_position > :lastDeliveredPosition
  AND transaction_id < (SELECT pg_snapshot_xmin(pg_current_snapshot()))
ORDER BY global_position
LIMIT :limit
```

The reader advances its cursor only to the highest `global_position` actually returned by this query, never
further. Why this direction is correct, worked through concretely: suppose T1 (txid 4998, slow) is still writing
`global_position=100` when T2 (txid 5000, fast) commits `global_position=101` first. At that instant,
`pg_snapshot_xmin()` returns 4998 (T1 is the oldest still-active transaction). The query's filter `transaction_id
< 4998` excludes row 101 (txid 5000 ≥ 4998) even though row 101 is already visible/committed — the filter
deliberately *withholds* it, because we cannot yet prove nothing lower than 101 is still coming. Once T1 resolves
(commits or rolls back) and a later poll computes a new, higher `xmin` (say 5001, if T1 was the sole blocker),
*both* rows now pass `transaction_id < 5001` and are delivered together, in `global_position` order — no reordering,
no skip. If T1 instead rolled back, `global_position=100` never exists (rolled-back inserts are never visible to
anyone), and row 101 is simply delivered once xmin clears T1 — the gap is closed the instant it is *provably*
permanent, with no timeout, no guessing, and no separate "re-check the missing number" step, because the filter
never let the reader claim a false high-water mark in the first place. This is the same underlying idea as
Marten's high-water mark (§3.4) — never advance past a position that might still be preceded by an unresolved
writer — implemented as a single filtered `SELECT` rather than a background reconciliation thread.

**Does `GapAwarePosition` still exist under this scheme? No — on Postgres it collapses to a plain integer, and
this is a real, resolved contradiction with §6.7, not a detail.** An earlier draft of §6.7 claimed
`GapAwarePosition` (§1.6/§2.1: `position:int` + `gaps:list<int>`) is "kept as-is," which contradicts the mechanism
above directly: the xmin filter guarantees `event_log` rows are delivered to a Postgres reader in strictly
contiguous `global_position` order — there is never a "provisionally-seen-but-not-contiguous" position to track,
because such a position is exactly what the filter withholds until it is safe. Resolution — **(a) on Postgres**:
`GapAwarePosition` is replaced by a bare `GlobalPosition`/integer; there is no `$gaps` list to serialize, ever.
**(b) on MySQL/MariaDB**: the bounded-timeout mechanism below genuinely can deliver events out of strict order
(a slow transaction's gap can still be "filled in late" after later positions were already delivered), so
`GapAwarePosition`'s `$gaps` list is still needed there. This means the *position encoding stored in
`ProjectionStateTableManager.last_position`* (§2.1, opaque `TEXT`) is **not** uniform across engines after this
revision — a Postgres store's positions serialize as a bare integer string (`"104"`), a MySQL/MariaDB store's as
`GapAwarePosition`'s `"pos:g1,g2"` form. Both remain opaque `string`s to `ProjectionStateTableManager`/`StreamPage`
(§2.1's "format-agnostic" property still holds structurally), but a position string **is not portable between a
Postgres-backed store and a MySQL-backed store** — migrating a deployment from Postgres to MySQL (or vice versa)
must treat every stored projection position as invalid and force a rebuild (the same treatment §6.7/§8 already
require for other migration paths, so this is a new *instance* of an existing rule, not a new kind of problem).
Document this explicitly wherever position portability is discussed (§6.7, §8).

This differs from Marten's own mechanism only in mechanics, not intent: Marten additionally maintains a
background "high water mark" advancing thread and Tombstone events for its general async-daemon path — see §3.4
for the literature. Ecotone's version needs neither a background thread nor tombstone rows: the filtered `SELECT`
above is stateless and correct per-poll, which is simpler operationally (nothing to keep alive between polls,
nothing to reconcile on projection restart) at the cost of one extra function call (`pg_current_snapshot()`) per
read, negligible next to the query itself.

**Latency cost — stated plainly, per the challenge's demand, not glossed over.** The reader can never advance
past the oldest transaction that is *still open anywhere in the database*, not just transactions that touch
`event_log`. `pg_snapshot_xmin()` reflects every active transaction visible to the snapshot, cluster/database-wide
(subject to normal visibility rules) — an entirely unrelated long-running reporting query, a `pg_dump` run without
appropriate flags, or a forgotten `BEGIN;` left idle in a psql session can each hold `xmin` back indefinitely and
stall delivery of *every* projection reading this store, even though that session never writes to `event_log` at
all. This is a real, sharp operational cost, not a corner case: a single idle-in-transaction connection is a
known, common production incident class independent of event sourcing. Mitigation, not elimination: (a) set
`idle_in_transaction_session_timeout` (built into Postgres since 9.6) at the database or role level as an
operational requirement for any deployment using this store, so a forgotten open transaction is killed rather than
stalling delivery indefinitely; (b) set `statement_timeout` to bound genuinely long write transactions; (c)
monitor `pg_stat_activity`/the gap between `pg_snapshot_xmin()` and the true max `global_position` as an alerting
signal (this is what `ecotone:event-store:verify-gaps` becomes on Postgres under this scheme, §9/C9 item 4 below —
a stall diagnostic, not a literal gap report, since there are no literal gaps left to report). This must be
called out loudly in the deployment docs, not buried — an operator who doesn't know about
`idle_in_transaction_session_timeout` can have projections silently stop advancing with no error, only growing
lag.

**Postgres version floor — checked, not assumed (part of C2).** `pg_current_snapshot()`, `pg_snapshot_xmin()`,
and the `xid8` type all require **Postgres 13+** (the pre-13 equivalents are `txid_current_snapshot()` /
`txid_snapshot_xmin()`, returning a `bigint`-safe `txid_snapshot`, available since ~9.2, without the wraparound-safe
64-bit `xid8` type). `upgrade-2.0.md` (`rg -n "Minimum requirements"`) documents no Postgres floor at all today —
only PHP/Symfony/Laravel/DBAL. `docker-compose.yml`/CI run `simplycodedsoftware/postgres:16.1` exclusively — no
older Postgres is tested anywhere in this repository. Given CI only ever exercises 16.1 and Postgres 13 has been
out of general support consideration for years by the time 2.0 ships, **recommend Ecotone explicitly document
Postgres 13+ as the 2.0 floor** (using the modern `xid8`/`pg_current_snapshot()` API as designed above) rather
than either silently assuming it or spending effort supporting the legacy `txid_*` functions for engines nothing
in CI verifies — this is a new, explicit open question for the maintainer (§10) since it is a real, user-visible
minimum-version decision this design forces, not a Group D implementation detail.

**MySQL / MariaDB: no equivalent primitive exists.** Neither engine exposes a queryable "set of currently active
transaction ids and their oldest member" the way Postgres's snapshot functions do; `information_schema.INNODB_TRX`
exists but is a privileged, engine-internal view not intended for this purpose, is not portable across MariaDB
versions, and doesn't map cleanly to "which `global_position` values are still uncommitted" (InnoDB's internal
trx ids are not the same numbering as the `AUTO_INCREMENT` sequence). Recommendation for MySQL/MariaDB: fall back
to a **bounded wall-clock safety window**, same mechanism Ecotone already has (`GapAwarePosition` +
`gapTimeout`/`maxGapOffset`, §1.6), but with the honest trade-off documented rather than hidden: a gap on
MySQL/MariaDB is presumed permanent only after `gapTimeout` has elapsed with no fill, which means a transaction
held open longer than that window (a long-running batch job, a stuck lock wait) can cause a silent skip on
MySQL/MariaDB that Postgres does not have. This must be a explicit, loud line in the docs and in `EventSourcingConfiguration`'s
MySQL/MariaDB code path (§7 edge-case table), not a silently-varying default. A secondary MySQL-only mitigation:
require `SET SESSION innodb_autoinc_lock_mode = 0` (traditional, table-level lock during the insert) is *not*
recommended (kills write concurrency) — instead, keep `2` (interleaved, the InnoDB default since 8.0) and rely on
the wall-clock window, sized generously (default recommendation: 3× the P99 transaction duration observed in
practice, configurable via `EventSourcingConfiguration::withGapTimeout()`).

**Rejected alternatives** (§3, §4 detail the sources):
- Monotonic sequence allocation via advisory lock / `SELECT FOR UPDATE` on a singleton row before every append:
  removes the gap problem entirely (writer serializes on the lock, so allocation order == commit order, no gaps
  possible) but destroys write concurrency — every append across the *entire application* contends on one lock.
  Rejected as the default; kept as an opt-in `EventSourcingConfiguration::withSerializedAppend()` escape hatch for
  low-throughput deployments that would rather trade write concurrency for zero gap-handling complexity.
- "Commit ordering" / outbox-style second table populated by a `COMMIT`-time trigger: adds a second write per
  event and a trigger dependency per engine; does not fundamentally avoid the same visibility race (the trigger
  still fires inside the transaction, before COMMIT is durable) — rejected as solving nothing extra over the
  approach above while adding cost.
- Prooph-style pure time-window retry with no transaction-visibility check at all (today's mechanism, §1.5, §3.7):
  rejected as the primary mechanism on Postgres (strictly worse than the snapshot-based approach, which is free);
  kept as the necessary fallback on MySQL/MariaDB where nothing better exists.

### 6.6 Physical partitioning (not a consistency concern)

Once tags — not physical table choice — define consistency boundaries, physical partitioning becomes a pure
scaling/maintenance concern and can be delegated to native Postgres declarative partitioning
(`PARTITION BY RANGE (global_position)` or `PARTITION BY HASH` on a tag-derived key) or MySQL/MariaDB `PARTITION BY
RANGE`, entirely transparent to the query layer described in §6.2 (partition pruning happens inside the DB
planner; Ecotone's SQL doesn't change). This directly satisfies the goal statement's "partitioning as a physical
concern, not a consistency concern" and lets very large logs (§7 "very large streams") be range-partitioned by
`global_position` for maintenance (old-partition archival/detach) without touching application-level tag queries.

**Why `event_tags`/`event_log` are not linked by a foreign key (C9 in the challenge review, checked against real
Postgres behaviour, not assumed).** Postgres foreign keys referencing a partitioned table were unsupported before
PG11 and only became fully reliable in PG12+ (["Waiting for PostgreSQL 12 – Support foreign keys that reference
partitioned tables"](https://www.depesz.com/2019/04/24/waiting-for-postgresql-12-support-foreign-keys-that-reference-partitioned-tables/),
[EDB — "PostgreSQL 12: Foreign Keys and Partitioned Tables"](https://www.enterprisedb.com/blog/postgresql-12-foreign-keys-and-partitioned-tables)) —
so the FK from §6.1.1's original DDL (`event_tags.global_position REFERENCES event_log`) *can* be created on any
Postgres version this design targets (13+, §6.5). But it directly undermines the archival use case this section
exists for: **detaching a partition that still contains rows referenced by a FK from another table is blocked
by Postgres**, and even the "referenced-but-empty-after-archival" case has had real, version-specific correctness
bugs — a bug affecting `ATTACH`/`DETACH PARTITION` against a table referenced by a FK from elsewhere was only
fixed in Postgres **16.5, 15.9, 14.14, 13.17** (per the same search results), meaning even a nominally-supported
older *minor* version in the 13–16 range can have broken FK-vs-partition-detach behaviour. Given Ecotone's own CI
only tests 16.1 (comfortably past the fix, §6.5), the FK would work correctly in what CI verifies — but it would
still make the exact "old-partition archival/detach" workflow this section recommends fail with a constraint
violation the moment an operator tries to detach a partition still holding referenced tag rows, defeating the
stated purpose. **Decision: drop the FK constraint from the default DDL** (§6.1.1/§6.1.2 already reflect this —
no `REFERENCES` clause on `event_tags.global_position`). Referential integrity is enforced at the application
level instead: `event_tags` rows are only ever written by Ecotone's own `append()` path, in the same transaction
as the `event_log` row they reference (never by user-supplied SQL), and are never updated or deleted after insert
— an orphaned `event_tags` row can only occur from a bug in Ecotone's own write path, not from external data entry,
which is a materially different (and much narrower) risk than the general case a FK normally guards against. This
trade is made explicitly here so it reads as a considered decision, not an oversight, if questioned later.

### 6.7 ProjectionV2 stream sources — precise mapping onto the new store

This is the interface Group B depends on (release-design spec, §"D"); mapped field-by-field against what exists
today (§2.1):

| Today (stream-name based) | New store (tag/query based) |
|---|---|
| `EventStoreGlobalStreamSource` reads raw SQL against the physical Prooph table by `PdoStreamTableNameProvider::generateTableNameForStream()` | Reads via `EventStore::read(Query::forTags([]) /* i.e. no tag filter = whole log */ ->ofTypes(...eventNames), from: GlobalPosition::fromString($lastPosition))` — no more direct-SQL bypass of the abstraction; the store's own `event_log`/`event_tags` tables are the single physical shape, so the stream source no longer needs `PdoStreamTableNameProvider` at all |
| `GapAwarePosition` (position + gaps, custom string serialization) | **Revised — not "kept as-is."** §6.5 resolves this: on Postgres the xmin-filtered read delivers strictly contiguous positions, so `GapAwarePosition` collapses to a bare integer there; on MySQL/MariaDB the bounded-timeout fallback still needs the real `$gaps` list, so `GapAwarePosition` survives *only* on that engine path. `StreamPage::$lastPosition`/`ProjectionStateTableManager.last_position TEXT` stay structurally opaque (§2.1's "format-agnostic" property holds), but the two engines now write **different, mutually unreadable position encodings** into the same `TEXT` column — see §6.5's latency-cost paragraph and §8 for what this means for migration between engines |
| `EventStoreAggregateStreamSource` (partition key `"streamName:aggregateType:aggregateId"`, per-aggregate `MetadataMatcher`) | Partition key becomes the tag pair `"aggregateType:aggregateId"` (stream name concept disappears entirely); loads via `EventStore::read(Query::forTags(['aggregateType' => ..., 'aggregateId' => ...])->ofTypes(...))` |
| `StreamFilter{streamName, aggregateType, eventStoreReferenceName, eventNames}` | Becomes `StreamFilter{tags: array<string,string>, eventStoreReferenceName, eventNames}` — `streamName` field removed, `tags` added; `#[FromAggregateStream(Order::class)]` continues to compile down to `StreamFilter` the same way, just emitting a tag pair instead of a stream name |
| `#[FromStream(stream: 'x')]` | Becomes `#[FromTags(['x' => $value, ...])]` or is deprecated in favour of always using `#[FromAggregateStream]` for aggregate-shaped projections and a new `#[FromTags]` for genuinely cross-aggregate DCB projections; `#[FromStream]` as a literal "physical stream name" concept has no meaning once there is one global log — kept only as a thin BC shim that maps to `#[FromTags(['stream' => $name])]` if any event still carries a legacy `stream` tag from migrated data (§8) |

`Ecotone\Projecting\StreamSource` interface itself (`canHandle`/`load(name, lastPosition, count, partitionKey):
StreamPage`) requires **no signature change** — `partitionKey` remains an opaque string, its *meaning* changes
from `"streamName:aggregateType:aggregateId"` to a tag-pair encoding. This is the key finding for Group B: the
Group B/D seam is already narrow and stable; the new store is a drop-in replacement behind `StreamSource` and
`ProjectionStateStorage`, not a redesign of the projection runtime.

**Existing `ecotone_projection_state.last_position` values are NOT safe to reuse blindly after the Prooph→new-store
rewrite — this is a hard coordination point with Group B, stated explicitly here rather than assumed away (C7 in
the challenge review).** An earlier draft of this section claimed "no schema migration needed" for
`ecotone_projection_state` — the *column* is unchanged (still `last_position TEXT`), but what that text
*represents* changes, and whether the OLD value remains valid depends entirely on which persistence strategy the
deployment was on before upgrading, which §8's migration text must handle per-strategy, not uniformly:
- **`single`/`partition` strategy** (today's closest layout to the new global log, and per §1.2/§8 the layout that
  is "read as-is" — i.e. the *same physical table* is reinterpreted, not copied into a new one): the existing
  Prooph `no` column *is* numerically the same sequence the new `global_position` continues from (it is the same
  column, possibly renamed). For these deployments, an old `GapAwarePosition`-encoded `last_position` like
  `"150:151,152"` can be **mechanically translated** (not rebuilt): parse out the leading integer, discard the old
  gap list (§6.5 already establishes gap tracking works differently now), re-encode per the target engine's new
  scheme (§6.5: bare integer on Postgres, a fresh empty-gaps `GapAwarePosition` on MySQL/MariaDB). Cheap, in-place,
  no event replay.
- **`aggregate` strategy** (many physical per-id tables, no shared sequence, §1.2): old `no` values are local to
  each per-aggregate table and have **no relationship whatsoever** to the new shared `global_position` sequence
  the migration tool assigns while copying rows into the global log (§8, §9 task 13). For these deployments, every
  stored `last_position` for every affected projection **must be force-reset** (to "start"/null), triggering a
  full rebuild via `ecotone:projection:rebuild` (Group B's existing command, per `upgrade-2.0.md` §3) — reusing
  the old value would silently resume the projection at a numerically-plausible but semantically-arbitrary offset,
  which is worse than an honest rebuild because it fails silently rather than loudly.
- **`simple`/`custom` strategy**: does not enforce the aggregate metadata the migration tool relies on to assign
  tags (C8 below) and should be treated the same as `aggregate`-strategy for this purpose — force-reset, don't
  attempt translation, unless a deployment can positively confirm its events carry complete `_aggregate_type`/
  `_aggregate_id` metadata (rare enough not to be the default assumption).

The migration tool (§8, §9 task 13) must therefore carry **two distinct code paths** — a cheap position-string
translator for `single`/`partition`, and a "mark every ProjectionV2 projection's state row for rebuild" step for
`aggregate`/`simple`/`custom` — and must run the latter *before* the first post-migration event is appended (a
new event appended under the new scheme, for a projection still holding a stale, untranslated position string,
would resume from the wrong point and silently miss data). This ordering requirement is exactly the kind of detail
that must be coordinated with Group B's own migration/rebuild tooling rather than decided unilaterally by Group D
— flagged as such, not resolved unilaterally, in §8's migration text and as a new open question (§10).

### 6.8 DCB decision models — scope recommendation

A "decision model" in DCB terms is: read a `Query` spanning tags from possibly-multiple conceptual aggregates,
fold the matching events into an in-memory projection, run a command handler's business logic against that
projection, then `append()` new events with an `AppendCondition` keyed to the same `Query` + the position read at.
Ecotone's `#[CommandHandler]` + `#[EventSourcingAggregate]` model today folds events for *one* aggregate only
(`EventSourcedRepositoryAdapter::findBy()`, §2.1). A `DcbDecisionModel` would look like:

```php
final class TicketSaleDecision
{
    #[CommandHandler]
    public function reserve(ReserveTicket $command, EventStore $eventStore): array
    {
        $query = Query::forTags(['ticket' => $command->ticketId])
            ->or(Query::forTags(['customer' => $command->customerId])->ofTypes(CustomerBlocked::class));

        $stream = $eventStore->read($query);
        $state = TicketSaleState::foldFrom($stream->events);

        if ($state->isReserved() || $state->customerIsBlocked()) {
            throw new TicketNotAvailable();
        }

        $eventStore->append(
            [new TicketReserved($command->ticketId, $command->customerId)],
            AppendCondition::noConflictExpected($query, $stream->lastPosition),
        );

        return [];
    }
}
```

**Recommendation: out of 2.0 scope, follow-up release.** Reasoning: 2.0's Group D goal is the *store* (tags,
conditional append, global position) — that is a large, foundational, and already-substantial scope on its own
(new DDL, gap detection, Prooph removal, migration tooling). A first-class `#[DcbDecisionModel]` /
`#[CommandHandler]`-on-multi-tag-projection ergonomic layer on top is a separate, purely additive feature: nothing
in §5's store API blocks building it later, and single-aggregate `#[EventSourcingAggregate]` command handlers
already get the concurrency-safety benefit of the new store for free (§5.3) without this layer existing. Shipping
the store first and validating it under real single-aggregate load before adding a second, more complex read/fold
API on top reduces risk. The store API (§5.2: `Query`, `AppendCondition`, `EventStore::read/append`) is public and
stable enough that a decision-model layer is additive, not a breaking redesign, whenever it ships.

### 6.9 `#[Version]`/`#[TargetVersion]` — how the user-facing optimistic lock survives the rewrite (C3)

**What today's mechanism actually does, read from source rather than assumed.**
`AggregateResolver::getVersionBeforeHandling()` (`packages/Ecotone/src/Modelling/AggregateFlow/SaveAggregate/AggregateResolver/AggregateResolver.php:201-224`)
resolves the aggregate's expected version in this order: (1) if the command carries a `#[TargetVersion]`-annotated
property, `LoadAggregateMessageProcessor::process()`
(`packages/Ecotone/src/Modelling/AggregateFlow/LoadAggregate/LoadAggregateMessageProcessor.php:59-65`) has already
copied that **user-supplied, possibly-stale** value into the `AggregateMessage::TARGET_VERSION` header, and it
wins outright; (2) otherwise, for a loaded (not brand-new) aggregate with a `#[Version]` property that is not
"state-stored and auto-increased," the **freshly-read** value of that property on the just-loaded PHP instance is
used. `SaveAggregateServiceTemplate::enrichAggregateEvents()` (same directory,
`SaveAggregateServiceTemplate.php:169-183`) then stamps each new event's `MessageHeaders::EVENT_AGGREGATE_VERSION`
with `++$incrementedVersion` starting from that value — a plain per-event increment, unified with save-time
optimistic locking today only because the *save* path's DB unique constraint on
`(aggregate_type, aggregate_id, aggregate_version)` (§1.3, §2.2) rejects the write outright if the stamped
version collides with an already-persisted one — i.e. the version number **is** the concurrency token today, by
construction. Note also that `AggregateVersionMismatchException`
(`packages/Ecotone/src/Modelling/AggregateVersionMismatchException.php`) exists but is thrown nowhere in the
codebase (`rg -l "AggregateVersionMismatchException" packages/` matches only its own definition file) — the actual
user-visible failure today is `Ecotone\Messaging\Support\ConcurrencyException`, surfaced via the DB
constraint-violation catch in `LazyProophEventStore` (§1.3), not that exception class.

**Why this report's earlier "count of matching events" answer was wrong, precisely.** `EventStream::getAggregateVersion()`
(`packages/Ecotone/src/Modelling/EventStream.php`) is set once, from `EventSourcingRepository::findBy()` reading
the `_aggregate_version` metadata off the **last loaded event** — O(1), not a count, correcting an earlier
imprecision in this report's own §1.7/§5.3 text. But that O(1) read is only correct because, today, "the last
loaded event" and "the last event this aggregate ever emitted" are the same thing — true only because the load is
never filtered by event type and never starts after a snapshot's exact boundary without also being told the
snapshot's own recorded version. The moment a query is `ofTypes()`-filtered (excluding some of the aggregate's own
events from the read) or the load starts strictly after a snapshot position without independently knowing how many
prior events existed (§6.10), "version of the last *loaded* event" silently diverges from "true version of the
aggregate" — this is the real flaw the challenge identified, not the O(1)-vs-counting framing.

**Fix: the `event_tag_versions` row for the aggregate's own instance tag (§6.1.4/§6.1.5) *is* the aggregate's
version, assigned by the same mechanism that already has to run for `AppendCondition`.** No new write beyond one
extra tag per event, no O(n) count, and no dependence on which events happen to be in a given read's result set.
§6.1's uniform layout (D4, this revision) means `event_tags`/`event_tag_versions` are the same shape on every
engine, closing an earlier draft's unresolved "Postgres also needs a companion table for this" note — it already
has one, identically to MySQL/MariaDB.

**Correction found while finalising this design (not asked for by the challenge, but required to make it actually
correct): `aggregateId` alone is not a safe CAS/versioning key.** §5.1/§6.1.1 tag every event with *separate*
`aggregateType`/`aggregateId` tags — correct and sufficient for **querying** (`Query::forTags(['aggregateType'=>...,
'aggregateId'=>...])` ANDs both, so cross-type collisions never affect reads), but §5.2/D1 established that only
the fine-grained tag may be CAS'd, and `aggregateId` alone is only unique **within one aggregate type** — two
different aggregate types can legitimately share an identifier value (sequential per-type integer ids, or simply
coincidence), which would make their `event_tag_versions` rows for `(aggregateId, '42')` collide and corrupt each
other's version counters. **Fix: an additional, automatically-derived, framework-internal tag** —
`_aggregateInstance: "{aggregateType}:{aggregateId}"` (e.g. `_aggregateInstance: "Ticket:42"`) — stamped on every
event alongside `aggregateType`/`aggregateId` by the same mechanism (§5.1's "aggregate-derived tags are automatic"
already covers this; it is a third automatic tag, not a new concept). This is the tag `EventSourcingRepository`
actually captures/CAS-protects (§5.2/D1's `$capturedTagVersions`), never `aggregateId` alone — `aggregateType`/
`aggregateId` remain the documented, queryable, user-facing tags (§5.1/§8 unchanged); `_aggregateInstance` is an
implementation detail of the versioning mechanism, not part of the public tag vocabulary, and is not expected to
be queried directly (nothing prevents it, it just isn't the documented path).

Concretely, in `EventSourcingRepository::save()`: for an append carrying K new events all belonging to instance
tag `_aggregateInstance: "Ticket:42"`, issue **one** UPSERT against `event_tag_versions` — `... SET version =
version + :K WHERE version = :versionBeforeHandling` (Postgres `ON CONFLICT DO UPDATE`, MySQL/MariaDB `ON
DUPLICATE KEY UPDATE`, §6.1.5) — and assign the K events' individual `MessageHeaders::EVENT_AGGREGATE_VERSION`
values as `versionBeforeHandling + 1 .. versionBeforeHandling + K` in PHP before building the `event_tags` rows,
storing each event's assigned number in `event_tags.tag_local_seq` (§6.1's uniform DDL carries this column on
every engine). This is **one UPSERT per distinct tag per append() call**, not per event — the same statement
§6.1.5 already has to run for `AppendCondition`, extended to also return the version it assigned.
`#[TargetVersion]`'s user-supplied stale value plugs directly into this UPSERT's `WHERE version = :captured` guard
exactly as it does into `AppendCondition`'s guard today — **no change to the aggregate/command-side contract**:
`#[Version]` and `#[TargetVersion]`-annotated properties keep meaning exactly what they mean today, from the
outside; `_aggregateInstance` is invisible to application code, exactly as `_aggregate_version` metadata is
invisible today. The per-aggregate write serialization this reintroduces is not a new cost — it is the same
serialization today's `UNIQUE(aggregate_type, aggregate_id, aggregate_version)` constraint already imposes on
every save for that aggregate; this design just makes the serialization point (one small side-table row) explicit
rather than an emergent property of a big table's unique index. What that serialization point actually *costs* in
lock-hold duration under Ecotone's transaction model is a separate question, addressed in §6.11 below (D5).

### 6.10 Snapshots — designed, not just named (C4)

`EventSourcedRepositoryAdapter` (`packages/Ecotone/src/EventSourcing/EventSourcedRepositoryAdapter.php`) owns
snapshot read/write via `DocumentStore`, keyed `aggregate_snapshots_<ClassName>` / the aggregate's identifier
(§2.1). Answering each part of C4 concretely:

- **What position does a snapshot record?** The aggregate's `#[Version]` value at snapshot time — unchanged by
  this rewrite, since §6.9 keeps `EVENT_AGGREGATE_VERSION` meaning exactly what it means today (a small,
  per-aggregate incrementing integer, now backed by `event_tag_versions`/`tag_local_seq` rather than a physical
  unique constraint). Snapshots are not touched by the store rewrite in *what* they store, only in how the
  post-snapshot load query is built.
- **Exact post-snapshot load query.** `EventSourcingRepository::findBy($class, $ids, fromVersion: $snapshotVersion
  + 1)` (§5.3's `readAggregateEvents(query, afterTagVersion: $snapshotVersion)`) filters on `event_tags.tag_local_seq
  > :snapshotVersion` for the aggregate's `_aggregateInstance` tag (§6.9 — not the separate `aggregateType`/
  `aggregateId` query tags, which have no meaningful `tag_local_seq` of their own since they're shared across many
  instances/across all aggregates of a type respectively) — an index range scan on
  `event_tags(tag_key, tag_value, tag_local_seq)` (a second index alongside the `(tag_key, tag_value,
  global_position)` one, or a covering index if `tag_local_seq` is added to the existing one), not a table scan
  and not a count. This is a store-internal capability (§5.3), not part of the general public `Query`/DCB
  language — a generic multi-tag decision-model query has no single well-defined "version" to filter on, so this
  optimisation is deliberately scoped to single-aggregate loading only.
- **The threshold is "every N events" of what, exactly?** Read from source: `EventSourcedRepositoryAdapter::save()`
  increments a local `$version` counter once per new event and snapshots when `$version % $snapshotTriggerThreshold
  === 0` — that `$version` **is** `EVENT_AGGREGATE_VERSION` (§6.9), so "every N events" already means, and
  continues to mean, "every N events carrying this aggregate's own tag pair, regardless of event type" — no
  semantic change, because §6.9 deliberately preserves that meaning.
- **What happens to existing snapshots on upgrade?** Better news than this report's own C4 prompt assumed: because
  `_aggregate_version` event metadata is *already*, today, numerically identical to what §6.9's `tag_local_seq`
  needs to be (it is the same per-aggregate incrementing integer, just currently enforced by a physical unique
  constraint instead of a side-table CAS — §6.9), and because that metadata survives migration verbatim (§8 copies
  event rows/metadata as-is), **existing snapshots do not need to be invalidated** — provided the migration tool
  backfills `event_tag_versions`/`event_tags.tag_local_seq` from each migrated event's existing `_aggregate_version`
  metadata as part of the copy (a new, explicit migration-tool requirement, §8/§9), and does so **before** any
  post-migration event is appended for that aggregate (same ordering requirement as §6.7's projection-state
  translation — miss it, and the first post-migration append for an aggregate would start its `event_tag_versions`
  counter from 0/1 instead of continuing from the migrated maximum, silently colliding with or duplicating
  existing version numbers). This holds for `single`/`partition`/`aggregate`-strategy migrations alike, since all
  three already populate `_aggregate_version` today (§2.1's DDL findings) — it does **not** hold for `simple`/
  `custom`-strategy stores that never populated that metadata, where snapshots for aggregates lacking it must be
  treated as invalid and rebuilt from a full replay, consistent with §6.7's treatment of the same strategies.
- **Interaction with DCB decision models (§6.8).** Not meaningful there in the same shape: a decision model's
  consistency boundary is chosen per-command from an arbitrary tag query rather than a fixed aggregate-shaped
  collection, so there is no stable cache key to snapshot a folded projection under across different callers'
  queries — snapshotting stays scoped to single-aggregate `#[EventSourcingAggregate]` loads in 2.0, consistent
  with §6.8's "decision models are follow-up scope" recommendation.

### 6.11 Lock-hold duration inside Ecotone's one-transaction-per-message model (D5)

**Where `append()` actually runs relative to the handler body — read from source, not assumed.**
`SaveAggregateService::process()` (`packages/Ecotone/src/Modelling/AggregateFlow/SaveAggregate/SaveAggregateService.php:34-68`)
does two separate loops over the message's resolved aggregates, in this order: first, `:44`
`$this->aggregateRepository->save($resolvedAggregate, $metadata)` for **every** resolved aggregate (this is where
`EventStore::append()` — and therefore §6.1.5's CAS UPSERT and its row lock — actually happens); only *after* that
first loop completes does a second loop, `:58-60`, call `$this->publishEvents($resolvedAggregate->getEvents())`
for every resolved aggregate, which (`:73-78`) calls `$this->eventBus->publish($event->getPayload(),
$event->getMetadata())` for each event — the call that synchronously invokes any non-`#[Asynchronous]`
`#[EventHandler]`s subscribed to that event (projections, sagas, further command dispatches) within the **same**
call stack. **`append()` is therefore not the last thing that happens in the transaction — it is close to the
first**, for any message whose handler does more after saving than immediately return.

**`DbalTransactionInterceptor` wraps the entire invocation, not just the save.**
(`packages/Dbal/src/DbalTransaction/DbalTransactionInterceptor.php:41-107`, `#[Around]`-style): it calls
`$connection->beginTransaction()` (`:83-89`), then `$result = $methodInvocation->proceed()` (`:96`) — which is the
**whole** wrapped invocation chain, including everything `SaveAggregateService::process()` does, including the
second loop's synchronous event publishing — and only calls `$connection->commit()` (`:99`) after `proceed()`
returns. So: the `event_tag_versions` row lock taken during the first loop's `append()` call is held through the
*entire remainder* of `SaveAggregateService::process()`, including every synchronous `#[EventHandler]` triggered
by that message's events, until the interceptor's `commit()` runs at the very end. This is a real, structural cost
of "one transaction wraps the whole message" (Group F), not specific to the CAS mechanism as such — but the CAS
mechanism is what makes contention on that window **visible and costly**, in a way today's mechanism partly
obscures.

**Is this actually worse than today's `UNIQUE(aggregate_type, aggregate_id, aggregate_version)` constraint?
Nuanced, stated honestly rather than either dismissed or overstated.** Both mechanisms hold their respective locks
until the same commit — that part is not new; any row-level lock in a DB transaction is held until that
transaction ends, regardless of mechanism. What genuinely differs: today's INSERT into the physical event table
acquires a lock on a **brand-new** index entry (a fresh `(type, id, version)` tuple) — two sequential, non-racing
saves for the same aggregate never contend on the *same physical row*, only on the abstract uniqueness constraint,
which only becomes visible as contention when a real version collision is attempted. The CAS mechanism's
`event_tag_versions` UPSERT touches the **same physical row** on every single save for that aggregate, forever —
so even two messages for the same aggregate that are *not* racing (message 2 genuinely arrives after message 1 is
fully done) will serialize on that row if they happen to be processed by concurrent consumers with overlapping
timing, which is an entirely ordinary, expected production scenario (two commands for the same order arriving
close together), not a bug. Concretely, this means: a slow synchronous projection or a slow further command
dispatch triggered by message 1's events (still inside message 1's transaction, per the paragraph above) now
directly delays message 2's ability to even *attempt* its own save for the same aggregate — not just delays
message 2's commit, delays its `append()` call from succeeding at all, for as long as message 1's entire
post-save processing takes.

**Interaction with Group F's implicit-commit removal.** Once implicit commit is removed and "one transaction
wraps the whole message" becomes an unconditional rule (not just today's common case), there is no way to shorten
this window from inside the event-store design itself — the lock-hold duration becomes entirely a function of how
much synchronous, in-transaction work a message's handler chain does after saving. The only mitigations available
to *application authors* (not to this design) are architectural: keep synchronous `#[EventHandler]` chains
short/fast, or move more of the reactive work behind `#[Asynchronous]` channels — which commit and hand off before
that downstream processing runs, ending the transaction (and releasing the lock) at the save, not after a cascade
of synchronous side effects. This should be stated explicitly in the migration guidance (§8) as a new operational
consideration for aggregates with high write concurrency and non-trivial synchronous event-handler fan-out — it is
not a defect in this design, but it is a real behavioural change from today worth calling out rather than leaving
implicit.

---

## 7. Edge cases

| Case | Behaviour |
|---|---|
| Concurrent appends to disjoint tag sets (no overlap in `$capturedTagVersions`) | No conflict — the CAS in §6.1.5 only touches the tags each caller actually captured, so neither observes the other's write; both commit independently, consistent with DCB's promise that unrelated consistency boundaries never contend |
| Concurrent appends where both `AppendCondition::$capturedTagVersions` protect the same tag, captured at the same version | Second committer's append fails — **not** via a plain re-check `SELECT` (which, under ordinary `READ COMMITTED`/`REPEATABLE READ`, cannot see the other writer's not-yet-committed insert and so both could wrongly succeed), but via the atomic `event_tag_versions` UPSERT-with-guard mechanism (§6.1.5, full per-engine comparison and rejected alternatives there — `SERIALIZABLE`+retry, advisory locks, `FOR UPDATE`): the second writer's UPDATE affects 0 rows once the first has committed, and that 0-row result is what throws `Ecotone\Messaging\Support\ConcurrencyException` (the same class thrown today, §6.9). Composes with `InstantRetryConfiguration`'s existing retry mechanism (Group F) rather than needing bespoke retry code |
| An `AppendCondition` with an `ofTypes()`-filtered query, on a tag also touched by other event types | `event_tag_versions` bumps on *any* event carrying that tag regardless of type — a conservative over-approximation of dcb.events' exact semantics, D2 (§6.1.5): can raise `ConcurrencyException` for a write the DCB spec would have allowed, never misses a real conflict. Deliberately accepted, since 2.0's shipped surface (single-aggregate loading) never uses a type filter on its captured tag; relevant only to the future decision-model layer (§6.8) |
| Ordering & gaps — reader polls mid-transaction | See §6.5 (corrected direction, C2): Postgres readers only ever deliver rows with `transaction_id < pg_snapshot_xmin(...)`, which is provably contiguous — no gap-list to track; MySQL/MariaDB readers rely on a bounded timeout and can (rarely) skip an event held open longer than the window — documented, not silently correct. `GapAwarePosition` therefore only exists on the MySQL/MariaDB code path after this revision (§6.5/§6.7) |
| Ordering & gaps — transaction rolls back after allocating a position | Position is permanently absent; Postgres readers detect this automatically the instant `pg_snapshot_xmin` clears the rolled-back transaction (no separate "re-check the number" step — the filtered `SELECT` itself never claims the gap as safe until then, §6.5); MySQL/MariaDB readers close it once `gapTimeout` elapses, same as today |
| A single long-open transaction anywhere in the Postgres database (idle-in-transaction session, `pg_dump`, a stuck lock wait) — not necessarily one that writes to `event_log` at all | Stalls delivery of **every** projection reading this store, cluster/database-wide, for as long as that transaction stays open — `pg_snapshot_xmin()` cannot distinguish "a transaction that will eventually write to event_log" from "any other open transaction" (§6.5). Mitigate operationally with `idle_in_transaction_session_timeout` and `statement_timeout` (§6.5) — this is a required operational setting for this design, not an optional tuning knob, and must be called out prominently in deployment docs |
| A slow synchronous `#[EventHandler]`/projection/further command dispatch triggered by an aggregate's own save, within the same message transaction | Extends the `event_tag_versions` row lock for that aggregate's `_aggregateInstance` tag well past the `append()` call itself, per §6.11 (D5) — a second, concurrent message for the *same* aggregate cannot even attempt its own `append()` until the first message's entire transaction (save + every synchronous side effect) commits. Mitigate by keeping synchronous handler chains short or moving reactive work behind `#[Asynchronous]` channels, which commit and release the lock at the save rather than after a cascade |
| Non-string / composite tag values (an int identifier, a UUID object, a value-object identifier) | Cast to string via the same convention aggregate ids already use today (`(string)`, §1.7) — a `#[Tag]`-annotated property whose type does not resolve to a scalar or `Stringable` fails fast at bootstrap with a `ConfigurationException`, not silently at runtime. A `null` tag value is rejected at append time (a tag that may or may not be present is not a meaningful DCB tag); an empty string is permitted but discouraged in docs, since it is easy to confuse with "absent" |
| Case/accent collation of tag values across MySQL/MariaDB and Postgres | Fixed in this revision (§6.1.2, C8): MySQL 8's *default* collation (`utf8mb4_0900_ai_ci`) is accent- and case-**insensitive**, which would silently equate two different tag values (`'Ticket-1'`/`'ticket-1'`) and corrupt consistency boundaries; DDL now specifies `COLLATE=utf8mb4_0900_bin` explicitly on `event_tags`/`event_tag_versions`. Postgres's default `text` comparison is already byte-exact; no equivalent fix needed there but stated explicitly for parity |
| Tag extraction timing relative to serialization/upcasting | `#[Tag]` reflection must run on the live PHP event object, before `ConversionService::convert()` turns it into an array/JSON payload (`EcotoneEventStoreProophWrapper::convertProophEvents()`, §2.1, is where that conversion happens today) — concretely, alongside `SaveAggregateServiceTemplate::enrichAggregateEvents()` (§6.9), which already runs on the live object at exactly this point in the pipeline. No upcasting mechanism exists in Ecotone today (`rg -il upcast packages/Ecotone/src packages/PdoEventSourcing/src` → no matches) — tags are therefore purely a write-time concern with nothing to reconcile against upcasting yet; if/when upcasting ships, it must not retroactively change a stored event's tags (upcasting reshapes payload for read-time consumption only) |
| PostgreSQL / MySQL / MariaDB minimum version | **Checked, not assumed (C2/C5)**: this design requires Postgres 13+ (`xid8`/`pg_current_snapshot`, §6.5) and benefits from — but with §6.2's portable SQL, does not require — MySQL 8.0.31+/MariaDB 10.3+ (`INTERSECT`). None of these floors is documented anywhere today (`upgrade-2.0.md` states only PHP/Symfony/Laravel/DBAL minimums); CI (`docker-compose.yml`, `.github/workflows/test-monorepo.yml`) only ever exercises Postgres 16.1, MySQL 8.0(.x), MariaDB 11.4 — comfortably above every floor this design needs, but "what CI happens to run" is not a documented commitment. Recommend Group D's PR also adds an explicit minimum-version statement to `upgrade-2.0.md` (new open question, §10) |
| Foreign key between `event_tags` and `event_log`, combined with `event_log` range-partitioning (§6.6) | Deliberately **no FK** (§6.6, C9) — a FK to a partitioned table is supported from Postgres 12+, but detaching a partition still referenced by another table's FK is blocked outright, defeating the archival use case partitioning exists for, and cross-version `ATTACH`/`DETACH`-vs-FK correctness bugs were only fixed as recently as PG 13.17/14.14/15.9/16.5. Enforced at the application level instead (§6.6): `event_tags`/`event_tag_versions` rows are only ever written by Ecotone's own `append()` path in the same transaction as their `event_log` row, and never updated/deleted, so an orphan can only arise from a bug in that path, not external data |
| Multi-tenancy | Tag tables are per-tenant-connection like today's event tables (`MultiTenantConnectionFactory`, `LazyProophEventStore::getContextName()`, §1) — `global_position` is only totally ordered *within* one tenant's physical tables; no cross-tenant `global_position` comparison is ever meaningful, must be documented explicitly since a naive reader might assume one global order across all tenants |
| Multiple DB connections (e.g. app uses two separate Postgres DBs for two bounded contexts) | Each `EventStore` instance is bound to one `DbalConnectionReference` exactly as `EventSourcingConfiguration::create($connectionReferenceName, ...)` allows today (`EventSourcingConfiguration.php:40-52`); `global_position`/`AppendCondition` are meaningless across two different `EventStore` instances — a decision model (§6.8, when it ships) cannot span two physical stores atomically, same limitation as today |
| PostgreSQL vs MySQL vs MariaDB vs SQLite | Table DDL differs (§6.1); gap-detection quality differs (Postgres: provable, MySQL/MariaDB: timeout-bounded, §6.5); SQLite (tests only) has no concurrent-transaction gap problem at all because it serializes writers — tests passing on SQLite/in-memory must not be treated as validating gap-handling correctness on Postgres/MySQL (§9 test plan must include a real-DB gap-injection test) |
| Very large streams / tables | An aggregate with millions of events still resolves via a tight index probe either way: MySQL/MariaDB via `event_tags` PK `(tag_key, tag_value, global_position)` range scan, Postgres via the GIN index on `event_log.tags` (§6.1) — same complexity class as today's per-stream table, not a regression on either engine. The whole-log `event_log` table itself grows unboundedly and is the candidate for range partitioning by `global_position` (§6.6) for archival, vacuum/analyze cost management, and (Postgres) partition-local index size |
| Replay & rebuild | Unaffected by the store rewrite at the `StreamSource`/`ProjectionStateStorage` seam (§6.7) — `ProjectingManager::prepareRebuild()`/`executePartitionBatch(shouldReset: true)` (packages/Ecotone/src/Projecting/ProjectingManager.php:71-125,179-182) already resets `lastPosition` to null and re-reads from the beginning; only the underlying SQL run per page changes |
| Failure mid-migration (aggregate-stream tables → global log) | Migration tool (§8) must be resumable and idempotent: track migrated-up-to per source table, use `INSERT ... ON CONFLICT (event_id) DO NOTHING` (Postgres) / `INSERT IGNORE` (MySQL/MariaDB) keyed on the *original* event's uuid so a re-run after a crash does not duplicate; never delete source tables until an explicit `--commit` step after verification |
| Transactional boundaries and rollback | `append()` must execute inside the same DBAL transaction as the rest of the message-handling unit of work — exactly as today's `appendTo()` does (implicitly, via the shared connection) — so a handler exception rolls back both the event append and any other DB writes in the same handler; verify this against `DbalTransactionInterceptor` behaviour once implicit-commit removal (Group F) lands, since Group F explicitly targets "remove implicit commit" in the same interceptor this store's writes flow through |
| Tests — `EcotoneLite` in-memory vs real DB | In-memory implementation (replacing `Ecotone\EventSourcing\EventStore\InMemoryEventStore`, §2.1) must implement the *same* `Query`/`AppendCondition`/`GlobalPosition` contract (not a parallel simplified one, as today's in-memory store already avoids doing — it implements the real `EventStore` interface) so tests written against `EcotoneLite::bootstrapFlowTesting()` exercise the real conditional-append semantics; gap-handling logic is inherently untestable in-memory (single-threaded, no concurrent transactions) and must have a dedicated Postgres/MySQL container test suite injecting real concurrent/rolled-back transactions (§9) |
| Existing snapshots after the Prooph→new-store migration | Remain valid, not invalidated — `_aggregate_version` metadata already numerically equals what `event_tag_versions`/`tag_local_seq` needs to be (§6.10, C4); the migration tool must backfill those from existing metadata *before* any post-migration append, or version numbering silently collides. Exception: `simple`/`custom`-strategy stores that never populated `_aggregate_version` — those aggregates' snapshots must be treated as invalid (§6.10, §8) |
| Existing `ecotone_projection_state.last_position` after the Prooph→new-store migration | **Not automatically valid** (§6.7, C7 — corrects an earlier "no migration needed" claim in this report): `single`/`partition`-strategy positions are mechanically translatable (strip the old `GapAwarePosition` gap list, re-encode per §6.5); `aggregate`/`simple`/`custom`-strategy positions have no relationship to the new shared `global_position` sequence and must be force-reset, triggering a full `ecotone:projection:rebuild` (Group B coordination point, §6.7/§8) |
| `event_tags`/`event_tag_versions` write batching | An `append()` call with K events carrying T distinct tags total issues 1 `event_log` insert (or K, one per event) + 1 multi-row `INSERT` covering all `event_tags` rows for all K events in one round-trip (not K×T single-row inserts) + T `event_tag_versions` UPSERTs (one per distinct tag touched across the whole batch, not per event — §6.1.5/§6.9). Total round-trips stay O(1) in the number of events, not O(events × tags) |
| `ecotone:event-store:verify-gaps` under the corrected §6.5 mechanism | On Postgres there are no literal gaps left to report (§6.5) — the command's Postgres behaviour becomes a **stall diagnostic**: how far `pg_snapshot_xmin()`'s current value trails the true maximum `global_position`, cross-referenced against `pg_stat_activity` for the blocking session, not a gap list. On MySQL/MariaDB it keeps its original, literal purpose (list positions still open past `gapTimeout`). The command's implementation branches by engine; document the Postgres behaviour change explicitly so operators don't expect a gap list that no longer exists |
| Licence gating | The event store itself (global log, tags, conditional append) is core, not Enterprise-gated — event sourcing is a foundational capability, consistent with today (`EventSourcingRepository`, `EventStreamTableManager` docblocks say `licence Apache-2.0`/`licence Enterprise` inconsistently in the current code — `EventStreamTableManager.php:17` says `licence Enterprise` while `EventSourcingRepository.php:18` says `licence Apache-2.0`; this inconsistency should be resolved deliberately during the rewrite, not carried forward accidentally). ProjectionV2's `FromStream`/`FromAggregateStream` attributes are currently marked `licence Enterprise` (§2.1) — confirm with the maintainer whether that gate is intentional and applies unchanged to their DCB-store equivalents (§10) |

---

## 8. Migration impact for users (draft `upgrade-2.0.md` addendum)

The existing draft in `upgrade-2.0.md` §4 ("Event Store: single global event log, DCB-ready, no Prooph") already
covers the top-level narrative correctly per this research; the following expands and corrects it based on the
code read for this report. **Everything inside the fenced block immediately below is copy-paste text for
`upgrade-2.0.md` itself** — its `## 4.` heading is that target file's heading, in that file's own numbering, not
a section of this report (this report's own sections are `## 1.` through `## 10.`, outside this fence).

```markdown
## 4. Event Store: single global event log, DCB-ready, no Prooph

**Before:** `ecotone/pdo-event-sourcing` wrapped `prooph/pdo-event-store`. Four persistence strategies existed
(`simple`, `single`/`partition` — these two were actually the same due to a bug in
`EventSourcingConfiguration::withPartitionStreamPersistenceStrategy()` — `aggregate`). Aggregate concurrency was
enforced through a `_aggregate_version` unique constraint per physical table. Event ids were uuid4. Tag-like
filtering existed only as a generic `MetadataMatcher` DSL scoped to one stream name.

**Now:** Ecotone ships its own DBAL event store: one global, totally ordered event log with tags per event
(aggregate type/id are automatic; add `#[Tag('key')]` to any event property for domain tags) and conditional
append via `AppendCondition`. Event ids are UUID v7. `Prooph\EventStore\*`, `MetadataMatcher`, `FieldType`,
`Operator` (the Prooph-facing ones under `Ecotone\EventSourcing\Prooph\*`) are gone; the store-facing
`Ecotone\EventSourcing\EventStore\{MetadataMatcher,FieldType,Operator}` DSL is replaced by `Query`/`TagCriteria`.

**How to adapt:**
- Delete calls to `withSingleStreamPersistenceStrategy()`, `withPartitionStreamPersistenceStrategy()`,
  `withStreamPerAggregatePersistenceStrategy()`, `withSimpleStreamPersistenceStrategy()`,
  `withCustomPersistenceStrategy()` — there is one physical layout now.
- Tag your own cross-aggregate/domain events: `#[Tag('ticketId')] public readonly string $ticketId` on the event
  property. Aggregate id/type tags are automatic — no change needed for plain `#[EventSourcingAggregate]` usage.
- `EventStore::load($streamName, ...)` calls become `EventStore::read(Query::forTags([...])->ofTypes([...]))`.
  A pure "load everything for aggregate X" call:
  ```php
  // 1.x
  $events = $eventStore->load('Ticket-42', 1, null, $matcher);
  // 2.0
  $stream = $eventStore->read(Query::forTags(['aggregateType' => 'Ticket', 'aggregateId' => '42']));
  ```
- `EventStore::appendTo($streamName, $events)` becomes `EventStore::append($events, $condition)`. Conditional
  writes need an explicit `AppendCondition::noConflictExpected($query, $capturedTagVersions)` — but if you use
  `#[EventSourcingAggregate]` through the standard repository, this is generated for you; no application code
  change for the common case.
- Existing `single`/`partition`-layout `event_streams` data is read as-is by the new global-log reader (same
  physical shape: one table, all events). `aggregate`-strategy (stream-per-aggregate-id, many physical tables)
  needs a one-time migration: `ecotone:event-store:migrate-aggregate-streams` copies every per-aggregate table
  into the global log + `event_tags`, preserving original event ids and timestamps (not re-minting uuid7 for
  migrated data — see below). The command is resumable and safe to re-run after an interruption (idempotent via
  `event_id` uniqueness).
- **Every migrated event is retagged from its existing `_aggregate_type`/`_aggregate_id` metadata**
  (`aggregateType`/`aggregateId` tags, plus the internal `_aggregateInstance` tag §6.9 introduces, synthesised
  automatically — no application code involved) **and** the migration seeds `event_tag_versions`/
  `event_tags.tag_local_seq` from the same events' existing `_aggregate_version` metadata, so existing snapshots
  remain valid and `#[Version]`/`#[TargetVersion]` continue working unchanged (§6.9/§6.10). **This backfill is a
  hard prerequisite, not a best-effort step** (D6 in the round-2 challenge review) — see the numbered migration
  order immediately below.
- **If you used the `simple` or `custom` persistence strategy**, which did not require `_aggregate_type`/
  `_aggregate_id`/`_aggregate_version` metadata to be present (§1.2): any event that genuinely lacks
  `_aggregate_type`/`_aggregate_id` is migrated into the global log with **no tags at all** — it remains visible
  via type-only queries, but its aggregate becomes permanently unloadable through `#[EventSourcingAggregate]`
  machinery, not merely "its snapshot is invalid." Events that *do* carry `_aggregate_type`/`_aggregate_id` (many
  `simple`/`custom` deployments populate it voluntarily even though the strategy didn't enforce it) are tagged and
  remain loadable normally; if they additionally lack `_aggregate_version`, the migration tool falls back to
  numbering that aggregate's events ordinally by `global_position` during the copy — this produces a correct,
  working `tag_local_seq` going forward, but not necessarily one matching an existing snapshot's recorded version,
  so that aggregate's snapshot must still be discarded even though the aggregate itself remains loadable. Run
  `ecotone:event-store:audit-legacy-metadata` (dry-run, new command) before upgrading if you ever used `simple`/
  `custom` (`rg "withSimpleStreamPersistenceStrategy|withCustomPersistenceStrategy"` in your codebase) to see
  exactly which aggregates fall into which of these three buckets before committing to the migration.
- **Migration order is enforced, not advisory — numbered steps, because getting this wrong silently corrupts
  version numbering (D6):**
  1. Deploy the 2.0 application code, but do **not** yet route production traffic to it.
  2. Run `ecotone:event-store:migrate-aggregate-streams`, which performs the row copy (`aggregate` strategy) or
     schema evolution (`single`/`partition` strategy) **and**, as an integral part of the same command (not a
     separate step an operator could skip), backfills `event_tag_versions`/`event_tags.tag_local_seq` for every
     migrated aggregate and translates/resets `ecotone_projection_state.last_position` per §6.7/§6.10. The command
     does not exit successfully, and does not mark the migration complete, until every discovered aggregate's
     backfill has finished — a partial run is resumable (idempotent per §7 "failure mid-migration"), not silently
     "good enough to proceed."
  3. Only after the command reports success does the deployment cut traffic over to the new store (restart
     consumers / promote the new application version). **Failure mode if this order is violated**: if the new
     store starts accepting `append()` calls for an aggregate whose `event_tag_versions` row was never backfilled,
     the first CAS UPSERT for that aggregate creates a **fresh** row starting at version 1 (an `ON CONFLICT`/`ON
     DUPLICATE KEY` UPSERT against a missing row is just a plain INSERT) instead of continuing from its true
     historical version — silently colliding with/duplicating `tag_local_seq` values the migrated historical
     events already have, corrupting version numbering for that aggregate going forward with no error raised at
     the time it happens.
  4. **Defensive runtime check, belt-and-suspenders on top of the deploy-order requirement**: the store refuses
     the first `append()` for any `_aggregateInstance` tag it finds already has rows in `event_tags` (i.e. genuine
     history exists) but has **no** corresponding `event_tag_versions` row — a cheap existence check — and raises
     a loud `ConfigurationException` naming the aggregate and pointing at the migration command, rather than
     silently mis-numbering. This turns "ordering was violated" from a silent data-corruption risk into an
     immediate, actionable failure, consistent with the project's "clear exception messages that say what to do
     next" principle, without requiring a live marker-row/feature-flag mechanism this design doesn't otherwise
     need.
- **Existing `ecotone_projection_state.last_position` values for ProjectionV2 projections need one of two
  treatments, not a blanket "no action needed"**: if you were on `single`/`partition` strategy, positions are
  mechanically translated in place by the migration tool (old gap list dropped, §6.5/§6.7); if you were on
  `aggregate`/`simple`/`custom` strategy, every affected projection's position is force-reset and
  `ecotone:projection:rebuild` runs automatically as part of the migration (coordinate downtime/read-model staleness
  expectations around this — it is a full replay, not incremental).
- ProjectionV2 (`#[FromAggregateStream]`) users: no application-visible change; `#[FromStream(stream: 'x')]`
  users must migrate to `#[FromAggregateStream]` or a new `#[FromTags]` — plain physical stream names have no
  equivalent once there is one global log.
- Custom `Ecotone\EventSourcing\EventStore` implementations must implement the new interface
  (`read(Query, ?GlobalPosition, int): EventPage`, `append(array $events, ?AppendCondition): GlobalPosition`).
- `ecotone/pdo-event-sourcing` composer package is renamed `ecotone/event-sourcing`
  (`composer require ecotone/event-sourcing`, remove the old package); the PHP namespace `Ecotone\EventSourcing`
  is unchanged, so most `use` statements do not need to change — only the composer package name and any Prooph
  type-hints.
- Migrated data: events copied from an `aggregate`-strategy table keep their original `event_id` (uuid4, not
  re-minted as uuid7) — uuid7 is only guaranteed for events written *after* the upgrade. Do not rely on all
  `event_id`s being uuid7 in a store that has ever been migrated.
- **Database version floors — stated as a firm recommendation, verified against this repo's own composer/CI
  configuration, not left as an open question (D6, round-2 challenge review).** This design requires **PostgreSQL
  13+** (`xid8`/`pg_current_snapshot()`, §6.5) — `packages/Dbal/composer.json` pins `doctrine/dbal: "^3.9|^4.0"`
  (no engine-version constraint of its own), and neither `upgrade-2.0.md`'s "Minimum requirements" line nor any
  other repo file states a Postgres floor today; `docker-compose.yml`/`.github/workflows/test-monorepo.yml` both
  run `simplycodedsoftware/postgres:16.1` exclusively, comfortably above 13. **MySQL 8.0+** is recommended as the
  floor (no version-specific SQL feature this design needs beyond what 8.0 already has, since §6.2's portable
  query avoids the `INTERSECT` floor entirely) — CI and `docker-compose.yml` both run `mysql:8.0`. **MariaDB
  10.6+** is recommended (aligning with the multi-valued-index floor discussed and rejected in §6.1.2/§3.10, even
  though this design no longer uses that feature — 10.6 is a reasonable, unremarkable modern floor) **with an
  explicit caveat**: `docker-compose.yml` runs `mariadb:11.4` for local development, but **no CI workflow in this
  repository tests against MariaDB at all** (`grep -rlE mariadb .github/workflows/` → no matches) — MariaDB
  support for this design is therefore correspondingly less verified than Postgres/MySQL and should be documented
  as such (either add a MariaDB job to CI as part of Group D's implementation work, or explicitly label MariaDB
  support "best-effort" until it is tested). Add all three floors to `upgrade-2.0.md`'s "Minimum requirements"
  line as part of this group's PR — none is documented there today.
```

**The `## 4.` heading above is text to paste into `upgrade-2.0.md`, not a section of this report** — it is
deliberately fenced inside the ```markdown code block above precisely so it can be copied verbatim into that file,
where it will become that file's own `## 4.` heading in sequence with its other numbered sections; it is not a
(duplicate, out-of-order) heading of *this* report, whose own top-level sections are the `## 1.`–`## 10.` ones
outside this fence.

Mechanical hints for the migration:
- `sed`/Rector rule: `Ecotone\EventSourcing\EventStore\MetadataMatcher` usages that only match `_aggregate_type`/
  `_aggregate_id`/`_aggregate_version` can be mechanically rewritten to `Query::forTags(...)` — a Rector rule can
  detect the exact 2-3-clause pattern `EventSourcingRepository::findBy()` itself used (§1.7) and rewrite it, but
  arbitrary custom `MetadataMatcher` usage (custom `REGEX`/`IN` matches on other metadata fields) cannot be
  mechanically translated and needs manual review — flag these with a Rector "cannot auto-migrate, see docs" comment
  rather than silently leaving broken code.
- `withPersistenceStrategyFor()` per-stream overrides: grep for call sites (`rg
  "withPersistenceStrategyFor|withCustomPersistenceStrategy|withStreamPerAggregatePersistenceStrategy"`) — these
  are the users who most need the `ecotone:event-store:migrate-aggregate-streams` path and should be called out by
  name in migration communication (changelog, upgrade guide callout box), since they are also the deployments most
  likely to have genuinely large per-aggregate tables that make the migration nontrivial in wall-clock time.


---

## 9. Implementation plan

Ordered so each task is a single focused coding session; later tasks depend on earlier ones being merged.

1. **New store value objects** (no DB yet): `Query`, `TagCriteria`, `AppendCondition`, `GlobalPosition`,
   `EventPage` (§5.2/§6 — not `EventStream`, which is a pre-existing, different class, §5.3), the new
   `Ecotone\EventSourcing\Api\EventStore` interface (`read`/`append`, §5.2's namespace decision). Pure PHP,
   unit-testable in isolation.
2. **`#[Tag]` attribute + tag resolution service**: read `#[Tag]` off event class properties (constructor-promoted
   and plain) via `ClassDefinition`/reflection, running at the same pipeline point as
   `SaveAggregateServiceTemplate::enrichAggregateEvents()` (§6.9) — before payload serialization, on the live PHP
   object — plus automatic `aggregateType`/`aggregateId` tag derivation reusing the existing
   `AggregateTypeMapping`/`AggregateStreamMapping`-equivalent resolution, plus the internal `_aggregateInstance`
   compound tag (§6.9, D6 correction) derived alongside them from the same data, at zero extra cost.
3. **In-memory `EventStore` implementation** against the new interface — needed early so `EcotoneLite` tests for
   every subsequent task can run without a real DB. Replaces `Ecotone\EventSourcing\EventStore\InMemoryEventStore`.
4. **`event_tag_versions` value objects + the UPSERT-with-guard primitive** (§6.1.4/§6.1.5) in isolation, per
   engine (Postgres `ON CONFLICT DO UPDATE ... WHERE`, MySQL/MariaDB `ON DUPLICATE KEY UPDATE` with the exact
   affected-rows contract, §3.4's addendum, SQLite `ON CONFLICT DO UPDATE ... WHERE`), **including deterministic
   lock ordering** (sort `$capturedTagVersions` keys lexicographically before issuing UPSERTs, §6.1.5/D3) — this
   is the mechanism both `AppendCondition` (task 6) and `#[Version]`/`#[TargetVersion]` (task 10) depend on, so it
   is built and unit tested once, standalone, before either consumer, with a dedicated multi-tag deadlock test.
5. **Postgres DBAL implementation**: `event_log` + `event_tags` DDL (§6.1.1, uniform layout with MySQL/MariaDB,
   D4 — no JSONB tags column) + `EventStreamTableManager` rewrite (implements `Ecotone\Dbal\Database\DbalTableManager`,
   following the existing pattern exactly) + `read()`/unconditional `append()` SQL (§6.2's single portable query
   shape).
6. **Postgres conditional append + `ConcurrencyException`**: wire task 4's UPSERT primitive into `append()` for
   `AppendCondition` handling (§6.1.5), operating on `$capturedTagVersions` (§5.2/D1, not `GlobalPosition`) — no
   `SERIALIZABLE`, no dedicated transaction, runs inside the caller's existing one; integration tests for
   concurrent-append races (two real overlapping transactions against a test Postgres container, not mocked,
   asserting the second's `ConcurrencyException`), plus a test specifically for D2's over-approximation (a
   type-filtered condition still conflicts on an unrelated event type sharing the tag).
7. **Postgres gap detection**: `pg_snapshot_xmin`-based filtered read (§6.5, corrected direction) —
   `GapAwarePosition` is **not** needed on this engine (§6.5/§6.7), a plain integer position suffices; dedicated
   test that opens a slow transaction, lets a fast one commit around it, and asserts no event is skipped, plus a
   test that an idle-in-transaction session on an *unrelated* table stalls delivery as documented (§7).
8. **MySQL + MariaDB DBAL implementations**: `event_log` + `event_tags` side-table DDL (§6.1.2, explicit
   `utf8mb4_0900_bin` collation, C8) + the portable `read()` query (§6.2, no `INTERSECT`) + task 4's UPSERT
   primitive for `append()`'s `AppendCondition` (advisory-lock-free, no `SERIALIZABLE`, §6.1.5) + bounded-timeout
   gap detection (`GapAwarePosition` retained here, §6.5's fallback) with a loud log line when a gap is closed by
   timeout rather than proof.
9. **UUID v7 event ids**: this is narrower than originally scoped (§6.3 correction) — `SaveAggregateServiceTemplate`
   already mints `Uuid::v7()` (Symfony UID) for aggregate-originated events; only the store's own fallback
   (`EcotoneEventStoreProophWrapper`-equivalent default when no `MESSAGE_ID` is pre-supplied) needs to change from
   `ramsey/uuid`'s `uuid4()` to `uuid7()`, plus bumping the `ramsey/uuid` constraint to `^4.7` (§6.3). Worth a
   maintainer note on consolidating two UUID libraries (`ramsey/uuid` vs `symfony/uid`) rather than carrying both.
10. **`EventSourcingRepository`/`EventSourcedRepositoryAdapter` rewire** onto the new store (§5.3/§6.9): tag-query
    load via `readAggregateEvents(query, afterTagVersion:)`, `AppendCondition`-based save with
    `EVENT_AGGREGATE_VERSION` assigned from task 4's UPSERT return value; snapshot read/write logic in
    `EventSourcedRepositoryAdapter` is otherwise unaffected (still via `DocumentStore`), but its post-snapshot load
    query changes to the `tag_local_seq`-filtered form (§6.10).
11. **`EventSourcingConfiguration` rewrite**: drop the 4-strategy surface (§1.2, §5.4), add
    `withPhysicalPartitioning()` (§6.6) as a separate physical-only knob, keep `withSnapshotsFor` unchanged (lives
    on `BaseEventSourcingConfiguration`, package-agnostic already).
12. **ProjectionV2 stream sources**: rewrite `EventStoreGlobalStreamSource`/`EventStoreAggregateStreamSource` per
    the §6.7 mapping; `StreamFilter` field change (`streamName` → `tags`); coordinate the PR with Group B's
    `ProjectionV2`→`Projection` rename so both land together or in adjacent PRs against the same base.
13. **CLI**: register the new `EventStreamTableManager`/`event_tags`/`event_tag_versions` table managers with
    `DatabaseSetupManager` (§2.3, §5.5) so `ecotone:migration:database:setup` picks them up automatically; add
    `ecotone:event-store:migrate-aggregate-streams`, `ecotone:event-store:audit-legacy-metadata` (dry-run diagnostic,
    §8, D6 — reports how many aggregates fall into each of the three `simple`/`custom`-strategy buckets before a
    migration is committed to), and the engine-branching `ecotone:event-store:verify-gaps` (§7) as new console
    commands.
14. **Migration tool**: `ecotone:event-store:migrate-aggregate-streams` implementation — resumable, idempotent,
    dry-run mode (§7 "failure mid-migration", §8), **plus** the pieces C7/C4/C8/D6 add to its scope: (a) tag
    backfill from `_aggregate_type`/`_aggregate_id` metadata for every migrated event, with the three-bucket
    handling for `simple`/`custom`-strategy events missing it (§8); (b) `event_tag_versions`/`tag_local_seq`
    backfill from existing `_aggregate_version` metadata (or ordinal numbering by `global_position` as a fallback,
    §8), completed as an integral, non-skippable part of the command before it reports success (§8's numbered
    migration order) — **plus the defensive runtime check** (§8, D6): `append()` refuses a first write for an
    `_aggregateInstance` tag with existing `event_tags` history but no `event_tag_versions` row, raising a named
    `ConfigurationException` rather than silently mis-numbering; (c) `ecotone_projection_state.last_position`
    translation for `single`/`partition` strategies and force-reset-and-rebuild for `aggregate`/`simple`/`custom`
    strategies (§6.7/§8) — coordinate this specific piece with Group B, since it touches their state table and
    rebuild command.
15. **Delete Prooph**: remove `prooph/pdo-event-store` from `packages/PdoEventSourcing/composer.json`, delete every
    file under `Prooph/` (§2.1's Prooph-named classes), delete `Ecotone\EventSourcing\EventStore\{MetadataMatcher,
    FieldType,Operator}` once no call sites remain, remove `ramsey/uuid`'s Prooph-specific usage points if any are
    Prooph-only. Do this last, only after 1-14 are green, so there is a working non-Prooph path before the old one
    is deleted (avoids a period where the package has neither store implementation working).
16. **Package rename**: `ecotone/pdo-event-sourcing` → `ecotone/event-sourcing` (composer.json `name`, any
    inter-package `require` references, quickstart `composer.json` path-repo entries per
    `project_quickstart_path_repo_dbal` constraints already known from prior work in this repo) — coordinate
    timing with whether 2.0 keeps a transitional `replace`/meta-package for the old name (§10 Q7).

## 10. Open questions for the maintainer

1. **Resolved twice, now settled — confirm rather than re-litigate.** Round 1 moved from "normalised side table
   everywhere" to "hybrid: JSONB+GIN on Postgres, side table on MySQL/MariaDB," reasoning from Marten and
   `bwaidelich/dcb-eventstore-doctrine`'s Postgres choices (§3.4 Addendum 2). Round 2 (D4) found that reasoning
   incomplete: this design's `event_tag_versions`/`tag_local_seq` requirement (§6.1.4/§6.1.5/§6.9), which neither
   of those reference points needs, means a normalised `event_tags` side table has to exist on Postgres regardless
   — so JSONB there stops being an alternative to the side table and becomes a second, redundant physical copy of
   the same tags, pure added write cost. **Final recommendation: uniform normalised `event_tags` on all four
   engines (§6.1)** — no known way this is now known to be wrong; flag only if the maintainer disagrees with the
   underlying premise that `event_tag_versions` is worth having at all (in which case, revisit §6.1.5's whole
   mechanism, not just the table layout).
2. **Bounded wall-clock gap timeout on MySQL/MariaDB — what's an acceptable default, and should Ecotone refuse to
   boot (or warn loudly) if a deployment picks MySQL/MariaDB for event sourcing without acknowledging this
   limitation?** Recommendation: warn at bootstrap (not fail) with a message pointing at the docs section
   explaining the Postgres-vs-MySQL gap-detection asymmetry (§6.5) — consistent with the project's "clear
   exception messages that say what to do next" principle.
3. **Should `#[Tag]` support computed/derived tag values (e.g. a `HasTags` interface escape hatch, §5.1) in 2.0, or
   ship attribute-only and add the escape hatch later if requested?** Recommendation: ship attribute-only in 2.0;
   it covers every case seen in this codebase (`_aggregate_type`/`_aggregate_id` equivalents plus simple domain
   tags); add the interface later as a strictly additive feature if a real use case surfaces.
4. **DCB decision models (§6.8): confirmed as a 2.0 follow-up, not in scope?** Recommendation: yes, follow-up —
   but flag this decision explicitly to whoever scopes the *next* release so it isn't lost; it is the single most
   requested DCB capability in the prior-art literature (§3.1-3.3) and Ecotone's store API is designed not to block
   it.
5. **`EventStreamTableManager`'s licence inconsistency (§7 "Licence gating" row: `licence Enterprise` on the table
   manager vs `licence Apache-2.0` on `EventSourcingRepository`) — was Enterprise-gating the event *store* tables
   intentional, or a docblock copy-paste error?** This needs a maintainer decision before the rewrite, since it
   determines whether the new `event_log`/`event_tags` `DbalTableManager` carries a licence check. Recommendation:
   core/Apache-2.0 — event sourcing itself has never been gated; only multi-tenant projections and specific
   Enterprise integrations have been (Group A's scope, `ProophProjectingModule.php:227`).
6. **`#[FromStream]`/`#[FromAggregateStream]` are marked `licence Enterprise` today (§2.1) — carry that gate
   forward onto their tag-query equivalents unchanged?** Assumed yes (no evidence of an intent to change it) but
   not independently confirmed by this research pass; flagging because it directly affects §6.7's proposed
   `#[FromTags]` attribute's licence annotation.
7. **Package rename timing (`ecotone/pdo-event-sourcing` → `ecotone/event-sourcing`, §9 task 15): does 2.0 ship a
   transitional composer `replace`/meta-package so `composer require ecotone/pdo-event-sourcing` keeps working for
   one release, or is this a hard break on day one like the rest of 2.0?** Recommendation: hard break, consistent
   with 2.0's stated "this is the break" posture elsewhere (upgrade-2.0.md §13's explicit "Keep BC aliases out —
   2.0 is the break") — but confirm, since composer package renames are more disruptive to automated tooling
   (Dependabot, lockfiles) than PHP namespace renames.
8. **`upgrade-2.0.md`'s stated CLI command name `ecotone:database:setup` (line 223) vs the actual current
   `#[ConsoleCommand('ecotone:migration:database:setup')]` (`DatabaseSetupCommand.php:25`) — is Group F renaming
   the command, or is the upgrade guide simply wrong?** Needs reconciling regardless of Group D's own scope, since
   §5.5's new event-store CLI commands should match whatever the final naming convention is.
9. **Should `global_position` be exposed to user code at all (e.g. as a `#[Header]`-injectable value on event
   handlers), or remain purely internal to the store/projection machinery?** Not addressed by this research in
   depth; relevant because some users may want to record "as-of position" for audit/debugging purposes the way
   `MessageHeaders::EVENT_AGGREGATE_VERSION` is already exposed today (§5.3). Recommendation: expose it as an
   optional `#[Header]`-style metadata value on events (read-only, store-assigned), mirroring how
   `EVENT_AGGREGATE_VERSION` is exposed, for consistency and debuggability — but this needs maintainer sign-off on
   whether it's worth the API surface.
10. **Closed in this revision — no spike needed.** An earlier draft left "worth a deeper spike on
    `bwaidelich/dcb-eventstore`'s DBAL adapter before committing to building from scratch" open, resting the
    build-vs-adopt call on an unaudited premise. This revision reads that adapter's actual source
    (`bwaidelich/dcb-eventstore-doctrine`, §3.4 Addendum 2) rather than only its interface package, and finds two
    concrete, code-level disqualifiers rather than a vague risk: (i) its Postgres concurrency mechanism demands its
    own top-level `SERIALIZABLE` transaction, incompatible with Group F's one-shared-transaction-per-message
    architecture without either widening isolation scope or breaking that invariant; (ii) it indexes tags on
    Postgres only, leaving MySQL/MariaDB with unindexed `JSON_CONTAINS` table scans on every tag query. Neither
    finding would change with more spike time — they are structural properties of the adapter's design, not gaps
    in its documentation. **Recommendation stands, now closed rather than conditional: build native (§4).** Its
    concurrency technique (`SERIALIZABLE`+retry, MySQL advisory lock) remains valuable prior art and is compared
    against directly in §6.1.5's mechanism design, which recommends a third approach (Marten-style per-tag CAS)
    specifically because it avoids both of this library's disqualifying properties.
11. **Resolved in this revision (D6) — no longer an open question, a firm recommendation.** §8 now states the
    proposed floors directly: **PostgreSQL 13+**, **MySQL 8.0+**, **MariaDB 10.6+**, verified against this repo's
    own `docker-compose.yml`/CI (`.github/workflows/test-monorepo.yml` runs Postgres 16.1 and MySQL 8.0; neither
    the repo nor CI documents a floor today). The one genuinely open item folded into this: **no CI workflow in
    this repository tests against MariaDB at all** (only `docker-compose.yml`'s local dev environment does) — the
    maintainer should decide whether Group D's PR adds a MariaDB CI job (making the 10.6+ recommendation as
    verified as the other two engines) or ships with MariaDB explicitly labelled best-effort/community-tested
    until that gap is closed separately.
12. **New in this revision (§6.3): two UUID libraries doing overlapping work** — `ramsey/uuid` (the event store's
    own fallback default) and `symfony/uid` (already used by `SaveAggregateServiceTemplate` for aggregate-originated
    event ids). Worth consolidating on one during this rewrite, or is carrying both an accepted, pre-existing
    condition outside Group D's scope to fix? Recommendation: consolidate on `symfony/uid` for anything new this
    rewrite touches (it is already the one actually used on the hot aggregate-save path today), but this is a
    judgment call, not forced by anything in this report's technical findings.
13. **New in this revision (§5.2): confirm the `Ecotone\EventSourcing\Api\*` namespace placement for `Query`,
    `AppendCondition`, `GlobalPosition`, `EventStore`, and `#[Tag]`** — this report places them there per Group H's
    rule (public API a decision model touches, §6.8), but Group H's own execution is scoped as "last, touches
    everything" (release-design spec) and may land after Group D. If Group H hasn't landed yet when Group D ships,
    should these value objects launch under a temporary `Ecotone\EventSourcing\*` (bare) location and get moved
    when Group H runs, or should Group D pre-adopt the `Api` convention early specifically for these new classes
    (since they have no BC baggage to preserve, unlike everything Group H is moving)? Recommendation: pre-adopt —
    there is no cost to placing brand-new classes correctly the first time, only cost to moving them later.
14. **New in this revision (D2): sign off explicitly on the conservative-over-approximation semantics of
    `AppendCondition`, since it is the single largest semantic divergence from the dcb.events spec this report
    otherwise tracks closely (§3.2).** `event_tag_versions`-based CAS conflicts on *any* event carrying a captured
    tag, not only ones matching a condition's `ofTypes()` filter (§6.1.5/§6.9) — safe (never misses a real
    conflict) but can raise spurious `ConcurrencyException`s the spec itself would not. This is inert for 2.0's
    actual shipped surface (single-aggregate loading never uses a type-filtered captured tag) and only matters once
    the decision-model layer (§6.8) ships. Recommendation: accept as documented, revisit the finer
    `(tag_key, tag_value, event_type)`-keyed alternative (§6.1.5) only if and when a real decision-model use case
    demonstrates the false-positive rate actually matters in practice — do not build the more expensive mechanism
    speculatively.

