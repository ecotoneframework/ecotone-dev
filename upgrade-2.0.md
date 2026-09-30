# Upgrading to Ecotone 2.0

This guide lists every behaviour change between Ecotone 1.x and 2.0. Each entry describes how it worked before,
how it works now, and what to change in your application. Entries are grouped the way the work is grouped in
the release; within a group the most impactful changes come first.

Minimum requirements: PHP 8.2 (8.4 for the Tempest integration), Symfony 6.4+, Laravel 11+, Doctrine DBAL 4 and,
where used, Doctrine ORM 3 with DoctrineBundle 2.12+. Laravel 9/10, DBAL 3 and ORM 2 are no longer supported.

**Status of this guide.** Sections 1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 13, 14 and 15 describe behaviour that is already in
the codebase. Section 12 is **planned for 2.0 and not implemented yet** — it is marked individually below.
Do not act on a planned section until it ships; the API it describes does not exist. Items marked **TODO** inside an
implemented section are known gaps that are not done yet. Section 10 records behaviour that was considered for change
and deliberately kept as it is. Section 16 lists the larger 2.0 work that is still to be done, each with the path to
its design and implementation plan in this repository.

---

## 1. Asynchronous processing is part of Core

**Before:** The `asynchronous` module package was optional. `ServiceConfiguration::withSkippedModulePackageNames()`
could disable it, and `EcotoneLite::bootstrapFlowTesting()` disabled it by default, so handlers marked
`#[Asynchronous('orders')]` were executed **synchronously** inside tests unless `enableAsynchronousProcessing: true`
(or a list of channel builders) was passed.

**Now:** Asynchronous handling, pollable-channel serialization and send-retries are always loaded. An
`#[Asynchronous]` handler is never executed inline — not in production, not in tests. In the application
(`EcotoneLite::bootstrap()`, Symfony, Laravel, Tempest) the channel named in the attribute must be configured, otherwise
bootstrap fails with `ConfigurationException`. In `EcotoneLite::bootstrapFlowTesting()` every `#[Asynchronous]` channel
the test does not configure gets an in-memory, delayable queue channel, so the test only has to consume it with `run()`.

**How to adapt:**

```php
// 1.x test
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderHandler::class]);
$ecotone->sendCommand(new PlaceOrder('1'));
self::assertCount(1, $ecotone->sendQueryWithRouting('orders.all')); // handler ran inline

// 2.0 test
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderHandler::class], [new OrderHandler()]);
$ecotone->sendCommand(new PlaceOrder('1'));
$ecotone->run('orders');                                            // consume the channel explicitly
self::assertCount(1, $ecotone->sendQueryWithRouting('orders.all'));
```

- Remove `enableAsynchronousProcessing` arguments. Flow tests get an in-memory delayable queue for each `#[Asynchronous]`
  channel; register a channel yourself (extension object or `#[ServiceContext]`) only when the test needs a different one
  — for example `SimpleMessageChannelBuilder::createQueueChannel('orders', delayable: false)` or a DBAL/AMQP channel. A
  configured channel replaces the provided one.
- Remove `ModulePackageList::ASYNCHRONOUS_PACKAGE` from any skip list and delete calls to
  `ServiceConfiguration::createWithAsynchronicityOnly()`.
- `withSkippedModulePackageNames([...])` is replaced by `withModulePackages([...])` listing the packages to **load**;
  Core and Asynchronous are always loaded. Example: `->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE])`.
- Symfony `ecotone.yaml`, Laravel `config/ecotone.php` and Tempest config: the `skippedModulePackageNames` key is renamed to
  `modulePackages` and now lists packages to load (Core and Asynchronous are implicit). Leaving the key out loads every
  installed package; an explicit empty list (`modulePackages: []`) loads Core and Asynchronous only.
- A handler referencing an unregistered channel fails at bootstrap with
  `ConfigurationException: Registered asynchronous endpoint \`orderHandler\`, however channel configuration for \`orders\` was not
  provided. Register it with SimpleMessageChannelBuilder::createQueueChannel('orders') as a ServiceConfiguration extension object
  or from a #[ServiceContext] method.`
  Flow tests (`bootstrapFlowTesting()`) do not throw this; they provide the in-memory channel instead.
- Default queue channels are delayable (see §9); nothing to change unless you relied on delays being ignored.

## 2. Multi-tenancy requires Ecotone Enterprise

**Before:** `MultiTenantConfiguration`, `#[MultiTenantConnection]`, `#[MultiTenantObjectManager]`,
`#[OnTenantActivation]`, `#[OnTenantDeactivation]` and the Laravel/Symfony/Tempest tenant connection switching worked
without a licence. Only `#[WithTenantResolver]` and multi-tenant projections were gated.

**Now:** Any multi-tenant configuration or attribute requires a valid Enterprise licence key. Without it bootstrap
throws `LicensingException` ("Multi-tenancy requires Ecotone Enterprise licence … See https://docs.ecotone.tech/enterprise") naming
the offending `MultiTenantConfiguration` or attribute placement. Laravel's tenant database switching is registered only when a
Laravel-backed `MultiTenantConfiguration` exists and is licensed; Symfony and Tempest rely on the same core gate.

**How to adapt:** Provide the licence key (`ServiceConfiguration::withLicenceKey()`, `ecotone.licenceKey` in Symfony,
`ECOTONE_LICENCE_KEY` / `config/ecotone.php` in Laravel, `licenceKey:` argument of `EcotoneLite`). If you are not
licensed, replace multi-tenant connection switching with explicit per-tenant connection references and route
messages yourself.

## 3. Projections: v1 (Prooph-based) removed, `ProjectionV2` renamed to `Projection`

**Before:** Two projection systems coexisted: the Prooph-based v1 (`Ecotone\EventSourcing\Attribute\Projection`
with `fromStreams`/`fromCategories`/`fromAll`, `ProjectionManager`, `ProjectionRunningConfiguration`, the
`ecotone:es:*` console commands, `FlowTestSupport::initializeProjection()`, `resetProjection()`,
`stopProjection()`, `deleteProjection()`, `triggerProjection()`), and the new v2 (`Ecotone\Api\ProjectionV2`).

**Now:** Only the new system exists and it is called `#[Projection]` (`Ecotone\Api\Projecting\Projection`). v1's
Prooph-based projection runtime, its lifecycle configuration classes and its `ecotone:es:*` console commands are
gone entirely. Projection state lives in the v2 state table (`ecotone_projection_state`), not the Prooph
`projections` table. The event *store* is a separate change — see §4, where Prooph goes away entirely.

**How to adapt:**

```php
// 1.x
#[Projection('order_list', fromStreams: Order::class)]
final class OrderListProjection { #[EventHandler] public function when(OrderPlaced $e): void {} }

// 2.0
#[Projection('order_list')]
#[FromAggregateStream(Order::class)]
final class OrderListProjection { #[EventHandler] public function when(OrderPlaced $e): void {} }
```

- Rename `#[ProjectionV2]` → `#[Projection]` (namespace `Ecotone\Api`, no `\Attribute\` segment — see §13).
- `fromStreams: Aggregate::class` → `#[FromAggregateStream(Aggregate::class)]`; `fromStreams: 'some_stream'` → one
  `#[FromStream('some_stream')]` per stream (repeatable). `#[FromStream]` pointing at an event-sourced aggregate class
  is rejected — see §4. `fromCategories: $x` → `#[FromAggregateStream($aggregateClass)]`
  when the category was actually one aggregate type's stream prefix (the common case); there is no direct
  replacement for reading unpartitioned, across all instances of an aggregate-per-stream aggregate — that needs
  `#[Partitioned]` and per-partition state instead. `fromAll` has **no replacement** — every projection must
  declare an explicit `#[FromStream]`/`#[FromAggregateStream]`, so a forgotten filter cannot silently scan the
  whole log.
- v1 capabilities with **no 2.0 replacement**: glob-pattern event routing inside a projection (e.g.
  `#[EventHandler('order.*')]`) and several handlers in one projection reacting to the same event — a projection now
  routes each event to exactly one handler, so split such logic into separate handlers keyed by event class or into
  separate projections; and Prooph's `MetadataMatcher` filtering and configurable `GapDetection` retry schedule on
  projections — gap handling is built in (`GapAwarePosition`) and has no user configuration.
- Code that injected the v1 `ProjectionManager` gateway (`Ecotone\EventSourcing\ProjectionManager`) injects
  `Ecotone\Api\Projecting\ProjectionRegistry` instead and works with `ProjectionRegistry::get('order_list')`, which returns a
  `ProjectingManager`: `init()`, `execute()`, `executeWithReset()`, `prepareRebuild()`, `prepareBackfill()` and
  `delete()` cover initialise, run, reset, rebuild, backfill and delete.
- Replace `ProjectionRunningConfiguration` / `ProjectionSetupConfiguration` / `ProjectionLifeCycleConfiguration`
  with `#[Polling(endpointId:)]`, `#[Streaming]`, `#[Partitioned]`, `#[Asynchronous]` on the projection class.
  `#[ProjectionInitialization]`/`#[ProjectionReset]`/`#[ProjectionDelete]` keep their names and move to
  `Ecotone\Api`.
- Console: the `ecotone:es:*` commands are removed with no direct replacement for `reset-projection`,
  `trigger-projection` or `stop-projection`. The nearest v2 surface is `ecotone:projection:init|backfill|rebuild|delete`
  (`ProjectingManager::executeWithReset()`/`execute()` exist programmatically but are not exposed as console
  commands).
- `#[ProjectionState]` parameters must declare a default value (e.g. `array $state = []`, or
  `MyState $state = new MyState()`) — the framework passes `null` before a partition has any state, and a
  required, non-nullable parameter with no default cannot accept that.
- `EventStreamEmitter::emit()` (not `linkTo()`) requires an Enterprise licence: it tags the emitted event with
  the projection's own name, and that header is only populated under a valid licence.
- Tests: `FlowTestSupport::triggerProjection()`/`resetProjection()`/`initializeProjection()`/`deleteProjection()`
  now call the v2 `ProjectingManager` directly and synchronously — there is no more queued/asynchronous delay
  before they take effect, so a test that relied on "nothing happens until the next `->run()`" needs to drop
  that intermediate `->run()` call. `initializeProjection()` drops its unused `$metadata` parameter.
  `stopProjection()` is removed with no replacement: stop a `#[Polling]` projection in a test by not calling
  `->run($endpointId)` again; a purely synchronous or async-channel projection has no "stop" concept to begin
  with. `deleteProjection()` no longer cascades into deleting a projection's `EventStreamEmitter`-emitted events,
  and `initializeProjection()` after a delete only recreates empty state — it does not replay history (use
  `resetProjection()` for that).
- `EventSourcingConfiguration`'s projection-table-name and projection-manager-reference configuration are
  removed; only event-store-meaningful configuration remains.

## 4. Event Store: Ecotone owns the store, one event stream table by default

**Before:** `ecotone/pdo-event-sourcing` wrapped `prooph/pdo-event-store`. Every stream name was hashed into its own
physical table (`_<sha1(streamName)>`), and an `event_streams` catalogue table mapped stream name → table. Four
persistence strategies existed (`simple`, `single`, `aggregate`, `partition`), selected on `EventSourcingConfiguration`.
Tables were created at runtime, during the first message that touched a stream.

**Now:** Ecotone ships its own DBAL-backed event store. There is one layout, and **a stream name is the name of its
table**. Aggregates that do not say otherwise all write to a single table, `ecotone_event_stream`, ordered by a global
`no` sequence. There is no `event_streams` catalogue and no sha1 hashing. `Prooph\*` classes are no longer available and
`prooph/pdo-event-store` (with `prooph/event-store` and `prooph/common`) is no longer a dependency.

Tags, `AppendCondition` and Dynamic Consistency Boundary querying are built on top of this layout — see the DCB
subsection below.

**How to adapt:**

- Delete `withSingleStreamPersistenceStrategy()`, `withPartitionStreamPersistenceStrategy()`,
  `withStreamPerAggregatePersistenceStrategy()`, `withSimpleStreamPersistenceStrategy()`, `withPersistenceStrategyFor()`
  and `withCustomPersistenceStrategy()`. They are gone; the single-table layout is the only one.
- `#[Stream]` (`Ecotone\Api\EventSourcing\Stream`) keeps its name and gains two arguments:

  ```php
  #[EventSourcingAggregate]
  #[Stream('orders_stream', connectionReferenceName: DbalConnectionReference::DEFAULT)]
  final class Order { /* ... */ }
  ```

  The first argument is the stream name **and** the table name. `connectionReferenceName` lets one aggregate live on a
  different DBAL connection. Aggregates with no `#[Stream]` use `ecotone_event_stream`.
- **Keeping your 1.x data where it is.** A 1.x stream lives in `_<sha1(streamName)>`, where the stream name was your
  `#[Stream]` value or, if you had none, the aggregate class name. Point the aggregate at it and nothing has to move:

  ```php
  #[EventSourcingAggregate]
  #[Stream(legacyStreamName: 'App\Domain\Order')]   // reads and appends to _<sha1('App\Domain\Order')>
  final class Order { /* ... */ }
  ```

  The legacy table keeps its 1.x schema — Ecotone writes the same five columns (`event_id`, `event_name`, `payload`,
  `metadata`, `created_at`) — so there is no migration, no downtime and no new command to run. Once every stream is
  addressed, the orphaned `event_streams` catalogue table can be dropped by hand; nothing reads it any more.
  You can also name a table directly (`#[Stream('_a94a8fe5ccb19ba61c4c0873d391e987982fbbd3')]`); `legacyStreamName`
  only saves you computing the hash.
- **If you were on the `aggregate` (stream-per-aggregate) layout,** there is one table per aggregate *instance*
  (`_sha1('Order-123')`). No attribute can address those. Copy their rows into one table — the column layout is
  unchanged, so `INSERT INTO ecotone_event_stream (event_id, event_name, payload, metadata, created_at) SELECT
  event_id, event_name, payload, metadata, created_at FROM "_sha1(...)" ORDER BY no` per table, ordered by original
  `created_at` across tables — and then leave the aggregate on the default stream.
- **If you were on `simple`,** events without aggregate metadata are still accepted: in the new schema
  `aggregate_id`/`aggregate_type`/`aggregate_version` are nullable, so one table serves both aggregate events and
  `EventStreamEmitter` events. `aggregate_id` widened from `CHAR(36)` to `VARCHAR(150)`, so non-UUID aggregate
  identifiers are no longer a hazard on MySQL/MariaDB.
- **Projections.** `#[FromStream]` is only needed when the stream is not the default one. Pointing it at an
  event-sourced aggregate class is now a configuration error:

  ```php
  #[Projection('order_list')]
  #[FromStream(Order::class)]         // 1.x / early 2.0 — now throws
  #[FromAggregateStream(Order::class)] // 2.0 — resolves the aggregate's stream and filters by aggregate type
  ```

  This matters because several aggregates now share one table: without the aggregate-type filter a projection would
  see everybody's events. `#[FromAggregateStream]` supplies it; `#[FromStream('name', aggregateType: ...)]` is the
  explicit form.
- **Appending an aggregate-less event that a `#[Partitioned]`/aggregate-scoped projection subscribes to is now a
  configuration error.** Such a projection reads its stream filtered by aggregate type, so it would silently never
  see an event recorded without aggregate metadata (e.g. via `EventStore::appendTo()` directly, or a DCB decision
  model). `appendTo()` now throws `ConfigurationException` naming the projection and event; fix it by giving the
  event an aggregate, or by having a global `#[FromStream]` projection handle it instead.
- **`EventStreamEmitter`.** `emit()` writes to the emitting class's `#[Stream]`, defaulting to `ecotone_event_stream`;
  it no longer invents a `projection_<name>` stream. `linkTo($streamName, ...)` still takes an explicit target, but the
  stream must be declared by a `#[Stream]` attribute somewhere — an unknown name is a configuration error instead of a
  table created behind your back.
- **Tables are declared, not discovered.** Every stream table — including one declared with a non-default
  `connectionReferenceName` — is registered with `ecotone:migration:database:setup` under the `event_stream` feature,
  grouped by the connection it lives on; see §8 for the `--connection` option and how the missing-table error names the
  right connection and command. In tests and dev (`DbalConfiguration` automatic table initialization) a missing table
  is still created on first write, on whichever connection the stream is declared for.
- `EventStreamingChannelAdapter::create(fromStream: ...)` takes a stream name, not an aggregate class; pass
  `aggregateType:` to filter.
- **`EventStore` gains two methods and a parameter; `TaggedEventStore` and `AggregateEventStore` are gone.**
  **Before:** `EventStore::appendTo(string $streamName, array $streamEvents): void` took no condition. Loading a
  single aggregate's events, or loading/appending by tag, went through two separate interfaces —
  `Ecotone\EventSourcing\EventStore\AggregateEventStore::loadAggregateEvents()` and, Enterprise only,
  `Ecotone\Api\EventSourcing\TaggedEventStore::load()`/`appendTo()`. **Now:** both are folded into `EventStore`
  itself:

  ```php
  interface EventStore
  {
      public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void;
      public function appendTo(string $streamName, array $streamEvents, ?AppendCondition $appendCondition = null): void;
      public function delete(string $streamName): void;
      public function hasStream(string $streamName): bool;
      public function load(string $streamName, int $fromNumber = 1, ?int $count = null, ?MetadataMatcher $metadataMatcher = null, bool $deserialize = true): iterable;
      public function loadAggregateEvents(string $streamName, ?string $aggregateType, string $aggregateId, int $fromVersion = 1, ?int $count = null, array $eventNames = [], bool $deserialize = true): iterable;
      public function loadByCriteria(EventCriteria $criteria): LoadedEvents;
  }
  ```

  `appendTo()` gains a third, optional `?AppendCondition $appendCondition = null` parameter — every existing call
  site without a third argument is unaffected. `loadByCriteria()` takes a single `EventCriteria` rather than a
  variadic list — an OR of several criteria is now expressed on `EventCriteria` itself via `->or()` (see below), so
  the method stays reachable through the `EventStore` *gateway*, which has no support for variadic parameters.
  **How to adapt a custom `EventStore` implementation:** add `loadAggregateEvents()` and `loadByCriteria()` (delegate
  to your existing aggregate-loading and tag-index code, or throw if you don't support tags), and widen
  `appendTo()`'s signature with the new optional parameter. Replace
  `Ecotone\EventSourcing\EventStore\AggregateEventStore` type-hints with plain `EventStore` — the method moved, the
  type did not gain a second interface to intersect. Replace `Ecotone\Api\EventSourcing\TaggedEventStore` the same
  way: `$taggedEventStore->load($criteria)` becomes `$eventStore->loadByCriteria($criteria)`,
  `$taggedEventStore->appendTo(...)` becomes `$eventStore->appendTo(...)` unchanged. `TaggedEventStore` and
  `AggregateEventStore` are deleted, along with their `Dbal`/`InMemory` adapter classes — there is one store, one
  interface.
- **`AppendCondition` now expresses an aggregate's optimistic-lock expectation too, not only tags — and the
  append path is licence-split.** **Before:** an aggregate save's concurrency check was purely the
  `(aggregate_type, aggregate_id, aggregate_version)` unique index; `AppendCondition` only ever carried tag
  expectations (Enterprise). **Now:** `AppendCondition::forAggregate(string $aggregateType, string $aggregateId, int
  $expectedVersion)` builds a condition from the version an aggregate was loaded at (`0` for a new one); a condition
  can carry an aggregate part, a tag part, both (via `mergeWith()`), or neither. `EventSourcingRepository::save()`
  (Pdo) and `InMemoryEventSourcedRepository::save()` now build this condition themselves and pass it to
  `appendTo()` — the unique index is still what actually enforces it on PostgreSQL/MySQL/MariaDB/SQLite, and
  `InMemoryEventStore` gained an explicit version comparison it did not have before (previously an in-memory
  aggregate save never raised `ConcurrencyException` at all). Internally, appending now goes through an **append
  strategy**, chosen once at bootstrap: the open-core strategy (`licence Apache-2.0`, what runs whenever
  `DynamicConsistencyBoundaryConfiguration` is not registered) handles the aggregate part only and rejects a
  hand-built condition carrying a tag part with the "Dynamic Consistency Boundary is disabled" `ConfigurationException`;
  the Enterprise strategy (chosen when the extension object is registered) handles the tag part (the counters-first
  protocol) and delegates the aggregate-only case to the open-core strategy. **How to adapt:** nothing, unless you called `AppendCondition`'s constructor-adjacent
  factories directly — `empty()` and `fromCapturedVersions()` are unchanged, `forAggregate()` is additive. Without
  the extension object, the DBAL append path stays byte-for-byte today's single `INSERT` — the aggregate
  condition costs nothing beyond the unique index that was already there.
- **The write-lock option is gone.** `EventSourcingConfiguration::withWriteLockStrategy(bool)` and
  `isWriteLockStrategyEnabled()` are removed. **Before:** an opt-in advisory lock (Postgres) / `GET_LOCK` (MySQL) held
  around the insert, meant to shrink the window for gaps in `no`. **Now:** it is gone outright — concurrency was
  always the `(aggregate_type, aggregate_id, aggregate_version)` unique index, not the lock, and `GapAwarePosition`
  already tolerates the gaps the lock used to shrink. It defaulted to off, so most applications see no behaviour
  change; delete any call to `withWriteLockStrategy()`.
- **`EventStore::create()` now respects automatic table initialization like every other method.** **Before:**
  `create()` always created the table if it was missing, even with automatic table initialization off (`AutoCreateLevel::None`,
  §8). **Now:** a missing table raises the same `ConfigurationException` `appendTo()` already raised, naming the
  `event_stream` feature, the table, and the `ecotone:migration:database:setup` command to run. **How to adapt:** if
  you call `create()` directly against a database with automatic table initialization off, run
  `ecotone:migration:database:setup --initialize` (or the equivalent for your integration, §8) first, the same as
  you already do for `appendTo()`.
- **SQLite is now a supported event store engine**, alongside PostgreSQL, MySQL and MariaDB — nothing to adapt, it is
  additive. **`ecotone:event-store:backfill-tags` on SQLite needs `RETURNING`, added in SQLite 3.35 (2021-03).** The
  backfill's counter bump reads the new version back via `... RETURNING version` on every engine that supports it; an
  older bundled `libsqlite3` (PHP's own SQLite extension, not a system package) raises a plain SQL syntax error rather
  than a named exception — check `SQLite3::libversion()` / `PDO::sqliteVersion` against 3.35 before running the
  backfill on SQLite. Appends and decision models do not use `RETURNING`.
- Internal, nothing to adapt: the "licence BSD-3-Clause / code comes from prooph/pdo-event-store" headers are gone
  from the schema and store classes — the DDL is Ecotone's own now. `EventSourcingRepository::findBy()` and the
  partitioned-projection aggregate stream source no longer build a `MetadataMatcher` internally; they call
  `EventStore::loadAggregateEvents()` instead (see above — folded in from the now-deleted `AggregateEventStore`).
  `MetadataMatcher`, `FieldType` and `Operator` are unchanged and still public — `EventStore::load()`'s signature
  did not change. `EventSourcingRepository::save()` builds the aggregate's `AppendCondition` from
  `versionBeforeHandling`, merges in any decision model's condition found on the save metadata, and passes the
  result to the event store's `appendTo()`, the same seam `InMemoryEventSourcedRepository` already used — this is
  what makes a `#[DecisionModel]` injected into an `#[EventSourcingAggregate]` command handler (§4's DCB subsection)
  actually enforce its condition against PostgreSQL/MySQL/MariaDB/SQLite, not only against `InMemoryEventStore`.
  `InMemoryEventStore` and `DbalEventStore` no longer default their `AppendStrategy`/tag-collaborator/
  `ProjectionInvariantGuard` constructor arguments to `null`, and `InMemoryEventSourcedRepository` no longer takes an
  optional `EventStore` — a repository backed by one is `EventStoreEventSourcedRepository` instead; only code that
  constructed these directly (bootstrap wiring does it for you) needs to pass the collaborator explicitly.

**Schema of `ecotone_event_stream`** (PostgreSQL; MySQL/MariaDB use generated columns for the three aggregate fields;
SQLite uses expression indexes over `json_extract(metadata, '$._aggregate_type')` and friends, with no
`AUTOINCREMENT` on `no`):

```sql
CREATE TABLE ecotone_event_stream (
    no BIGSERIAL,
    event_id UUID NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    metadata JSONB NOT NULL,
    created_at TIMESTAMP(6) NOT NULL,
    PRIMARY KEY (no),
    UNIQUE (event_id)
);
CREATE UNIQUE INDEX ... ON ecotone_event_stream
    ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), (metadata->>'_aggregate_version'));
CREATE INDEX ... ON ecotone_event_stream
    ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), no);
```

The unique index is what enforces optimistic concurrency; rows without aggregate metadata do not collide because NULLs
are distinct on all three engines.

### Dynamic Consistency Boundary (DCB) — decision models, Enterprise

**Before:** No cross-aggregate consistency mechanism existed; enforcing an invariant that spans more than one
aggregate instance (a coupon redemption limit, a unique username) meant either a saga with compensating actions or a
pessimistic lock outside Ecotone's control.

**Enabling.** DCB is off until you register its extension object. Without it Ecotone behaves exactly as a
1.x application with `#[EventTag]` unknown to it:

```php
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;

#[ServiceContext]
public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
{
    return DynamicConsistencyBoundaryConfiguration::createWithDefaults();
}
```

- **Registered:** everything below. Registering it without an Enterprise licence is a `LicensingException` at
  bootstrap, before any message is handled. It is also where DCB is configured: every option is a `with*` method on
  this one object (today: `withFilterOnlyTags()`, below).
- **Not registered:** a `#[DecisionModel]` class, a `#[DecisionBoundary]` method, or a handler injecting a decision
  model is a `ConfigurationException` at bootstrap ("Dynamic Consistency Boundary is disabled. Register
  DynamicConsistencyBoundaryConfiguration::createWithDefaults() as an extension object (#[ServiceContext]) to enable
  decision models, event tags and append conditions."). `EventStore::loadByCriteria()`, `appendTo()` with a
  tag-bearing `AppendCondition`, `ecotone:event-store:backfill-tags` and `ecotone:event-store:verify-schema` throw the
  same exception at runtime. Events carrying `#[EventTag]` are stored as plain rows — a single `INSERT`, no tag
  tables, no counters — and load by stream or aggregate as usual; the `event_tags` setup feature is not listed and
  no tag table is created or verified.

**Aggregate saves are guarded on their tags.** With DCB enabled, every append of tagged events — an
`#[EventSourcingAggregate]` save included, whether or not a decision model is injected — captures each tag's
version with a consistent `SELECT` inside the transaction and bumps it with the guarded `UPDATE ... WHERE version =
:captured`. On `REPEATABLE READ` (InnoDB) the captured version is the one the transaction's snapshot saw when the
aggregate was loaded, so a competing commit made after the load fails the save with
`DecisionModelConcurrencyException` (a retry then sees it); on `READ COMMITTED` (the PostgreSQL default) the capture
is current. An explicit `AppendCondition`/decision-model version always wins over the captured one for the same tag.
The counters are never bumped blindly any more, except by `backfill-tags`.

**Aggregates are inside the boundary.** With DCB enabled, every aggregate class — `#[EventSourcingAggregate]` and
state-stored `#[Aggregate]` alike; `#[Saga]` and `#[EventSourcingSaga]` are process managers and are exempt — keeps
one counter per instance in `ecotone_tag_versions`: tag name `aggregate_<AggregateType>`, tag value the aggregate's
identifier (the string the stream's `aggregate_id` column stores; a composite identifier is the JSON map of its
parts). Every save of the aggregate bumps it with the same guarded `UPDATE` a tag uses. The counter is never indexed:
`loadByCriteria()` on it returns no events, only its captured version, and the aggregate is still loaded from its
own stream or repository, snapshots included.

- **`#[AggregateType]` is required on every aggregate** once DCB is registered — a bootstrap
  `ConfigurationException` names the class otherwise. For an event-sourced aggregate with existing history, declare
  the type its stream already stores (the fully qualified class name unless you declared one), or its history no
  longer loads. `aggregate_<AggregateType>` must fit the 100-character `tag_name` column (a longer type is a
  bootstrap error pointing at a shorter `#[AggregateType]`), and no `#[EventTag]` may be named like an aggregate's
  counter tag. Not registered: nothing changes — no requirement, no counters, a single `INSERT`.
- **Event-sourced saves** bump the counter in the same sorted pass as the events' tags; the stream's unique index on
  `(aggregate_type, aggregate_id, aggregate_version)` stays the aggregate's own guard for its own events. Two
  concurrent saves of the *same* aggregate therefore usually fail on that index, as a plain `ConcurrencyException`
  with the database's message; on `REPEATABLE READ` (InnoDB) the counter catches it first and the message names the
  aggregate.
- **State-stored aggregates get an optimistic lock — new behaviour.** The counter is captured before the repository's
  `findBy()` and bumped guarded before the repository's `save()`. Two concurrent commands on one instance, which
  used to end in a silent last-write-wins, now let one succeed and fail the other with
  `DecisionModelConcurrencyException` — configure retry (below). The aggregate's own `#[Version]` property is not
  used for this and behaves as before. The repository must write on the event store's connection so the counter
  and the aggregate commit together: a Dbal document-store or Doctrine ORM repository on another connection is a
  bootstrap `ConfigurationException`; for a repository Ecotone cannot inspect (your own, Eloquent, Tempest) this is
  your responsibility.
- **Opting a state-stored aggregate out of the new lock.** If callers of a particular state-stored aggregate rely on
  last write wins, name it and it keeps no counter at all — nothing captured at `findBy()`, nothing bumped at
  `save()`, exactly the 1.x behaviour:

  ```php
  return DynamicConsistencyBoundaryConfiguration::createWithDefaults()
      ->withoutOptimisticLockFor([ImportedContact::class, LegacyBasket::class]);
  ```

  It still declares `#[AggregateType]`, because that is the name its counter tag would carry and the exclusion can be
  lifted later. Fetching an excluded aggregate into a decision-model handler is a bootstrap `ConfigurationException`:
  the decision would rest on state nothing guards. A `#[DecisionBoundary]` whose criteria name only excluded
  aggregates is refused the same way, when it is evaluated rather than at bootstrap, because the criteria are built
  at runtime; mixing in a counted tag or an aggregate outside the opt-out is allowed and guards on those.
  Naming an `#[EventSourcingAggregate]` is a bootstrap error too —
  its stream's own version check is its lock and cannot be switched off — and so is naming a saga or a class that is
  no aggregate at all.
- **Transactions are mandatory for every aggregate save**, not only for tagged ones — see "Transactions are
  required" below. `#[WithoutDatabaseTransaction]` on an aggregate's command or event handler is a bootstrap
  `ConfigurationException`.
- **`#[Fetch]` in a decision-model handler.** A handler that injects a decision model or declares a
  `#[DecisionBoundary]` captures the counter of every aggregate it fetches with `#[Fetch]`, before invocation and in
  the same read as its models, and appends its events only if none of those aggregates was saved meanwhile. A
  fetched aggregate that does not exist yet is captured at version 0, so deciding on its absence is safe; a
  `#[Fetch]` expression that resolves to no identifier contributes nothing. A `#[Fetch]` handler that is not a
  decision-model handler behaves exactly as before. A boundary made only of an aggregate is written with
  `EventCriteria::aggregate(Wallet::class, $command->walletId)` — a leaf that captures the counter and matches no
  events. Fetching a saga into a decision-model handler is a bootstrap `ConfigurationException`.
- **A decision model can be backed by an event-sourced aggregate.** `#[DecisionModel(aggregate: Wallet::class)]`
  scopes the model by one instance of `Wallet`: its events, of the types the model handles, are read from `Wallet`'s
  own stream by the stream's own `(aggregate_type, aggregate_id, no)` index, in `aggregate_version` order, with the
  event-name filter pushed into SQL. The boundary is the aggregate's counter tag, which already exists and is already
  bumped by every save, captured in the same read as the handler's other models and guarded on the append. The
  aggregate's events need **no `#[EventTag]`**, and nothing is indexed, backfilled, migrated or configured:

  ```php
  #[DecisionModel(aggregate: Wallet::class)]
  final class WalletBalance
  {
      private int $balance = 0;

      #[EventSourcingHandler] public function credited(WalletCredited $e): void { $this->balance += $e->amount; }
      #[EventSourcingHandler] public function debited(WalletDebited $e): void   { $this->balance -= $e->amount; }

      public function canCover(int $amount): bool { return $this->balance >= $amount; }
  }

  #[CommandHandler]
  public function payOut(RequestPayout $command, WalletBalance $wallet, PayoutsToday $today): array { /* ... */ }
  ```

  The aggregate id comes from the message by the same convention tag values use — a property named like the
  aggregate's `#[Identifier]` (`walletId`, `walletIdId` or `walletId_id` for `#[Identifier] private string $walletId`)
  — or from `#[Fetch]`, which is what you need to inject the same model class twice
  (`#[Fetch('payload.fromWalletId')]`) or to name the identifier explicitly
  (`#[Fetch("{'walletId': payload.sourceWalletId}")]`). A nullable parameter whose identifier does not resolve
  receives `null`, contributes nothing to the boundary, and the handler still appends. An aggregate with no events
  folds from nothing and is captured at 0, so deciding on its absence is safe and a concurrent creation conflicts.
  Two models backed by the same instance share one read of its stream.

  Checked at bootstrap: `aggregate:` and `tags:` are exclusive; the named class must be an `#[EventSourcingAggregate]`
  (a state-stored `#[Aggregate]` records no events — fetch it with `#[Fetch]` instead; a saga is outside the
  boundary); every event the model handles must be recorded by that aggregate; the identifier must resolve from a
  concrete message class; and the aggregate's stream must be on the handler's connection.

  A save of the aggregate recording an event type the model ignores still bumps the counter and still invalidates the
  decision — conservative, and at most a retry. And a model cannot span an aggregate's events *and* tagged events:
  if you need one aggregate's event inside a model shared with other events, put `#[EventTag]` on that event and
  scope the model by tag names as usual.
- **Fetched aggregates are read-only.** In a decision-model handler, an event-sourced aggregate fetched with
  `#[Fetch]` that has recorded events when the handler returns throws ("fetched aggregates are read-only") and nothing
  is appended — those events were silently dropped before. Send a command to the aggregate instead. Outside
  decision-model handlers `#[Fetch]` is unchanged.
- **Conflicts name the aggregate**: "Wallet w-1 changed since it was loaded", still a
  `DecisionModelConcurrencyException`.
- **A tag conflict names what was being decided.** The batch loader knows which decision model, or which
  `#[DecisionBoundary]` method, each captured tag belongs to, so the message says so:
  `Concurrent append conflict on tag username:ada (expected version 0, current version 1) while deciding
  App\Registration\UsernameAvailability. The tag moved after it was read: ...`. Several models sharing the
  conflicting tag are listed comma-separated; a handler with only a boundary is named as
  `while deciding App\Registration\Usernames::boundary`. An aggregate counter conflict keeps its own wording
  ("Wallet w-1 changed since it was loaded") and names no model. The exception also exposes the conflict as data —
  `conflictingTagName()`, `conflictingTagValue()`, `expectedVersion()`, `currentVersion()`, `decidedBy()` — so you
  can turn it into a business answer without parsing the message.
- **A `#[Fetch]` expression that fails names where it is written.** A syntax error, an unknown `reference()`, a
  mapper that throws, or a result that does not fit the model throws `ExpressionEvaluationException` naming the
  attribute, the parameter, the handler method, the expression and what it returned — see §14, "Expression failures
  name their place". Bootstrap guards keep throwing `ConfigurationException`.
- **No backfill.** A missing counter row is version 0, and the guarded path for 0 is an insert that conflicts when
  someone else inserted first, so an aggregate with years of history is guarded from its first save after the
  upgrade. The one operator rule: during a rolling deploy, nodes still on the previous release save aggregates without
  bumping — deploy to every node before relying on the boundary. `ecotone:migration:database:setup` now lists the
  `event_tags` feature whenever DCB is registered and the application has an aggregate, even without any
  `#[EventTag]`.
- **Flow testing:** an event-sourced aggregate declaring `#[AggregateType]` is now reloaded correctly by
  `EcotoneLite::bootstrapFlowTesting()` (it used to be looked up by class name and not found).

**Now:** Events tagged with `#[EventTag]` are indexed by tag, and small reusable **decision model** classes — folded
on demand from the events matching a tag, like an aggregate but keyed by tag instead of identity — can be injected
into `#[CommandHandler]`/`#[EventHandler]`/`#[QueryHandler]` methods, on service classes and
`#[EventSourcingAggregate]`s alike. All of a handler's injected models load in one batched
`EventCriteria::or()`-combined read — a handler with three models costs the same read-side statements as one, not
three separate round trips. The framework captures every injected model's tag version before folding it, and
appends the handler's returned events only if none of those versions moved since — a
`DecisionModelConcurrencyException` (extends `ConcurrencyException`) otherwise. This is entirely additive: an
application that does not register `DynamicConsistencyBoundaryConfiguration` sees no behaviour change, no new
tables, and the append path stays today's single `INSERT`.

**Retry — read this before using decision models.** A `DecisionModelConcurrencyException` always means *the command
should run again*; nothing retries it automatically. Configure retry explicitly:

```php
#[ServiceContext]
public function retry(): InstantRetryConfiguration
{
    return InstantRetryConfiguration::createWithDefaults()
        ->withCommandBusRetry(true, 3, [DecisionModelConcurrencyException::class]);
}
```

or, Enterprise, on a custom command bus interface:

```php
#[InstantRetry(retryTimes: 3, exceptions: [DecisionModelConcurrencyException::class])]
interface MyCommandBus extends CommandBus {}
```

Without retry configured, a conflict surfaces to the caller as a technical exception naming the tag (or the aggregate)
and the captured vs. current version — not a business answer. Asynchronous endpoints already retry 3 times by default. Retry never
fires inside an already-open database transaction; that transaction is already unsafe to continue. A conflict is not
always another writer: a handler that sends a command from inside itself, whose handler appends to the same tag in
the same transaction, moves the tag under its own feet and fails on every retry — decide both in one handler.

**Transactions are required.** Tagged appends (events carrying an `#[EventTag]`, or any append with an
`AppendCondition`), every aggregate save, decision-model handlers and `ecotone:event-store:backfill-tags` write the tag
versions, the events or the aggregate, and the tag index as one unit, so they need an active database transaction. The event store never opens one itself:
without it the append throws `Ecotone\Messaging\Config\ConfigurationException` naming the switch to turn on.
Transactions are on by default in `DbalConfiguration::createWithDefaults()`; if you disabled them, enable the one that
covers the entry point:

```php
#[ServiceContext]
public function dbal(): DbalConfiguration
{
    return DbalConfiguration::createWithDefaults()
        ->withTransactionOnCommandBus(true)               // command handlers
        ->withTransactionOnAsynchronousEndpoints(true)    // asynchronous handlers
        ->withTransactionOnConsoleCommands(true);         // ecotone:event-store:backfill-tags
}
```

A handler marked `#[WithoutDatabaseTransaction]` opts out of the bus transaction, so a tagged append from it fails
the same way, and the message names the attribute. Calling `EventStore::appendTo()` with tagged events outside a handler (a script, a test) needs a transaction opened
around the call (`$connection->transactional(fn () => $eventStore->appendTo(...))`). `EcotoneLite` tests bootstrapped
with `bootstrapFlowTestingWithEventStore(runForProductionEventStore: true)` get `DbalConfiguration::createForTesting()`
(all transactions off) unless they pass their own `DbalConfiguration`. Untagged appends stay a single `INSERT` and need
no transaction.

**How to adapt:**

- Tag an event: promoted constructor parameter, property, method (for a computed/hashed value), or class-level with
  a literal `value:` (for a decision with no natural entity):

  ```php
  final readonly class StudentSubscribedToCourse
  {
      public function __construct(
          #[EventTag('course')]  public string $courseId,
          #[EventTag('student')] public string $studentId,
      ) {}
  }
  ```

  The same key may repeat across properties (a transfer's two accounts) and a property may be an array of scalars
  (each value indexed separately). Values are scalar, `Stringable`, or arrays of those; `null` means no tag. A value
  must be valid UTF-8, non-empty, at most 255 characters (characters, not bytes), without a NUL byte or trailing
  whitespace; a tag name must be non-empty and at most 100 characters (checked at bootstrap). A subclass of a tagged
  event carries the tags of its nearest tagged ancestor, so it is indexed and guarded like its parent; a decision
  model, though, folds only the exact classes its `#[EventSourcingHandler]`s name — a handler for the parent does not
  receive the subclass. Tags are known from Ecotone's class scan: an event class outside the scanned namespaces is
  appended **without** tags (not indexed, not guarded) even if it declares `#[EventTag]` — keep tagged events in a
  scanned namespace. A decision model handling such an event fails at bootstrap, naming the scan as the cause.
- Declare a decision model with `#[DecisionModel]` and fold it with `#[EventSourcingHandler]`, exactly like an
  aggregate, with a public no-argument constructor:

  ```php
  #[DecisionModel]                        // tag names default to the intersection of handled events' own tags
  final class CourseCapacity
  {
      private int $capacity = 0;
      private int $seatsTaken = 0;

      #[EventSourcingHandler]
      public function defined(CourseDefined $event): void { $this->capacity = $event->capacity; }

      #[EventSourcingHandler]
      public function seatTaken(StudentSubscribedToCourse $event): void { $this->seatsTaken++; }

      public function hasFreeSeat(): bool { return $this->seatsTaken < $this->capacity; }
  }
  ```

  Give `tags: [...]` explicitly when the default intersection isn't the question being asked (e.g.
  `#[DecisionModel(tags: ['student'])]` to scope by student alone). Every event the model handles must carry every
  one of the model's tag names — a bootstrap `ConfigurationException` otherwise. A model left with no tag name at
  all — no `tags:` and its handled events share no `#[EventTag]` name, including a handled event carrying none — is
  rejected at bootstrap too: it would fold no event and guard nothing, so either tag the event or give the model
  `tags:`. Models are injected, they never
  own handlers: `#[CommandHandler]`/`#[EventHandler]`/`#[QueryHandler]` declared directly on a `#[DecisionModel]`
  class is a bootstrap `ConfigurationException`, the same way it would be on an aggregate mixing the two roles; so is
  a `#[DecisionModel]` class also declared `#[Aggregate]`, `#[EventSourcingAggregate]` or `#[Saga]`.
- Inject the model into a handler by type-hint — no attribute needed, the same way an aggregate is loaded:

  ```php
  #[CommandHandler]
  public function subscribe(SubscribeStudentToCourse $command, CourseCapacity $course): array
  {
      if (! $course->hasFreeSeat()) { throw new CourseIsFull($command->courseId); }
      return [new StudentSubscribedToCourse($command->courseId, $command->studentId)];
  }
  ```

  Tag values come from the message by name: a property carrying `#[EventTag('course')]`, else a property named
  exactly `course`/`courseId`/`course_id` (a `courseCode` property needs `#[EventTag('course')]` or `#[Fetch]`). When
  the handler's message is a concrete class without such a property, that is a bootstrap `ConfigurationException`
  naming the handler, the model and the tag — for a nullable model parameter too, which would otherwise receive
  `null` on every message. A nullable parameter (`?CourseCapacity`) receives `null`, contributing nothing to the
  boundary, when the property holds `null`; a non-nullable one throws, naming the model and the tag.
  A value resolved from the message — by name or through `#[Fetch]` — is normalised and validated exactly like an
  `#[EventTag]` value on an event: an `int` id matches the same `int` tagged on events, and an empty, over-long or
  trailing-whitespace value throws, naming the model and the tag. A model is scoped by one value per tag: a message
  property or `#[Fetch]` result holding several values throws too — inject the model once per value with `#[Fetch]`,
  or use `#[DecisionBoundary]`.
  Use `#[Fetch('payload.fromAccountId')]` for explicit mapping — needed to inject the same model class twice (a
  transfer's two accounts) or when the property-name convention doesn't apply; a multi-tag model's `#[Fetch]`
  expression returns a map (`"{'customer': payload.customerId, 'coupon': payload.couponCode}"`).
  **A class-level `#[EventTag]` literal needs no value from the message.** A decision that has no natural entity — a
  gapless invoice sequence — is scoped by a tag every one of its events fixes on the class:

  ```php
  #[EventTag('invoiceSequence', value: 'default')]
  final readonly class InvoiceIssued
  {
      public function __construct(public int $number) {}
  }

  #[DecisionModel(tags: ['invoiceSequence'])]
  final class InvoiceNumbering { /* ... */ }

  #[CommandHandler]
  public function issue(IssueInvoice $command, InvoiceNumbering $numbering): array
  {
      return [new InvoiceIssued($numbering->nextNumber())];
  }
  ```

  When every event the model handles declares the same class-level literal for a scope tag, that literal *is* the
  model's value for it: nothing is read from the command and nothing from a `#[Fetch]` expression, so `IssueInvoice`
  needs no `invoiceSequence` property. Handled events declaring *different* literals for one scope tag are a bootstrap
  `ConfigurationException` — one model folds one value per tag. A scope mixing a literal tag with a message-resolved
  one (`invoiceSequence` + `region`) resolves the latter from the message as usual.
  `#[DecisionBoundary]` on a static method of the same class, taking the same command, is the escape hatch for a
  boundary no model expresses. It is matched to the handler whose first parameter has the same type — a boundary
  that is not static, does not take that command as its first parameter, does not declare `EventCriteria` as its
  return type, matches no handler of its class, or shares its parameter type with another boundary is a bootstrap
  `ConfigurationException`; a boundary matched only by a `#[QueryHandler]` is rejected saying a query appends no
  events, so the boundary would guard nothing. Its criteria are evaluated with the handler's command **before
  invocation**, in the same single read as the handler's models and fetched aggregates, so a write committed to them
  while the handler runs fails the append.

  After the command, a boundary may take **any number of further parameters**, resolved by exactly the rules a
  handler's parameters follow — `#[Header]`, `#[Headers]`, `#[Reference]`, `#[ConfigurationVariable]`, and a service
  by type hint. A tenant-scoped boundary, the most common one a model cannot express, is then one method:

  ```php
  #[DecisionBoundary]
  public static function boundary(
      RateCourse $command,
      #[Header('tenant')] string $tenant,
      TenantCourseMapper $mapper,
  ): EventCriteria {
      return EventCriteria::tag('tenant', $tenant)
          ->andTag('course', $mapper->courseOf($command->rating));
  }
  ```

  The method stays `public static`: it is called before any instance exists, and its services arrive as arguments
  rather than through a constructor, which keeps it stateless. A parameter no rule resolves — a scalar with no
  attribute — is a bootstrap `ConfigurationException` naming the method and the parameter. So is a parameter that
  would need the very read the boundary scopes: a decision model, or one marked `#[Fetch]`. Inject those into the
  handler instead. Because the boundary runs inside the handler's single read, a service it calls runs inside the
  database transaction — keep that mapping local and fast.
- Only a `#[CommandHandler]`/`#[EventHandler]` that injects a model appends and publishes its returned events;
  `#[QueryHandler]` replies as always, appending nothing. `return []` is a no-op. `outputChannelName` keeps working
  on a model-injecting handler: events are appended and published, then forwarded as today.
- Injected into an `#[EventSourcingAggregate]` command handler, a model adds a *second* guard on the same save —
  both the aggregate's own version check and the tag condition must pass:

  ```php
  #[EventSourcingAggregate]
  final class Order
  {
      #[CommandHandler]
      public static function place(PlaceOrder $command, ?CouponRedemptions $coupon): array
      {
          if ($coupon?->isExhausted()) { throw new CouponExhausted(); }
          return [new OrderPlaced($command->orderId, $command->couponCode)];
      }
  }
  ```

  This is the adoption path for an existing application: an aggregate stays exactly as it is and gains a
  cross-aggregate invariant by injecting one model.
- The default stream is `ecotone_event_stream`; `#[Stream]` now also works on the handler *method*, and wins over a
  class-level `#[Stream]`.
- A tag that would queue most writers (a low-cardinality key like `tenant` or `region`) should be declared
  filter-only — indexed for reads, never counted, so it can't become a bottleneck:

  ```php
  #[ServiceContext]
  public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
  {
      return DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withFilterOnlyTags(['tenant']);
  }
  ```

  A decision model scoped *only* by filter-only tag names is a bootstrap `ConfigurationException` — its append would
  be guarded by nothing. A scope mixing a filter-only and a counted tag (`tenant` + `username`: unique per tenant) is
  allowed: it folds exactly that tenant's events and is guarded on the counted tag alone, so two usernames claimed in
  one tenant never wait for each other. A unique username per tenant is the worked example, end to end
  in `UniqueUsernamePerTenantTest` (in memory) and `UniqueUsernamePerTenantDbalTest` (four engines). Naming a tag in
  `withFilterOnlyTags()` that no `#[EventTag]` declares is a bootstrap `ConfigurationException` too — a typo would
  otherwise leave the hot tag counted.

  The same rule now covers `#[DecisionBoundary]`. A boundary builds its criteria at runtime from the command, so its
  tag *names* are not knowable at bootstrap; the check therefore runs each time the boundary is evaluated, and a
  boundary returning criteria whose tags are all filter-only raises a `ConfigurationException` naming the method and
  the tags on the first message that reaches it. One counted tag anywhere in the criteria is enough, so
  `EventCriteria::tag('tenant', $t)->andTag('course', $c)` and `EventCriteria::tag('course', $c)->or(...)` are both
  accepted.
- **Licence.** The tag-carrying half of DCB — `#[EventTag]`, `#[DecisionModel]`, `#[DecisionBoundary]`,
  `EventCriteria`, and a tag-bearing `AppendCondition` — is Enterprise, and it is switched on by
  `DynamicConsistencyBoundaryConfiguration` (see "Enabling" above): the extension object decides *whether* DCB
  runs, the licence decides *may*. Registering it without an Enterprise licence is a `LicensingException` at
  bootstrap, before any message is handled; without the extension object nothing DCB-related runs and the
  disabled `ConfigurationException` is what a decision model, `loadByCriteria()` or a tag-bearing
  `AppendCondition` raises (§4, "AppendCondition now expresses an aggregate's optimistic-lock expectation too"). `EventStore` and `AppendCondition` themselves stay Apache-2.0 — an aggregate's
  own optimistic-lock condition (`AppendCondition::forAggregate()`) is open-core and works without any licence.
- Without any class, the same machinery is a gateway on the store itself:
  `$eventStore->loadByCriteria(EventCriteria::tag('course', $courseId)->ofTypes(...))` returns the matching events
  and a ready-made `AppendCondition` for `$eventStore->appendTo($stream, $events, $condition)`. Appending no events
  is a no-op on every store: the condition is not checked, even when stale. Several criteria
  combine with `->or(...)`; narrow each one with `andTag()`/`ofTypes()` before combining — calling either on an
  `or()` combination throws `InvalidArgumentException`.
- **Fully shipped, including the DBAL-backed store.** `#[EventTag]`, `#[DecisionModel]`, `#[DecisionBoundary]`,
  `EventCriteria`, the licence gate, and the full injection/append/retry mechanism are implemented and tested
  against `InMemoryEventStore` (`EcotoneLite::bootstrapFlowTesting()` exercises real conditional-append semantics
  with no database) **and** against PostgreSQL, MySQL, MariaDB and SQLite through `DbalEventStore` — including
  real two-connection contention proofs (a conflicting writer waits and either loses with
  `DecisionModelConcurrencyException` or succeeds once the blocker rolls back, opposite-order multi-tag appends
  don't deadlock, and an aggregate save whose tag moved after the aggregate was loaded fails on InnoDB
  `REPEATABLE READ`). An application with
  no `#[EventTag]` sees byte-for-byte today's single `INSERT` — no counter statements, no tag tables touched.

**Observability.** Every conflict is reported as one `notice`-level log line through the PSR logger you registered,
with the message `Dynamic Consistency Boundary conflict` and the context keys `ecotone.dcb.conflict.tag`,
`ecotone.dcb.conflict.expected_version`, `ecotone.dcb.conflict.current_version` and `ecotone.dcb.conflict.model`, so
conflicts can be counted without any tracing set-up.

With `ecotone/open-telemetry` installed and the tracing package enabled, each boundary handler also produces two spans:

| Span | When | Attributes |
|---|---|---|
| `Decision Models: <Class::method>` | around the pre-invocation decision load | `ecotone.dcb.models` (the model classes folded), `ecotone.dcb.tags` (the captured `name:value` tags), `ecotone.dcb.captured_versions` (`name:value=version`), `ecotone.dcb.aggregates` (the `aggregate_<Type>:<id>` counters among them), `ecotone.dcb.events_folded` (how many events were folded) |
| `Conditional Append: <stream>` | around an append carrying a tag condition — a decision-model handler's result and an event-sourced aggregate save alike | `ecotone.dcb.tags`, `ecotone.dcb.events_appended` |

A conflict records the exception on the append span, sets its status to error, and adds a span event named
`dcb.conflict` whose attributes are the four `ecotone.dcb.conflict.*` fields above. List-valued attributes are
comma-separated strings.

Nothing is registered when the OpenTelemetry module is absent, and the DCB code itself has no dependency on it: the
module decorates the decision-model batch loader and the raw event store in a compiler pass. With DCB switched off no
boundary span is produced at all.

#### DCB tag tables — schema, setup, backfill, verify-schema

Two tables carry the tag index and the per-tag conflict counters, alongside whichever `ecotone_event_stream` /
`#[Stream]` tables already exist. **No column is ever added to a stream table for DCB** — this is what keeps the
schema upgradable while an application is still on 1.x (below).

```sql
-- PostgreSQL; MySQL/MariaDB use ENGINE=InnoDB ROW_FORMAT=DYNAMIC COLLATE utf8mb4_bin (the server default collation
-- would merge 'coupon:ABC' with 'coupon:abc'); SQLite uses TEXT/INTEGER.
CREATE TABLE ecotone_tagged_events (
    tag_name     VARCHAR(100) NOT NULL,
    tag_value    VARCHAR(255) NOT NULL,
    stream_name  VARCHAR(128) NOT NULL,
    event_no     BIGINT       NOT NULL,
    tag_sequence BIGINT       NOT NULL,
    PRIMARY KEY (tag_name, tag_value, stream_name, event_no)
);

CREATE TABLE ecotone_tag_versions (
    tag_name VARCHAR(100) NOT NULL,
    tag_value VARCHAR(255) NOT NULL,
    version   BIGINT       NOT NULL,
    PRIMARY KEY (tag_name, tag_value)
) WITH (fillfactor = 70);   -- PostgreSQL only: in-place UPDATEs are HOT updates on a table that never grows with event volume
```

`ecotone_tagged_events` is the read side — one row per tag per tagged event, `tag_sequence` a gapless commit-ordered
sequence per tag so a model fed from more than one stream table still folds in exact commit order
(`ORDER BY tag_sequence, event_no`). `ecotone_tag_versions` is the write side — one row per distinct tag *value*,
`UPDATE ... SET version = version + 1 WHERE ... AND version = :captured` is the entire locking mechanism (no
advisory lock, no `SELECT ... FOR UPDATE`, no raised isolation level). Both tables register with
`ecotone:migration:database:setup` under their own feature, **`event_tags`** — distinct from `event_stream` — whose
table manager reports `isUsed()` only when the application declares an `#[EventTag]`; an open-core application never
sees these tables in its setup output or its database (§8's feature list now includes `event_stream`, `event_tags`
alongside `deduplication`, `dead_letter`, `document_store`, ...). Counters are keyed by tag alone, not by stream, so
a decision model can read events from several streams on the same connection without any extra configuration.
**Cross-connection injection fails loudly at bootstrap, not silently at runtime.** Every event class a model handles
is traced — via the `#[EventSourcingHandler]`s of the aggregates that record it — to that aggregate's `#[Stream]`
connection; if it differs from the connection the injecting handler's own write stream lives on, bootstrap raises a
`ConfigurationException` naming the model, the event, and both connections, instead of silently loading a model that
can never see events committed on the other connection (cross-database consistency is a saga's job, not a
consistency boundary's). Events recorded only by a service handler — not an aggregate — cannot be traced this way
and are not checked; keep such a handler's stream and its injected models' aggregates on the same connection by
convention.

**A missing stream table stops a decision instead of answering it.** `EventStore::load()` and
`loadAggregateEvents()` keep their open-core contract — a stream whose table does not exist reads as no events, which
is what an aggregate `#[CommandHandler]` loading a not-yet-created aggregate needs. On the decision path that answer
is wrong: an aggregate-backed `#[DecisionModel]` would fold zero events and the handler would decide on an empty
aggregate. The decision read is a separate store method, so the two contracts never need a flag to tell them apart,
and a missing stream table there raises the same `ConfigurationException` §8 describes, naming the `event_stream`
feature, the table and both setup commands. `ecotone:event-store:backfill-tags` reports the same error rather than a
report saying nothing was scanned, which is what a mistyped `--stream=` actually means.

**Upgrading while still on 1.x — expand first, deploy code second:**

| # | When | Step | Why 1.x keeps working |
|---|---|---|---|
| 1 | on 1.x | Create `ecotone_tagged_events` and `ecotone_tag_versions` (the DDL above, or `ecotone:migration:database:setup --sql --feature=event_tags` once on 2.0 to get it, applied by hand while still on 1.x) | 1.x never references them |
| 2 | on 1.x, optional | Create `ecotone_event_stream` if not already present | 1.x never references it |
| 3 | on 1.x, **only for a table a decision model will *write* into** | Relax the table's aggregate `NOT NULL` — see below | Only permits *more*, not less |
| 4 | | Deploy 2.0 to **every** node | |
| 5 | on 2.0 | Release adding `#[EventTag]` to events; deploy to every node | |
| 6 | on 2.0 | Run `ecotone:event-store:backfill-tags` and **wait for it to finish** before the next step | |
| 7 | on 2.0 | Release adding `#[DecisionModel]` | |

Step 3 is rarely needed: because a boundary can span streams (above), a model can *read* events from existing 1.x
tables and *write* its own to `ecotone_event_stream` without touching them at all. When a decision model's own
events do land in a 1.x-shaped table, that table's three `NOT NULL` constraints on the aggregate columns reject an
aggregate-less event outright — the exact failure `ecotone:event-store:verify-schema` is built to catch before it
happens in production:

| 1.x layout | PostgreSQL | MySQL | MariaDB |
|---|---|---|---|
| `single` / `partition` (1.x default) | `SET lock_timeout = '2s'; ALTER TABLE "..." DROP CONSTRAINT IF EXISTS aggregate_version_not_null, DROP CONSTRAINT IF EXISTS aggregate_type_not_null, DROP CONSTRAINT IF EXISTS aggregate_id_not_null;` — metadata-only once the lock is granted; the timeout keeps a busy table from queuing every writer behind it, retry on timeout | `ALTER TABLE ... MODIFY` each generated column without `NOT NULL`, restating its expression — **rebuilds the table under a write lock**, use `gh-ost` / `pt-online-schema-change` | Nothing — 1.x MariaDB columns are already nullable |
| `simple` | No such constraints — nothing to do | | |

**Mixed writers are unsupported on tagged events, by operator discipline, not by a runtime guard.** A node still
running code from before an event's `#[EventTag]` was added appends without bumping counters or writing index rows,
and a decision model would then approve what it should reject — this is exactly why the release order above puts
*every node on 2.0* before *tags* before *backfill* before *decision models*. There is no coverage table and no
runtime check for it (a deliberate maintainer decision, to keep the append path free of bookkeeping); rolling code
back to 1.x after decision models have run means re-running the backfill before rolling forward again.

**`ecotone:event-store:backfill-tags [--stream=] [--event=] [--batchSize=500] [--fromNo=] [--dryRun]
[--skipUndeserializable]`** indexes events recorded before their class declared its current tags — every 1.x event,
and any event tagged later. Per batch, in one transaction: walk the stream by `no`, deserialize, bump the tags a
batch touches once each (the same unit an ordinary append uses — one `appendTo()` call, however many events, bumps
a shared tag once) and insert the index rows — one per tag the event carries, filter-only tags included, so events that predate
the tags stay filterable by them; the insert is idempotent on the primary key, so re-running a
completed range is a no-op and `--fromNo` resumes an interrupted one. **`--batchSize` trades backfill throughput
against cross-stream ordering precision:** every event sharing a tag within one batch gets that batch's single bump
as its `tag_sequence`, so two co-tagged events recorded on *different* streams within the same batch cannot be told
apart by commit order — only same-stream order survives, via `no`. Size it down when backfilled history needs
precise cross-stream ordering for a tag. `--dryRun` reports counts without writing. A
payload that no longer deserializes is reported with its `no` and aborts the run unless `--skipUndeserializable` is
given, in which case it is skipped and still reported. There is no decision-model usage until the backfill has
finished — start using `#[DecisionModel]` only after step 6 above completes. On a multi-tenant setup, the `tenant`
header selects which tenant's connection and tag tables get backfilled — `ecotone:event-store:backfill-tags --header
"tenant:a"` indexes tenant `a` only, and the command must be run once per tenant.

**`ecotone:event-store:verify-schema [--legacyStream=]`** is the CI/deploy gate for all of the above: it checks
that `ecotone_tagged_events` / `ecotone_tag_versions` exist (a missing one is reported with the setup command that
creates it), their primary keys and, on MySQL/MariaDB, that their tag columns kept
`utf8mb4_bin` collation (a hand-applied migration with the server default would silently let `'ABC'` and `'abc'`
collide as one tag value); for every `--legacyStream=` table named, it checks the three aggregate `NOT NULL`
constraints from the table above are relaxed. On any failure it prints the exact `ALTER`/`DROP CONSTRAINT`
statement to run — the same text as the table above, generated instead of hand-typed. Like the backfill command, it
is tenant-selected via the `tenant` header on a multi-tenant setup, and refuses to run against a default connection
when the header is missing.

#### Decision-model snapshots

A decision model folds its whole scope on every read. For a long-lived scope — a wallet, a subscription, an account
— that fold grows forever. Snapshots bound it, and they are configured the way aggregate snapshots already are:

```php
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\Gateway\DocumentStore;

#[ServiceContext]
public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
{
    return DynamicConsistencyBoundaryConfiguration::createWithDefaults()
        ->withSnapshotsFor(WalletBalance::class, thresholdTrigger: 100, documentStore: DocumentStore::class);
}
```

The folded model is kept in the document store, in its own collection `decision_model_snapshots_<Model>`, separate
from `aggregate_snapshots_<Class>`. A read loads it and folds only the events after the position it covers; a write
happens inline after the handler's append, once a read has folded `thresholdTrigger` positions beyond the covered
one. **Nothing is added to the schema** — no table, no column, no index — and the feature is entirely opt-in.

- **The model needs a converter.** The stored state goes through the same `application/x-php` ↔ `application/json`
  conversion aggregate snapshots use, so a snapshotted model needs a `#[MediaTypeConverter]` or a serializer
  package. Without one the first snapshot raises a `ConfigurationException` naming the model. A model class that
  carries no `#[DecisionModel]` is rejected at bootstrap, and a `documentStore` reference that does not resolve is a
  `ConfigurationException` naming the reference.
- **No `#[Version]` property.** The covered position and the fold shape are written by Ecotone around your state,
  never inside it. Your converter only has to round-trip the model's own fields.
- **A changed model re-folds itself.** The stored document records which class, handled events and tag names it was
  folded with. Deploy a model with one more `#[EventSourcingHandler]` and its old snapshots stop matching: the next
  read folds the whole scope and the next write stores a snapshot under the new shape. The same happens to a
  snapshot that cannot be read, holds another class, or covers a position its scope has not reached — it is logged
  and ignored, never trusted and never deleted mid-read.
- **Storage is per scope value, not per model.** One document per tag value per model class. A tag with a million
  values and two models scoped on it is two million documents. Snapshot long-lived scopes; a tag whose value lives
  three events costs more to snapshot than to fold.
- **Reads stay reads.** A read never writes a snapshot, so a `#[QueryHandler]` folding a snapshotted model never
  advances it. Only a handler that appends does.

## 5. Connections: Ecotone classes replace the Enqueue ones (DBAL, AMQP, SQS, Redis)

**Before:** Every transport's connection was referenced by the underlying `php-enqueue/*` package's own class name —
`Enqueue\Dbal\DbalConnectionFactory::class`, `Enqueue\AmqpExt\AmqpConnectionFactory::class` /
`Enqueue\AmqpLib\AmqpConnectionFactory::class`, `Enqueue\Sqs\SqsConnectionFactory::class`,
`Enqueue\Redis\RedisConnectionFactory::class`. Application code constructed and registered those Enqueue classes
directly, and every channel builder / attribute defaulted its `$connectionReferenceName` (or `$connectionReference`)
parameter to one of those Enqueue class names.

**Now:** Every transport has an Ecotone-owned connection factory (internalised from the matching `php-enqueue/*`
package, MIT-licensed, with a `code comes from https://github.com/php-enqueue/...` attribution comment) plus an
Ecotone-owned `<Package>ConnectionReference` in that package's `Api/` namespace, mirroring `DbalConnectionReference`.
The reference name a user ever needs to type is one of these Ecotone classes; every default parameter across the
framework now points at `<Package>ConnectionReference::DEFAULT` instead of an Enqueue class name. Internally, the
factories still implement `Interop\Queue\ConnectionFactory` (`Interop\Amqp\AmqpConnectionFactory` for AMQP) — that
seam is unchanged and is what lets the underlying transport be replaced later without a user-facing change.

| Package | Before (Enqueue class you typed) | Now (Ecotone class you type) | Default reference name |
|---|---|---|---|
| DBAL | `Enqueue\Dbal\DbalConnectionFactory` | `Ecotone\Dbal\Connection\DbalConnectionFactory` | `Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference::DEFAULT` |
| AMQP (default, `ext-amqp`) | `Enqueue\AmqpExt\AmqpConnectionFactory` | `Ecotone\Amqp\Connection\AmqpExtConnectionFactory` | `Ecotone\Api\Amqp\AmqpConnectionReference::DEFAULT` |
| AMQP (RabbitMQ Streams, `php-amqplib`) | `Enqueue\AmqpLib\AmqpConnectionFactory` | `Ecotone\Amqp\Connection\AmqpLibConnectionFactory` | `Ecotone\Api\Amqp\AmqpConnectionReference::DEFAULT_STREAM` |
| SQS | `Enqueue\Sqs\SqsConnectionFactory` | `Ecotone\Sqs\Connection\SqsConnectionFactory` | `Ecotone\Api\Sqs\SqsConnectionReference::DEFAULT` |
| Redis | `Enqueue\Redis\RedisConnectionFactory` | `Ecotone\Redis\Connection\RedisConnectionFactory` | `Ecotone\Api\Redis\RedisConnectionReference::DEFAULT` |

`DbalConnectionReference::defaultConnection()`, `AmqpConnectionReference::defaultConnection()` /
`defaultStreamConnection()`, `SqsConnectionReference::defaultConnection()` and `RedisConnectionReference::defaultConnection()`
each return the reference object, mirroring `SymfonyConnectionReference`, `LaravelConnectionReference` and
`TempestConnectionReference`, which already resolved to `DbalConnectionReference::DEFAULT` and are unaffected by this
change.

**How to adapt:**
- Replace the `use Enqueue\...\...ConnectionFactory;` import with the matching `Ecotone\...\Connection\...ConnectionFactory`
  one from the table above. The constructor signature (DSN string, config array, or an already-connected client where
  the transport supports it — e.g. an `Aws\Sqs\SqsClient` or `Enqueue\Redis\Redis` instance) is unchanged.
- Container service ids / reference names that were the literal string `Enqueue\...\...ConnectionFactory` must be renamed
  to `<Package>ConnectionReference::DEFAULT` (or `AmqpConnectionReference::DEFAULT_STREAM` for RabbitMQ Streams via
  `AmqpStreamChannelBuilder` / `AmqpStreamInboundChannelAdapterBuilder`); Ecotone no longer looks up the old id.
  ```php
  // 1.x
  [Enqueue\AmqpExt\AmqpConnectionFactory::class => new Enqueue\AmqpExt\AmqpConnectionFactory(['dsn' => $dsn])]

  // 2.0
  [AmqpConnectionReference::DEFAULT => new AmqpExtConnectionFactory(['dsn' => $dsn])]
  ```
- Code that reaches into a transport's `Context` / `Consumer` / `Producer` / `Destination` / `Message` classes (e.g.
  `Enqueue\AmqpExt\AmqpContext`, `Enqueue\Sqs\SqsContext`, `Enqueue\Redis\RedisContext`,
  `Ecotone\Dbal\Connection\DbalContext`) is unaffected — only the `ConnectionFactory` class itself moved into Ecotone's
  namespace for DBAL, AMQP, SQS and Redis alike. `Interop\Queue\Context` type-hints on a DBAL connection should use
  `Ecotone\Dbal\Connection\DbalContext` as before.
- `enqueue/amqp-ext`, `enqueue/amqp-lib`, `enqueue/amqp-tools`, `enqueue/sqs`, `enqueue/redis` and `enqueue/dsn` remain
  composer dependencies of their respective packages and are used internally — unlike `enqueue/dbal`, which was dropped
  entirely when DBAL was internalised. Only the class you reference in your own configuration changed.

## 6. AMQP Distributed Bus removed

**Before:** `AmqpDistributedBusConfiguration::createPublisher()` / `createConsumer()` wired a RabbitMQ-specific
distributed bus using exchange/queue conventions.

**Now:** The only distributed bus is the Service Map one (`DistributedServiceMap`), which works over any channel
(AMQP, Kafka, DBAL, SQS, Redis). `DistributedServiceMap::withServiceMapping()` (legacy mode),
`getSubscriptionChannels()` and `getAllChannelNamesBesides()` are removed.

**How to adapt:**

```php
// 1.x
AmqpDistributedBusConfiguration::createPublisher(),
AmqpDistributedBusConfiguration::createConsumer(),

// 2.0
DistributedServiceMap::initialize()
    ->withCommandMapping('ticket_service', 'ticket_service.commands')
    ->withEventMapping('ticket_service.events', subscriptionKeys: ['*']),
AmqpBackedMessageChannelBuilder::create('ticket_service.commands'),
AmqpBackedMessageChannelBuilder::create('ticket_service.events'),
```

Consumers subscribe by having a channel with the mapped name; `#[Distributed]` handlers stay unchanged. Distributed event
routing wildcards are unchanged (`*` matches a single dotted segment).

- The AMQP bus declared a `ecotone.distributed` exchange and per-service queues automatically. Service Map has no exchange
  convention: each mapped channel is an ordinary channel you declare (`AmqpBackedMessageChannelBuilder::create('ticket_service.events')`
  creates the queue on first use; `AmqpStreamChannelBuilder` for RabbitMQ streams when several services consume the same events).
- To receive *all* events on a channel use `->withEventMapping('ticket_service.events', subscriptionKeys: ['*'])`; an empty key list
  subscribes to nothing, so the former "subscribe to everything by default" behaviour of `withServiceMapping()` must be made explicit.
- `DistributedServiceMap` is an Enterprise feature, as the AMQP Distributed Bus was; the licence key is still required.

## 7. Deprecated API removed

| Removed | Use instead |
|---|---|
| `#[AggregateIdentifier]`, `#[SagaIdentifier]` | `#[Identifier]` |
| `#[AggregateIdentifierMethod]` | `#[IdentifierMethod]` |
| `#[TargetAggregateIdentifier]` | `#[TargetIdentifier]` |
| `#[AggregateVersion]` / `#[TargetAggregateVersion]` | `#[Version]` / `#[TargetVersion]` |
| `WithAggregateEvents` trait | `WithEvents` |
| `StandardRepository` interface | `StateStoredRepository` (same methods) |
| `EcotoneLite::bootstrapForTesting()`, `EcotoneLiteConfiguration`, `ecotone/lite-application` package | `EcotoneLite::bootstrapFlowTesting()` / `EcotoneLite::bootstrap()` |
| `MethodInvocation::getInterfaceToCall()`, `MethodInvocation::replaceArgument()` | `getObjectToInvokeOn()` / `getMethodName()`; pass changed values by returning a new message or header from `#[Before]` / `#[Presend]` |
| `Ecotone\Messaging\Gateway\Converter\Serializer` | `Ecotone\Api\Gateway\SerializerGateway` |
| `Type::STRING`, `Type::ARRAY`, `Type::OBJECT` | `Type::string()`, `Type::array()`, `Type::object()` |
| `Clock::get()` static access | inject `EcotoneClockInterface` |
| `FlowTestSupport::releaseAwaitingMessagesAndRunConsumer()` | `run()` |
| `BaseEventSourcingConfiguration::withSnapshots()` | `withSnapshotsFor()` |
| `ProjectingManager::backfill()` | `prepareBackfill()` |
| `AmqpBackedMessageChannelBuilder::withPublisherAcknowledgments()` | `withPublisherConfirms()` |
| `DbalConfiguration::withCleanObjectManagerOnAsynchronousEndpoints()` | `withClearAndFlushObjectManagerOnAsynchronousEndpoints()` |
| `ProjectionRunningConfiguration::with*()` / `get*()` typed helpers | removed with projection v1 (§3) |
| `Module::canHandle()` | removed from the `Module` interface; modules receive every extension object and filter by `instanceof` inside `prepare()` |
| `CronExpression::factory()` | `new CronExpression()` |
| `#[ServiceActivator]` | `#[InternalHandler]` (same attribute, `InternalHandler` was its 2.0 name; §7a) |

Kept (still deprecated, scheduled for a later minor): `ServiceActivatorBuilder` (use `MessageProcessorActivatorBuilder` in new modules),
`MessagingSystemConfiguration::buildMessagingSystemFromConfiguration()`.

### 7a. `#[ServiceActivator]` is gone — `#[InternalHandler]` is the only name

**Before:** `#[InternalHandler]` (introduced earlier in 2.0) and `#[ServiceActivator]` were two names for the same
attribute — `InternalHandler extends ServiceActivator` and both worked identically.

**Now:** `Ecotone\Api\ServiceActivator` no longer exists. `#[InternalHandler]` is the only attribute.

**How to adapt:**
- Rename `#[ServiceActivator(...)]` to `#[InternalHandler(...)]` and update the `use` statement
  (`Ecotone\Api\ServiceActivator` → `Ecotone\Api\InternalHandler`).
- **Check positional arguments — the second one changed meaning.** `ServiceActivator`'s constructor was
  `(inputChannelName, endpointId, outputChannelName, requiresReply, requiredInterceptorNames, changingHeaders)`.
  `InternalHandler`'s is `(inputChannelName, outputChannelName, endpointId, requiredInterceptorNames, changingHeaders, requiresReply)`
  — positional argument 2 is `outputChannelName` on `InternalHandler`, not `endpointId`. A call like
  `#[ServiceActivator('orders.in', 'orders.processEndpoint')]` (channel + endpoint id) silently becomes
  `#[InternalHandler('orders.in', 'orders.processEndpoint')]` (channel + **output channel**) if renamed blindly —
  the endpoint id is lost and the second argument is now routed as an output channel instead. Use named arguments for
  every argument after the first: `#[InternalHandler('orders.in', endpointId: 'orders.processEndpoint')]`.
- `requiresReply` moved onto `InternalHandler` (it only existed on `ServiceActivator` before) and is still available
  as a named argument: `#[InternalHandler('orders.in', requiresReply: true)]`.
- Framework-internal cross-cutting concerns that target `InternalHandler::class` as a pointcut — the message-handler
  execution log line and OpenTelemetry's per-handler tracing span — now also apply to any handler that used to be
  `#[ServiceActivator]`-only and therefore invisible to them. If you assert on exact log/span sequences in tests that
  exercise such a handler, expect the extra entries.

If you implemented a custom `Module` / `AnnotationModule`, delete the `canHandle()` method and filter extension objects
with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)` inside `prepare()`. The base and marker
classes that concrete attributes extend (`EndpointAnnotation`, `IdentifiedAnnotation`, `ChannelAdapter`,
`MessageConsumer`, `StreamBasedSource`, …) are now `@internal`: application code never uses them, and custom modules
that type against them should expect them to change in minor versions.

## 8. Database tables are no longer created on the fly

**Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`,
`ecotone_error_messages`, `enqueue` and the rest during the first message. On MySQL and MariaDB that `CREATE TABLE`
statement commits the surrounding transaction implicitly, so Ecotone carried a workaround
(`ImplicitCommit::isImplicitCommitException()`) that string-matched the driver's error message and swallowed the
resulting commit failure. Deduplication additionally special-cased PostgreSQL, because only there could it insert its
row before running the handler without risking that insert being committed early by a table-creation statement later
in the same transaction.

**Now:** Ecotone never issues DDL while handling a message. Tables are created only through the CLI, through the
`DatabaseSetupManager` gateway, or by your own migration tool. `ImplicitCommit` is gone, `DbalTransactionInterceptor`
raises a hard error again if a commit genuinely fails, and one transaction wraps the whole message on every driver —
including MySQL and MariaDB. Deduplication's concurrency guarantee (a handler running twice for the same message
collides on the primary key instead of running twice) now works on every driver, not just PostgreSQL. Deduplication
cleanup runs on its own endpoint, outside your handler's transaction, so it no longer holds row locks while your
handler runs.

Behaviour is controlled by `AutoCreateLevel`:

- `AutoCreateLevel::None` — the new default, everywhere except test bootstraps. Ecotone never issues DDL; a missing
  table raises a `ConfigurationException` naming the feature, the table, and the exact command (or, without a
  console, the code) to run for the integration your application is running under.
- `AutoCreateLevel::CreateOnly` — create missing tables, never alter or drop an existing one. This is what 1.x always
  did, under a new name. `EcotoneLite::bootstrapFlowTesting()` / `bootstrapFlowTestingWithEventStore()` use it by
  default, so in-memory and SQLite tests are unaffected.

On MySQL and MariaDB, `AutoCreateLevel::CreateOnly` (`withAutomaticTableInitialization(true)`) is not supported: a
`CREATE TABLE` there implicitly commits the surrounding transaction, and Ecotone will not split your message
transaction to work around it. A missing table raises the same `ConfigurationException` as `AutoCreateLevel::None`
regardless of the level configured, naming the feature, the table, and the exact `ecotone:migration:database:setup`
command to run instead. PostgreSQL and SQLite are unaffected and auto-create exactly as configured.

**How to adapt:**

```php
// 1.x — nothing to configure; tables appeared on first use.

// 2.0 — None is the default everywhere outside test bootstraps. Opt back into auto-create where you want it:
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseSetup(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withAutomaticTableInitialization(true); // AutoCreateLevel::CreateOnly
    }
}
```

Every failure caused by a missing table carries that message, whatever triggered it — storing a dead letter,
deduplicating a message, writing a document, appending events, reading the tag index, or a Dbal message channel built
with `withAutoDeclare(false)`, which in 1.x surfaced Doctrine's `TableNotFoundException` on receive and Interop's
"The transport fails to send the message due to some internal error" on send.

Reading an event stream is not one of those paths any more. `EventStore::load()` and
`EventStore::loadAggregateEvents()` used to probe `information_schema` on every call and answer "no events" when the
stream table was absent, which turned a missing `ecotone:migration:database:setup` into an
`AggregateNotFoundException` for an aggregate whose events were never readable in the first place. **They now raise
the same `ConfigurationException` as every other missing table**, naming the `event_stream` feature, the table and
the exact command to run, and the probe they paid for on every aggregate load is gone. Projections reading a stream
table that does not exist raise it too, instead of reporting themselves up to date against a stream nobody created.
Event-sourced aggregate command handlers, `#[Fetch]`, `Repository::getFor()` and partitioned and global projections
all surface it. Tests that used an absent stream table to mean "this aggregate has no history" must create the
tables — `$ecotone->initializeDatabase()` in an `EcotoneLite` test, or
`$messagingSystem->getServiceFromContainer(DatabaseSetupManager::class)->initializeAll()` — and then start from an
empty stream instead. `EventStore::hasStream()` is unchanged: it answers whether the stream exists, so it still
returns `false` rather than raising.

The read paths that answer a question rather than do work still treat an absent table as "nothing there", and say so
quietly: `DeadLetterGateway::list()`/`count()` and the document store's `findDocument()`/`getAllDocuments()`/
`countDocuments()`. If you need to tell "not set up" from "nothing to show" in those places, run
`ecotone:migration:database:setup --missing` — it lists exactly the tables that are absent.

- Add `ecotone:migration:database:setup --initialize` to your deploy pipeline (or `--feature=deduplication,dead_letter`
  for a subset). On MySQL/MariaDB this is not optional — auto-create never runs there, so the setup command (or
  `--sql` for your own migration tool) is the only way to get the tables in place before your application runs.
  - Symfony: `bin/console ecotone:migration:database:setup --initialize`
  - Laravel: `php artisan ecotone:migration:database:setup --initialize`
  - Tempest: `./tempest ecotone:migration:database:setup --initialize`
  - EcotoneLite / standalone: `$messagingSystem->getServiceFromContainer(DatabaseSetupManager::class)->initializeAll();`
- Add `--missing` to list only the tables that do not exist yet, instead of every used feature. Combine it with
  `--sql` (`ecotone:migration:database:setup --missing --sql`) to get `CREATE TABLE` statements for only what's
  missing — the shape you want when pasting into an existing migration, rather than dumping SQL for tables you
  already created.
- Or generate SQL for your own migration tool: `ecotone:migration:database:setup --sql`, optionally with
  `--feature=` or `--missing`, and paste the output into a Doctrine Migrations / Laravel migration.
- For integration tests against a real database, call
  `$ecotone->getGateway(DatabaseSetupManager::class)->initializeAll();` once in `setUp()`. `EcotoneLite` in-memory and
  SQLite bootstraps do not need this.
- Deduplication cleanup: run the endpoint with `ecotone:run ecotone.deduplication.cleanup` on a schedule (a
  Kubernetes CronJob, Supervisor timer, or your own scheduler), or keep using
  `ecotone:deduplication:remove-expired-messages` if you already trigger it from your own cron. Both call the same
  code; neither runs inside your handler's transaction any more.
- `DbalConfiguration::withAutomaticTableInitialization(bool)` still works: `true` maps to `AutoCreateLevel::CreateOnly`,
  `false` to `AutoCreateLevel::None`.
- `EventSourcingConfiguration::withInitializeEventStoreOnStart(bool)` is deprecated in favour of
  `DbalConfiguration::withAutomaticTableInitialization()` / `AutoCreateLevel`. It still works unchanged (it is
  combined with the `DbalConfiguration` setting for the event store's own tables), but new code should configure
  auto-create through `DbalConfiguration` only.
- Remove any application code that relied on the implicit commit (for example, DDL issued from inside a handler on
  MySQL) — it is no longer swallowed, and a genuinely failing commit now throws.
- `#[ProjectionInitialization]` handlers now run **before** the projection-state transaction is opened, instead of
  inside it. Previously a partition's first batch opened the transaction and then called your initialization handler
  from within it, so DDL in that handler triggered MySQL's implicit commit and broke the later commit. Your handler
  may still create its read-model tables, but it is no longer covered by the projection-state transaction: if
  initialization succeeds and the batch then fails, the initialization is not rolled back. Make initialization
  idempotent — `CREATE TABLE IF NOT EXISTS` rather than a bare `CREATE TABLE` — since it may be re-attempted.
- **Before:** a synchronous `#[CommandHandler]` marked `#[WithoutDatabaseTransaction]` was only honoured when it ran
  through a `#[ConsoleCommand]` or an asynchronous endpoint. Dispatched through `CommandBus` (`send()` /
  `sendWithRouting()`), the transaction was still opened around the whole gateway call before routing picked a
  handler, so the attribute had no effect there — a handler doing its own DDL still hit MySQL/MariaDB's implicit
  commit despite being marked `#[WithoutDatabaseTransaction]`.
  **Now:** `#[WithoutDatabaseTransaction]` is honoured for command handlers reached through `CommandBus`, whether
  dispatched with `send()` (routed by the command's class) or `sendWithRouting()` (routed by an explicit routing key).
  **How to adapt:** nothing to change in application code — mark the handler `#[WithoutDatabaseTransaction]` as
  documented and it is skipped regardless of whether it is called directly, through a console command, an
  asynchronous endpoint, or the command bus via `send()` / `sendWithRouting()`.

### 8a. The setup CLI covers every connection Ecotone knows about at configuration time

**Before (early 2.0):** `ecotone:migration:database:setup` was wired to a single `DatabaseSetupManager`, bound to
whichever connection `DbalConfiguration::withDefaultConnectionReferenceNames()` (or the DBAL default) resolved to. A
feature declared on any other connection — most commonly an event stream declared with
`#[Stream('orders_stream', connectionReferenceName: 'secondary')]` — was written to and read from correctly, but its
table was invisible to the command: absent from the status table, `--sql`, and `--missing`, and the missing-table
`ConfigurationException` told you to run a command that only ever touched the default connection.

**Now:** Ecotone builds one `DatabaseSetupManager` per connection it can see declared in your configuration — the
default connection(s), plus the connection named by every feature that accepts a `connectionReferenceName`
(`#[Stream]`, `DbalConfiguration::withDeduplication()`, `withDeadLetter()`, `withDocumentStore()`) — and a
`DatabaseSetupManagerRegistry` that holds all of them. `DatabaseSetupManager::class` still resolves to the default
connection's manager for backward compatibility.

- **Status output names the connection.** `ecotone:migration:database:setup` (and `--sql`, `--missing`) now show a
  `Connection` column; the same feature name can appear more than once, once per connection it is declared on.
- **`--connection=<reference>`** scopes any invocation (status, `--sql`, `--missing`, `--initialize`, and
  `ecotone:migration:database:delete`) to one connection. Omitting it covers every connection Ecotone knows about —
  the default invocation does not silently skip a secondary connection any more.
  ```
  bin/console ecotone:migration:database:setup --initialize --feature=event_stream --connection=secondary
  ```
- **The missing-table exception names the connection** whenever the table is not on the default one, and its
  suggested command carries the matching `--connection=` flag (or, without a console, calls
  `DatabaseSetupManagerRegistry::getManagerFor('secondary')` instead of the default-connection-only
  `DatabaseSetupManager`):
  ```
  The 'orders_stream' table required by the 'event_stream' feature does not exist on connection 'secondary'.
  Run:
    bin/console ecotone:migration:database:setup --initialize --feature=event_stream --connection=secondary
  ```
- **What is out of scope: multi-tenant connections.** A tenant's connection (`MultiTenantConfiguration` /
  `HeaderBasedMultiTenantConnectionFactory`) is resolved per message from a tenant header at runtime — there is no
  active header outside message processing, so the CLI (and the setup manager it builds) cannot open that connection
  to create or inspect tables on it. Pointing a feature's `connectionReferenceName` at a multi-tenant reference is not
  supported by `ecotone:migration:database:setup`; provision each underlying per-tenant database yourself (loop your
  own tenant list and run the migration tool against each physical connection, or call
  `DatabaseSetupManager::getCreateSqlStatementsForFeatures()` against a `ConnectionFactory` you construct for that
  tenant) rather than expecting the CLI to enumerate tenants for you.
- This generalizes beyond event streams: any table manager whose feature is configured on a non-default connection —
  today that is deduplication, dead letter and the document store, alongside event streams — is now covered the same
  way. `message_queue` (DBAL-backed channels/publishers) and `projection_state` are still tied to the default
  connection; they do not yet accept a per-feature `connectionReferenceName`.

## 9. Changed defaults

| Setting | 1.x default | 2.0 default | Where to override |
|---|---|---|---|
| JMS: serialize `null` properties | off | on | `JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)` |
| JMS: native enum support | off | on | `->withDefaultEnumSupport(false)` |
| `SimpleMessageChannelBuilder::createQueueChannel()` delayable | `false` | `true` | `createQueueChannel('x', delayable: false)` |
| `ExecutionPollingMetadata::createWithTestingSetup()` messages handled | 1 | 100 | `createWithTestingSetup(handledMessageLimit: 1)` |
| Instant retries on asynchronous endpoints | disabled | enabled (3 attempts) | `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)` |
| Module packages | all except explicitly skipped | Core + Asynchronous + what `withModulePackages()` lists; with no call, all installed packages load | `ServiceConfiguration::withModulePackages([...])` |

Test-suite impact of the new defaults:
- A test that asserted "exactly one message handled per `run()`" now sees up to 100; pass
  `ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)` (or `--handledMessageLimit=1` on `ecotone:run`).
- A test that expected the first consumer run to fail and a second run to succeed now sees the failure retried instantly inside the first
  run; disable it for that test with `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)`
  or assert the retried outcome.

Delayable channels change in-memory behaviour: a message sent with `delay` is not visible to `run()` until the
clock passes the delay. Use `$ecotone->advanceTimeBy(Duration::seconds(5))->run('x')` (§15)
or `TestConfiguration::createWithDefaults()->withSpyOnChannel()` to assert delayed messages.

## 10. Aggregate identifier resolution for queued messages (unchanged)

Consumer-side identifier resolution stays as it was in 1.x: when a message reaches an aggregate handler without the
`aggregate.id` header, the identifier is resolved again from the payload at consumption time. This also covers messages
whose `aggregate.id` is supplied by a `#[Before]` or `#[Presend]` interceptor rather than by the sending bus.

**How to adapt:** Nothing. Queues produced by older Ecotone versions do not need draining.

## 11. Routing keys and channel names

**Before:** An asynchronous channel name could not be equal to a routing key of a handler.

**Now:** Routing keys and message channels are separate namespaces; the same string may be used for both.

**How to adapt:** Nothing; remove workarounds that renamed channels to avoid the clash.

## 12. Framework configuration: `ServiceContext` only

> **Planned — not implemented yet.** The behaviour below is not in the codebase; nothing to do at this point.
>
> **TODO** — design and implementation plan: `docs/superpowers/specs/2026-08-28-servicecontext-only-config-design.md`
> (section "Implementation plan", 9 steps; research: `docs/superpowers/research/servicecontext-only-config/report.md`).
> First part of the work: `ServiceConfiguration::mergeWith()` currently merges only the serialization media type and
> error channel from `#[ServiceContext]`, so most values set there are ignored today. The final list of keys that stay
> in framework config is decided in the plan and may differ from the list below. Awaiting maintainer answers to the
> plan's open questions.

**Before:** Symfony `config/packages/ecotone.yaml` and Laravel `config/ecotone.php` exposed most `ServiceConfiguration` options
(`serviceName`, `defaultSerializationMediaType`, `defaultErrorChannel`, `skippedModulePackageNames`, `loadAppNamespaces`, ...).

**Now:** Framework config files keep only bootstrap keys that must be known before the container is built:
`namespaces`, `cacheConfiguration`, `licenceKey`, `loadSrcNamespaces`, `failFast`, `test`. Every other option is set from a
`#[ServiceContext]` method returning `ServiceConfiguration`.

**How to adapt:**

```php
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function service(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withServiceName('billing')
            ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON)
            ->withDefaultErrorChannel('errorChannel');
    }
}
```

Delete the corresponding keys from `ecotone.yaml` / `config/ecotone.php`; the bundle/provider rejects unknown keys.

## 13. Public API moved to `Api` namespaces, split into `Attribute` / `ExtensionObject` / `Gateway` / module sub-namespaces

**Before:** User-facing attributes and configuration objects lived in module namespaces
(`Ecotone\Messaging\Attribute\*`, `Ecotone\Modelling\Attribute\*`, `Ecotone\Projecting\Attribute\*`, `Ecotone\Dbal\Attribute\*`,
`Ecotone\Messaging\Config\ServiceConfiguration`, `Ecotone\Dbal\Configuration\DbalConfiguration`, ...).

**Now:** Everything you are meant to reference from application code — attributes, `#[ServiceContext]` extension
objects, and gateways/buses alike — lives under `Ecotone\Api`, organized by kind:
- attributes that are cross-cutting messaging/modelling vocabulary → `Ecotone\Api\Attribute\*`
  (e.g. `Ecotone\Api\Attribute\CommandHandler`, `Ecotone\Api\Attribute\EventHandler`, `Ecotone\Api\Attribute\Asynchronous`,
  `Ecotone\Api\Attribute\Aggregate`, `Ecotone\Api\Attribute\Saga`, `Ecotone\Api\Attribute\Header`)
- configuration/extension-object value objects → `Ecotone\Api\ExtensionObject\*`
  (e.g. `Ecotone\Api\ExtensionObject\ServiceConfiguration`, `Ecotone\Api\ExtensionObject\PollingMetadata`,
  `Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder`, `Ecotone\Api\ExtensionObject\DistributedServiceMap`)
- gateway interfaces → `Ecotone\Api\Gateway\*`
  (e.g. `Ecotone\Api\Gateway\CommandBus`, `Ecotone\Api\Gateway\EventBus`, `Ecotone\Api\Gateway\QueryBus`,
  `Ecotone\Api\Gateway\DistributedBus`, `Ecotone\Api\Gateway\DocumentStore`, `Ecotone\Api\Gateway\MessagePublisher`)
- classes that belong to a feature sub-module → `Ecotone\Api\<Module>\*`, flat inside the module with **no** further
  `Attribute`/`ExtensionObject`/`Gateway` segment (e.g. `Ecotone\Api\Projecting\Projection`,
  `Ecotone\Api\Projecting\ProjectionDelete`, `Ecotone\Api\Projecting\ProjectingManager`) — a module-scoped class stays
  flat inside its module even when it would otherwise be an attribute or extension object
- classes belonging to another Composer package → `Ecotone\Api\<Package>\*`, and only split into kind sub-namespaces
  when that package's own `Api` dir mixes enough kinds to warrant it — today only `Dbal` does
  (`Ecotone\Api\Dbal\Attribute\DbalWrite`, `Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration`,
  `Ecotone\Api\Dbal\Gateway\DeadLetterGateway`); every other package (`Amqp`, `Kafka`, `Redis`, `Sqs`, `Symfony`,
  `Laravel`, `Tempest`, `DataProtection`, `JMSConverter`, `EventSourcing`) stays flat, e.g.
  `Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder`, `Ecotone\Api\Kafka\KafkaMessageChannelBuilder`,
  `Ecotone\Api\Laravel\LaravelConnectionReference`, `Ecotone\Api\Symfony\SymfonyConnectionReference`,
  `Ecotone\Api\Tempest\TempestConnectionReference`, `Ecotone\Api\EventSourcing\EventSourcingConfiguration`,
  `Ecotone\Api\JMSConverter\JMSConverterConfiguration`, `Ecotone\Api\Redis\*`, `Ecotone\Api\Sqs\*`,
  `Ecotone\Api\DataProtection\*`

New classes, not renamed from 1.x, follow the same rules: DCB's attributes (`#[EventTag]`, `#[DecisionModel]`,
`#[DecisionBoundary]` — cross-cutting modelling vocabulary, same precedent as `#[EventSourcingAggregate]`) are
`Ecotone\Api\Attribute\*`; its core interfaces and value objects (`EventCriteria`, `AppendCondition`,
`DecisionModelConcurrencyException`) are module-scoped, `Ecotone\Api\EventSourcing\*` — living in
core (`packages/Ecotone`) even though that namespace is also where `PdoEventSourcing`'s own `Api` classes
(`EventSourcingConfiguration`, `Stream`) live; Composer merges both packages' directories under the one namespace.

Classes outside `Api` are `@internal` and may change in minor versions. `DistributedServiceMap` and `DistributedBusHeader` (formerly
`Ecotone\Modelling\Api\Distribution\*`) become `Ecotone\Api\ExtensionObject\DistributedServiceMap` and
`Ecotone\Api\Gateway\DistributedBusHeader`; `KafkaHeader` (formerly `Ecotone\Kafka\Api\KafkaHeader`) becomes
`Ecotone\Api\Kafka\KafkaHeader`, consistent with every other Kafka class (Kafka's `Api` dir is small enough to stay flat).

**How to adapt:** Replace the imports using the full old → new mapping in
[`upgrade/namespace-map-2.0.csv`](https://github.com/ecotoneframework/ecotone-dev/blob/2.0/upgrade/namespace-map-2.0.csv)
(two columns, `old_fqcn,new_fqcn`) — a find-and-replace or `sed` over your `use` statements covers it. The full
judgement-call reasoning behind each placement (which attributes stayed cross-cutting vs. became module-scoped, and
why only `Dbal` split into kind sub-namespaces) is recorded in
[`docs/superpowers/specs/2026-09-16-api-namespace-layout-mapping.md`](https://github.com/ecotoneframework/ecotone-dev/blob/2.0/docs/superpowers/specs/2026-09-16-api-namespace-layout-mapping.md).
Examples:

| 1.x | 2.0 |
|---|---|
| `Ecotone\Modelling\Attribute\CommandHandler` | `Ecotone\Api\Attribute\CommandHandler` |
| `Ecotone\Messaging\Attribute\Asynchronous` | `Ecotone\Api\Attribute\Asynchronous` |
| `Ecotone\Projecting\Attribute\ProjectionV2` | `Ecotone\Api\Projecting\Projection` |
| `Ecotone\Messaging\Config\ServiceConfiguration` | `Ecotone\Api\ExtensionObject\ServiceConfiguration` |
| `Ecotone\Dbal\Configuration\DbalConfiguration` | `Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration` |
| `Ecotone\Amqp\AmqpBackedMessageChannelBuilder` | `Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder` |
| `Ecotone\Modelling\CommandBus` | `Ecotone\Api\Gateway\CommandBus` |

The full mapping is in `upgrade/namespace-map-2.0.csv`.

### 13a. `MediaType`, `FinalFailureStrategy` and `RetryTemplateBuilder` moved into `Api` too

**Before:** three value objects an application has to write sat in `@internal` namespaces, even though the `Api`
classes that take them are public:

```php
use Ecotone\Messaging\Conversion\MediaType;                              // internal
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;                     // internal
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;       // internal

ServiceConfiguration::createWithDefaults()
    ->addExtensionObject(ErrorHandlerConfiguration::create('errorChannel', RetryTemplateBuilder::exponentialBackOff(1000, 2)))
    ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel(
        'orders',
        conversionMediaType: MediaType::createApplicationXPHP(),
        finalFailureStrategy: FinalFailureStrategy::STOP,
    ));
```

**Now:** all three are `Ecotone\Api\ExtensionObject\*`, beside the `ServiceConfiguration`,
`ErrorHandlerConfiguration` and `SimpleMessageChannelBuilder` that take them:

```php
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Messaging\Conversion\MediaType` | `Ecotone\Api\ExtensionObject\MediaType` |
| `Ecotone\Messaging\Endpoint\FinalFailureStrategy` | `Ecotone\Api\ExtensionObject\FinalFailureStrategy` |
| `Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder` | `Ecotone\Api\ExtensionObject\RetryTemplateBuilder` |

**How to adapt:** replace the three imports. Nothing else changes — same class names, same methods, same enum cases.

Only the builder moved, not what it builds: `RetryTemplateBuilder::build()` still returns
`Ecotone\Messaging\Handler\Recoverability\RetryTemplate`, which stays internal because the framework, not the
application, calls `build()`. `MediaType` is also still what `CommandBus`, `QueryBus`, `EventBus`,
`DistributedBus`, `MessagePublisher` and `#[ContentType]` name, which is why it could not stay in `src`: a public
signature was forcing an `@internal` import on every application that used it. The rule this closes is
[conventions rule 12a](docs/coding-conventions.md#12a-the-boundary-holds-in-both-directions).

## 14. Smaller behaviour changes

- `ServiceConfiguration::withSkippedModulePackageNames()` → `withModulePackages()` (see §1/§9).
- **TODO:** `MessageHeaders::STREAM_BASED_SOURCED` is planned to be replaced by the `#[StreamBasedSource]` attribute
  check. Not done yet — the header still exists; nothing to change.
- `ecotone/lite-application` package is discontinued; use `ecotone/ecotone` `EcotoneLite::bootstrap()`.
- Laravel: `LaravelConnectionReference::defaultConnection()` resolves to the Ecotone DBAL factory (§5); `SHELL_VERBOSITY` handling in tests unchanged.
- **`#[Asynchronous]` requires an explicit `endpointId` on `#[InternalHandler]`**, as it
  already did for `#[CommandHandler]`/`#[EventHandler]`. Before, a generated endpoint id was accepted and failed later
  with an unrelated missing-channel error. Bootstrap now throws a `ConfigurationException` naming the class and method,
  ending with `should have endpointId defined for handling asynchronously`.
  **How to adapt:** add it — `#[InternalHandler('orders.process', endpointId: 'orders.process.endpoint')]`, and use
  that id with `run()` / consumer commands.
- **`changingHeaders: true` on `#[InternalHandler]` requires an Enterprise licence.** In this
  mode the returned `array` is merged into the message headers, the payload is left untouched, and the message
  continues to `outputChannelName`. Without a licence bootstrap throws `LicensingException` naming the method.
  **How to adapt:** provide the licence key, or move the header enrichment into a `#[Before(changeHeaders: true)]`
  interceptor on the handler that needs the headers.
- **Returning `null` in header-changing mode keeps the message.** For `changingHeaders: true` handlers and for
  `#[Before]`, `#[Presend]`, `#[After]` and `#[ChannelInterceptor]` with `changeHeaders: true`, a `null`/`void` return
  used to drop the message silently; it now passes the message on unchanged. If you relied on `null` to stop the flow,
  throw or route explicitly instead.
- **Gateway `iterable<T>` replies are converted element by element.** A `#[MessageGateway]`/`#[BusinessMethod]`
  declaring `@return iterable<Foo>` used to receive the raw array unconverted when the handler `return`ed
  an array (only a `yield`ing handler was converted). Each element now goes through the registered converters, as the
  return type says. If you worked around it by converting in the caller, remove that step.
- **`#[LogBefore]` / `#[LogAfter]` work.** In 1.x they threw on the first handler call and were unusable; they now log
  the payload (and headers with `logFullMessage: true`) through the configured PSR logger. They moved, with
  `#[LogError]`, to `Ecotone\Api\Attribute\LogBefore`, `Ecotone\Api\Attribute\LogAfter` and `Ecotone\Api\Attribute\LogError` (§13).
- **New, Enterprise: `#[ChannelInterceptor('channelName')]`.** A method with this attribute runs as a pre-send
  interceptor for that exact channel, with the same `changeHeaders` / `precedence` semantics as `#[Before]` /
  `#[Presend]`. In 1.x the attribute existed but did nothing. Nothing to change unless you had it in code expecting it
  to be ignored; without a licence bootstrap throws `LicensingException`.
- **DBAL 3 compatibility layer removed (internal).** With DBAL 4 as the minimum (see the top of this guide),
  `Ecotone\Dbal\Compatibility\QueryBuilderProxy` and `Ecotone\Dbal\Compatibility\SchemaManagerCompatibility` are gone,
  together with the version checks around `getSchemaManager()`, `ArrayParameterType`, `ParameterType` and
  `connect()`/`close()`; Ecotone calls DBAL 4 directly. Both classes were internal (`src/`, never part of `Api/`), so
  there is nothing to change. If you referenced them anyway, use the native methods: `$connection->createQueryBuilder()`
  with its own `executeQuery()` / `executeStatement()` / `fetch*()`, and
  `$connection->createSchemaManager()->tableExists($table)`.

### Aggregate snapshots are invalidated when the aggregate folds other events

**Before:** A stored aggregate snapshot was loaded whenever one existed for the instance. Adding or removing an
`#[EventSourcingHandler]` left every stored snapshot loadable, so the aggregate came back folded by the old rules
and silently stayed that way until enough new events accumulated to overwrite it.

**Now:** Every snapshot is written beside a marker recording the fold it was taken with — the aggregate class and
its sorted `#[EventSourcingHandler]` event types — in a sibling collection `aggregate_snapshot_fold_shapes_<Class>`
of the same document store. On load, a marker that is missing or names another fold makes the snapshot stale: it is
logged and ignored, the aggregate is replayed from its events, and the next save writes a fresh snapshot and marker.

**What to expect on upgrade:** snapshots written by 1.x carry no marker, so each snapshotted aggregate instance is
replayed in full once, on its first load after the upgrade, and re-snapshotted on its next save. The snapshot
documents themselves are unchanged and need no migration. Applications that do not use
`withSnapshotsFor(...)` are unaffected.

### Expression failures name their place

**Before:** an expression that failed at runtime threw whatever the cause threw. A typo was a Symfony
`SyntaxError`, a misspelled service an `\InvalidArgumentException` from the container, a mapper that blew up its own
exception, and a `#[Fetch]` whose result did not fit the model a `ConfigurationException` reading
`#[Fetch] expression for DecisionModel App\CouponRedemptions did not resolve tag 'coupon'.` None of them said which
attribute, which parameter or which method the expression was written on, and none of them quoted the expression.

**Now:** every expression Ecotone evaluates has one failure boundary, and every failure that crosses it is an
`Ecotone\Messaging\Handler\ExpressionEvaluationException` — a **runtime** `MessagingException`, so a retry policy
and a `catch` can tell it apart from a boot problem. Its message follows one template:

```
<attribute> on <target> in <Class::method> failed. Expression: <expression>. <cause>
```

```
#[Fetch] on $coupon in App\OrderService::place failed. Expression: headers['couponCode']. DecisionModel App\CouponRedemptions did not resolve tag 'coupon'. The expression returned null. A single-tag model needs a scalar or Stringable value; declare the parameter nullable to let it contribute nothing.
```

```
#[Payload] on $total in App\OrderService::place failed. Expression: reference('pricing').total(payload). Reference pricing was not found in definitions
```

The original exception is kept as `$previous`, so nothing is lost. An expression whose own inner expression already
failed is not wrapped twice. A closure expression reports `Expression: closure`. A class- or method-level attribute
such as `#[AddHeader]` or `#[Deduplicated]` has no target, so it reads `#[AddHeader] in App\OrderService::place failed.`
The enricher and the expression transformer name the edited path and the input channel instead of a method —
`Enricher on payload path 'token' failed.`, `Transformer in endpoint 'orders.normalise' failed.`

This covers `#[Fetch]` (decision model tag values, aggregate-backed model identifiers and fetched aggregates),
`#[Payload(expression:)]` on handlers and gateways, `#[Header(expression:)]`, `#[Reference(expression:)]`,
`#[AddHeader]` and the attributes extending it (`#[Delayed]`, `#[Priority]`, `#[TimeToLive]`, `#[ContentType]`),
`#[Deduplicated]`, `#[DbalParameter]`, the expression transformer and the enricher's request payload and property
editors — string expressions and PHP 8.5 closures alike.

**Runtime or bootstrap.** `ExpressionEvaluationException` is thrown per message, for things only a message can
reveal: a syntax error in an expression that was never evaluated before, a service the container does not have, an
absent header, a mapper that throws, a result that does not fit the model. `ConfigurationException` stays what it
always was — a problem found while building the container, including a tag that cannot be resolved by convention
and every `#[DecisionModel]` / `#[DecisionBoundary]` guard.

**How to adapt:** nothing, unless you catch these by class. Three catches move:

| Was | Is now |
|---|---|
| `ConfigurationException` from a `#[Fetch]` tag value or identifier that did not resolve or normalise | `ExpressionEvaluationException` |
| `AggregateNotFoundException` from a `#[Fetch]`-ed aggregate whose expression resolved no identifier | `ExpressionEvaluationException` (a genuine not-found still throws `AggregateNotFoundException`) |
| `InvalidArgumentException` from a required header missing for an expression-backed `#[Header]` | `ExpressionEvaluationException` |

A handler *parameter* still surfaces through `MethodInvocationException` ("Cannot resolve parameter 'x' while
calling ..."), as every parameter converter always has; the expression failure is its `$previous` and its message is
quoted under `Reason:`.

**A `bool` is no longer a tag value.** `#[Fetch('true')]` silently produced the tag value `"1"` and `#[Fetch('false')]`
produced a confusing "value cannot be empty"; both are now rejected with
`Tag 'account' value must be a string, int, float or Stringable, got bool. Did you mean to compare instead of return?`
This applies to `#[EventTag]` values as well, for the same reason: a boolean tag value is always a mistake, usually a
comparison written where a value was meant.


## 15. Testing and developer-experience changes

These changes make tests and error messages say what happens, so that failures point at the real cause. Most of them
rename test-support methods without aliases; the renamed methods are listed in each entry.

- **Retries may use a zero back-off.** `RetryTemplateBuilder::fixedBackOff(0)`, an exponential back-off starting at `0`
  and `#[DelayedRetry(initialDelayInMilliseconds: 0)]` used to throw `Initial delay must be greater than 0`. A zero delay is now
  accepted: the failed message is sent back to its channel without a delivery delay, so a single
  `run('async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false))` walks it through every retry and
  into the dead letter. Negative delays still throw, naming the value
  (`Retry initial delay must be 0 or greater, got -1 ms`).
  **How to adapt:** nothing. Tests that advanced the clock only to get past a 1 ms back-off can use `0` instead.
- **Retry exhaustion counts deliveries, not "retries".** With `maxRetryAttempts(3)` the handler is delivered 4 times
  (1 initial + 3 retries), but the log said `retried maximum number of \`4\` times` and the exception said
  `Message handling failed after 4 retry attempts`. They now read
  `Sending message \`…\` to dead letter channel after 4 failed deliveries (1 initial + 3 retries). Due to: …`,
  `No dead letter channel defined. Message failed after 4 failed deliveries (1 initial + 3 retries). …` and
  `Message handling failed on channel \`async\` after 4 failed deliveries (1 initial + 3 retries). RuntimeException: …`
  (the exception now also names the channel and the original exception class). The number of deliveries is unchanged.
  **How to adapt:** update log-based alerts or tests that match the old texts.
- **The test clock stays where the test put it.** After `changeTimeTo()`, `advanceTimeBy()` or a `StaticPsrClock` created
  with a fixed time, `run()` used to move the clock forward by its internal polling waits (about 1 ms per handled
  message and 10 ms at the end), so handlers recorded `13:00:00.010000` instead of `13:00:00.000000` and setting the same
  time again failed. Now every message handled by `run()` sees the time the test set, and the clock is back at that time
  when `run()` returns.
  **How to adapt:** remove workarounds that shifted fixture times to get past the drift.
- **`changeTimeTo()` accepts the current instant.** Setting the time it already shows is a no-op; only an earlier time
  throws, now with `Cannot move time backwards: the test clock is at 2026-03-01 13:00:00.000000 and you requested
  2026-03-01 12:00:00.000000. Request a later time, or use advanceTimeBy() to move forward relative to the current time.`
  **How to adapt:** nothing.
- **`advanceTimeTo(Duration)` is renamed `advanceTimeBy(TimeSpan|Duration)`, and `run()` no longer takes a time
  argument.** The third argument of `run($name, $metadata, $releaseAwaitingFor)` released messages whose *original delay*
  was at most the given span (or which were due at a given date) without moving the clock, so `run(…, 23h)` followed by
  `run(…, 2h)` never released a 24-hour delay. Time now moves only through the clock, and `run()` delivers what is due
  at the clock's current time.
  **How to adapt:**

  | 1.x / early 2.0 | 2.0 |
  |---|---|
  | `$ecotone->advanceTimeTo(Duration::seconds(5))` | `$ecotone->advanceTimeBy(Duration::seconds(5))` |
  | `$ecotone->run('async', $metadata, TimeSpan::withHours(1))` | `$ecotone->advanceTimeBy(TimeSpan::withHours(1))->run('async', $metadata)` |
  | `$ecotone->run('async', releaseAwaitingFor: $dateTime)` | `$ecotone->changeTimeTo($dateTime)->run('async')` |

  A test that used the span as "release everything delayed by up to X" while keeping the clock still must now move the
  clock; set a fixed time first (`changeTimeTo()`) so second-precision message timestamps do not make the release flaky.
- **Due delayed messages are delivered in the order they became due.** The in-memory delayable channel handed out due
  messages in send order, so a 24-hour expiry sent before a 1-hour reminder ran first once both were due. It now delivers
  the earliest due message first (send order for equal due times), as a broker with delivery delay does.
  **How to adapt:** tests that asserted send order for messages with different delays assert due order instead.
- **Retry builder names say what they count and in which unit.** `maxRetryAttempts(3)` read as "3 attempts in total",
  but it allows 3 retries after the first delivery. `exponentialBackoff` was spelled differently from `fixedBackOff`, and
  the delay parameters did not name their unit.

  | 1.x / early 2.0 | 2.0 |
  |---|---|
  | `RetryTemplateBuilder::fixedBackOff(initialDelay: 1000)` | `RetryTemplateBuilder::fixedBackOff(delayInMilliseconds: 1000)` |
  | `RetryTemplateBuilder::exponentialBackoff($initialDelay, $multiplier)` | `RetryTemplateBuilder::exponentialBackOff($initialDelayInMilliseconds, $multiplier)` |
  | `RetryTemplateBuilder::exponentialBackoffWithMaxDelay($initialDelay, $multiplier, $maxDelay)` | `RetryTemplateBuilder::exponentialBackOffWithMaxDelay($initialDelayInMilliseconds, $multiplier, $maxDelayInMilliseconds)` |
  | `->maxRetryAttempts(3)` | `->maxRetries(3)` |
  | `#[DelayedRetry(initialDelayMs: 100, maxDelayMs: 1000, maxAttempts: 3)]` | `#[DelayedRetry(initialDelayInMilliseconds: 100, maxDelayInMilliseconds: 1000, maxRetries: 3)]` |

  Behaviour is unchanged. **How to adapt:** rename the calls; positional arguments keep working, named arguments use the
  new parameter names.
- **Recorded-message readers are named `pop*`, because they remove what they return.** `getRecordedEvents()` returned
  the events recorded since the previous call and cleared the list, so asserting twice saw nothing the second time. The
  destructive behaviour stays; the names now say it, and typed variants remove only messages of one class.

  | 1.x / early 2.0 | 2.0 |
  |---|---|
  | `FlowTestSupport::getRecordedEvents()` | `popRecordedEvents()` |
  | — | `popRecordedEventsOfType(OrderWasPlaced::class)` (removes only events of that class) |
  | `FlowTestSupport::getRecordedEventHeaders()` / `getRecordedEventRouting()` | `popRecordedEventHeaders()` / `popRecordedEventRouting()` |
  | `FlowTestSupport::getRecordedCommands()` | `popRecordedCommands()` |
  | — | `popRecordedCommandsOfType(PlaceOrder::class)` |
  | `FlowTestSupport::getRecordedCommandHeaders()` / `getRecordedCommandsWithRouting()` | `popRecordedCommandHeaders()` / `popRecordedCommandsWithRouting()` |
  | `FlowTestSupport::getRecordedMessagePayloadsFrom($channel)` | `popRecordedMessagePayloadsFrom($channel)` |
  | `FlowTestSupport::getRecordedEcotoneMessagesFrom($channel)` | `popRecordedMessagesFrom($channel)` |
  | `MessagingTestSupport::getRecordedEvents()`, `getRecordedEventMessages()`, `getRecordedCommands()`, `getRecordedCommandMessages()`, `getRecordedQueries()`, `getRecordedQueryMessages()` | the same names with `pop` instead of `get` |
  | `WithEvents::getRecordedEvents()` (aggregate trait, also clears) | `WithEvents::popRecordedEvents()` |

  Events the test publishes itself are still recorded. `discardRecordedMessages()` is unchanged.
  **How to adapt:** replace `getRecorded` with `popRecorded` (and `getRecordedEcotoneMessagesFrom` with
  `popRecordedMessagesFrom`); a `sed -i 's/getRecorded/popRecorded/g'` over the test suite covers it. If an aggregate
  declares its own events method, keep it: the `#[AggregateEvents]` attribute, not the name, is what Ecotone looks for.
- **One naming rule for routing and dead-letter replay.** `FlowTestSupport` had `sendCommandWithRoutingKey()` and
  `publishEventWithRoutingKey()` next to `sendQueryWithRouting()`, while the buses use `sendWithRouting()`. The dead
  letter gateway had `reply()`/`replyAll()` while its console commands are `ecotone:deadletter:replay`/`replayAll`.

  | 1.x / early 2.0 | 2.0 |
  |---|---|
  | `FlowTestSupport::sendCommandWithRoutingKey()` | `sendCommandWithRouting()` |
  | `FlowTestSupport::publishEventWithRoutingKey()` | `publishEventWithRouting()` |
  | `DeadLetterGateway::reply($messageId)` | `DeadLetterGateway::replay($messageId)` |
  | `DeadLetterGateway::replyAll()` | `DeadLetterGateway::replayAll()` |
  | `DbalDeadLetterBuilder::createReply()` / `createReplyAll()` | `createReplay()` / `createReplayAll()` |

  The internal dead-letter channels are renamed with them (`ecotone.dbal.deadletter.replay`, `…replayAll`); console
  command names are unchanged. **How to adapt:** rename the calls, e.g.
  `sed -i 's/WithRoutingKey(/WithRouting(/g; s/->reply(/->replay(/g; s/->replyAll(/->replayAll(/g'`.
- **A command or query sent to a handler that is not registered in a flow test names the class to register.** An
  Ecotone Lite bootstrap registers only the classes it lists, but the error suggested a missing attribute:
  `No Command Handler defined for it. Have you forgot to add #[CommandHandler] to method?`. In
  `bootstrapFlowTesting()` Ecotone now looks up the handler in your autoloaded (non-vendor) namespaces and says
  `Can't send command to App\Shipping\ReserveShippingSlot. It is handled by App\Shipping\ShippingSlotReservationHandler::reserve(),
  which is not registered in this Ecotone Lite bootstrap. Add App\Shipping\ShippingSlotReservationHandler to the classesToResolve of
  EcotoneLite::bootstrapFlowTesting(), or load its namespace with ServiceConfiguration::withNamespaces(['App\Shipping']).`
  When no handler exists it says which attribute to add. Queries get the same message; the application bootstrap keeps
  the previous text. **How to adapt:** nothing.
- **Handlers typed on an interface or abstract class receive the concrete message after serialisation.** A handler
  such as `#[Asynchronous('async')] #[EventHandler] onChange(BasketContentChanged $event)`, where `BasketContentChanged`
  is an interface, worked in process but failed once the message crossed a serialising channel
  (`… is an interface, and cannot be instantiated`); on an aggregate the same handler failed with the misleading
  `identifier header is missing`, and a projection handler failed on stored events. Ecotone now deserialises into the
  concrete class named by the message's `__TypeId__` header (or the stored event name for projections) whenever the
  parameter type is an interface or abstract class the concrete class implements, and resolves aggregate identifiers from
  the concrete payload. When no concrete class is known, the conversion error ends with
  `Parameter $event is typed with …BasketContentChanged, which cannot be instantiated, and the message does not name a
  concrete class in its __TypeId__ header. Type the parameter with a concrete class or a union of concrete classes, or
  send an object instead of an array.`
  **How to adapt:** nothing; one interface-typed handler can replace per-event copies written as a workaround.
- **`AggregateNotFoundException` says which handler sent the command and why.** When a command sent from inside
  another handler targeted a missing aggregate, the message named only the aggregate and identifiers, so finding the
  saga or event handler that sent it meant searching the code. The message now continues with the causation chain:
  `Aggregate App\Wallet for calling chargeFunds was not found using identifiers {"walletId":"wallet-404"}. Command
  App\ChargeFunds was sent by App\WalletChargeHandler::onOrderCreated() while handling event App\OrderCreated, which was
  published while handling command App\CreateOrder.` The exception class is unchanged; the original exception is its
  `previous`. A command sent directly from the test or a controller keeps the short message.
  **How to adapt:** tests asserting the exact message with `assertSame` switch to `assertStringStartsWith`.
- **A service parameter taken as the payload says so.** The first handler parameter without an attribute is the
  message payload, so `#[CommandHandler('basket.clear')] public function clear(ClockInterface $clock)` tried to convert
  the (empty) payload into `ClockInterface` and failed with a bare conversion error. The error now ends with
  `If $clock is a service rather than the message payload, mark it with #[Reference].` (for aggregate handlers:
  `Payload of the message sent to App\Basket could not be converted into Psr\Clock\ClockInterface, the type of the first
  handler parameter without an attribute. If that parameter is a service rather than the message payload, mark it with
  #[Reference].`). Parameter resolution is unchanged.
  **How to adapt:** nothing; add `#[Reference]` where the message tells you to.
- **New: `#[Delayed]` accepts named durations.** `#[Delayed(hours: 24)]` and `#[Delayed(minutes: 30, seconds: 10)]`
  (`milliseconds`, `seconds`, `minutes`, `hours`, `days`) work next to `#[Delayed(new TimeSpan(hours: 24))]`. Passing both a
  `$time` and a named duration throws `#[Delayed] takes either $time or named durations (milliseconds, seconds, minutes,
  hours, days), not both.` **How to adapt:** nothing.
- **New: `WithAggregateVersioning::getVersion()`.** Aggregates using the trait expose their current version. The version
  also travels with every recorded event in the `MessageHeaders::EVENT_AGGREGATE_VERSION` header, including across
  asynchronous channels, so a delayed handler can compare it with the aggregate's current version.
  **How to adapt:** nothing; remove your own `getVersion()` accessor if it only returned the trait's property.
- **The Enterprise-only `#[EventSourcingHandler]` metadata parameter names the open-source alternative.** Without a
  licence, a second parameter such as `#[Header(MessageHeaders::TIMESTAMP)] int $recordedAt` fails with `… is part of
  Enterprise features. Without Enterprise, keep a single event parameter and carry the value you need (for example the
  time of the change) in the event itself. To read metadata here, obtain Enterprise: https://docs.ecotone.tech/enterprise`.
  **How to adapt:** nothing.
- **Testing polling parameters use the names of the matching setters.** `createWithTestingSetup()` took
  `amountOfMessagesToHandle` (a maximum, not an exact count), `maxExecutionTimeInMilliseconds` and `failAtError`, while the
  same settings are `withHandledMessageLimit()`, `withExecutionTimeLimitInMilliseconds()` and `withStopOnError()` (and
  `--handledMessageLimit` / `--stopOnError` on `ecotone:run`).

  | 1.x / early 2.0 | 2.0 |
  |---|---|
  | `ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, maxExecutionTimeInMilliseconds: 500, failAtError: false)` | `createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 500, stopOnError: false)` |
  | `ExecutionPollingMetadata::withTestingSetup(…)`, `PollingMetadata::withTestingSetup(…)`, `createWithFinishWhenNoMessages(failAtError:)` | the same new parameter names |
  | `FlowTestSupport::run(name: 'async')` | `run(channelOrEndpointName: 'async')` |

  **How to adapt:** only named arguments change; positional calls keep working.
  `sed -i 's/amountOfMessagesToHandle:/handledMessageLimit:/g; s/maxExecutionTimeInMilliseconds:/executionTimeLimitInMilliseconds:/g; s/failAtError:/stopOnError:/g'`.
- **Contributor-only: fresh checkouts of `ecotone-dev` now bootstrap with two commands everyone
  already knows.** `docker compose up -d` builds a local image with `ext-sockets` baked in for the
  `app` service (the published `simplycodedsoftware/php:8.5.3` image doesn't have it) and blocks
  until every database/broker dependency's healthcheck passes; `.env` is optional, not required;
  `composer install` (run without `-u root`, so `vendor/` stays owned by your own user) finishes
  the bootstrap. Per-package `composer install` is now documented as an explicit step before
  `composer tests:ci`, and `packages/DataProtection/tests/before-tests.sh` no longer silently
  fails to create its fixture when run from a different working directory. See
  `docs/dev-environment-cold-start-findings.md` for the full list of gaps this closed. This only
  affects working on the `ecotone-dev` monorepo itself.
  **How to adapt:** nothing; application code using the published packages is unaffected.
- **Bootstrap is faster; nothing in configuration or behaviour changes.** Measured on the monorepo example
  application: cached `EcotoneLite::bootstrap()` takes less than half the time (2.4 ms → 1.0 ms with opcache,
  3.0 ms → 1.4 ms without), a Laravel boot in a non-cached environment is 17–25% faster, and an uncached bootstrap — what `EcotoneLite::bootstrapFlowTesting()` does inside the
  Ecotone test suites — is about 40% faster (16.4 ms → 10.0 ms). Compiled Symfony and Laravel production containers were already not on this path and are unchanged. Four internal changes produce this: `#[Environment]` filtering no
  longer instantiates every method attribute, annotated methods are indexed once per annotation finder, the use
  statements of a class file are parsed once per bootstrap, and the cache fingerprint of class files and
  `composer.lock` uses `xxh128` instead of `sha1`. Every one of them keeps its state inside objects created for a single
  bootstrap, so two bootstraps in one process (two tests, two tenants) still never see each other's configuration.
  The fingerprint remains content-based: changing any registered class file or `composer.lock` still rebuilds the cache.
  The only visible effect is that the hashed sub-directory name under the Lite cache directory changes once, so the
  first 2.0 bootstrap rebuilds the container and the directory written by the previous version is left unused.
  Method and measurements are in `docs/bootstrap-performance-2.0.md`.
  **How to adapt:** nothing. Optionally delete old `ecotone/<hash>` directories from the Lite cache directory once.

## 16. Planned 2.0 work still to be done (TODO)

These changes are designed but not implemented. Nothing here affects an upgrade today; each entry will become a
normal section with "How to adapt" steps when it ships.

| Work | What it changes | Design and implementation plan |
|---|---|---|
| `#[ServiceContext]`-only configuration (§12) | `ServiceContext` values are actually merged; framework config files keep only bootstrap keys | `docs/superpowers/specs/2026-08-28-servicecontext-only-config-design.md` · research `docs/superpowers/research/servicecontext-only-config/report.md` |
| Simpler EcotoneLite testing | Flow tests load every installed package with in-memory test profiles, instead of Core only; in-memory queue channels provided automatically for `#[Asynchronous]` handlers, still consumed with `run()` | `docs/superpowers/research/ecotone-lite-testing-simplification/report.md` (section "Implementation sketch"; no final design yet) |
| Service cache directory | Replace the cache-directory setting on `ServiceConfiguration` with an explicit bootstrap parameter; shared cache-clear command | `docs/superpowers/research/service-cache-directory/report.md` (section "Implementation plan"; overlaps with §12) |
| `ecotone:describe` introspection | A read-only API and console command that prints how messaging is configured: channels (type, delayable, consumer command), asynchronous endpoints (channel, delay, retry and dead letter), the error channel policy, converters, projections with their commands, and how each handler resolves its aggregate or saga identifier. Answers the question coding agents ask in almost every session without reading configuration files | Not designed yet — to be discussed. Input: agent benchmark findings (catalogue item A6, "How is messaging configured here?") |
| Stateless `#[EventSourcingHandler]` | A second, opt-in fold style: the handler **returns** the changed state instead of mutating `$this`, for both event-sourced aggregates and DCB decision models. A command handler then runs against the last state the fold returned. The existing mutating style stays and needs no change. Detected from the declared return type at configuration time — `void` is stateful, the model's own type is stateless — which is unambiguous because a non-void `#[EventSourcingHandler]` is already rejected at bootstrap today. A stateless state object may carry its own `#[Version]`; **userland increments it in each apply** and Ecotone verifies it increased on every applied event, which also makes a fully `readonly` state class possible on PHP 8.2. Returning the wrong type is rejected rather than ignored | `docs/superpowers/specs/2026-09-30-stateless-event-sourcing-handler-design.md` — researched against Axon 5, Marten, Akka/Pekko, Emmett/Decider and Eventuous, with the fold cost measured. Maintainer decisions on all four open questions are recorded in it. Note the measured hazard: a state object that copies a growing collection makes the fold O(n²) (19.9 µs/event by 10k events against 6.3–6.8 µs today), so the documentation must lead with the shape that avoids it |

---

## Upgrade checklist

1. Upgrade to the latest 1.x first and fix every deprecation notice.
2. Bring the platform up to the new minimums: PHP 8.2, Laravel 11+, DBAL 4, ORM 3 / DoctrineBundle 2.12+ (see the top of this guide).
3. `composer require ecotone/ecotone:^2.0` together with every `ecotone/*` package you use.
4. Replace imports with the namespace map (§13).
5. Replace `withSkippedModulePackageNames` with `withModulePackages`; remove `enableAsynchronousProcessing` and add `->run('<channel>')` in tests (§1).
6. Replace `Enqueue\Dbal\DbalConnectionFactory` references (§5).
7. Replace `AmqpDistributedBusConfiguration` with `DistributedServiceMap` (§6).
8. Move v1 projections to `#[Projection]` + `#[FromAggregateStream]`/`#[FromStream]`, give `#[ProjectionState]` parameters a default, and run `ecotone:projection:rebuild` for former v1 projections (§3).
9. Drop the persistence-strategy calls, and decide per aggregate whether it moves to `ecotone_event_stream` or stays on its 1.x table via `#[Stream(legacyStreamName: ...)]`; replace `#[FromStream(Aggregate::class)]` with `#[FromAggregateStream(Aggregate::class)]` (§4).
10. Rename `#[ServiceActivator]` to `#[InternalHandler]`, checking positional arguments (§7a). Add an explicit `endpointId` to every `#[Asynchronous]` `#[InternalHandler]` (§14).
11. Review changed defaults (§9) and set explicit values where the old behaviour is required.
12. Provide an Enterprise licence key if you use multi-tenancy (§2), `EventStreamEmitter::emit()` (§3), `changingHeaders: true` on internal handlers or `#[ChannelInterceptor]` (§14).
13. Adopting DCB decision models: create the `event_tags` tables and, only for a 1.x table a model writes into,
    relax its aggregate `NOT NULL` constraints — while still on 1.x; deploy 2.0 to every node; release `#[EventTag]`;
    run `ecotone:event-store:backfill-tags` and wait for it to finish; only then release `#[DecisionModel]` (§4).
14. Once they ship: move framework YAML/PHP config options into `#[ServiceContext]` (§12) and add `ecotone:migration:database:setup` (or dumped SQL) to your deployment (§8).
