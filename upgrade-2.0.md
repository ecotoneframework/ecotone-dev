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
- `ModulePackageList::ASYNCHRONOUS_PACKAGE` and `ModulePackageList::allPackagesExcept()` are **deleted**, not only
  unnecessary: naming either fails with PHP's undefined-constant or undefined-method error. Remove
  `ASYNCHRONOUS_PACKAGE` from every list, the load lists of `withEnabledModulePackageNames()` included — there is no package by
  that name to load. 1.x's `withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))`
  skipped everything but the packages it listed, which is what `withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])`
  now says directly: pass the same list to `withEnabledModulePackageNames()` and drop `allPackagesExcept()`. Delete calls to
  `ServiceConfiguration::createWithAsynchronicityOnly()`.
- `withSkippedModulePackageNames([...])` is replaced by `withEnabledModulePackageNames([...])` listing the packages to
  **load**; Core and Asynchronous are always loaded. Example:
  `->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::AMQP_PACKAGE])`. The getters
  follow: `getSkippedModulesPackages()` is replaced by `getEnabledModulePackageNames()`, and `areSkippedPackagesDefined()`
  by `areEnabledModulePackageNamesDefined()`. Early 2.0 builds called the method `withModulePackages()`.
- Symfony `ecotone.yaml`, Laravel `config/ecotone.php` and Tempest `EcotoneConfig`: the `skippedModulePackageNames` key is
  replaced by `enabledModulePackageNames`, which lists the packages to **load** — the meaning is inverted, so the 1.x list
  cannot be moved over unchanged. Leaving the key out (or `null`, as Laravel's shipped `config/ecotone.php` now sets it)
  loads every installed package; an explicit empty list (`enabledModulePackageNames: []`) loads Core and Asynchronous
  only — without the framework's own package (`ModulePackageList::SYMFONY_PACKAGE`, `LARAVEL_PACKAGE`,
  `TEMPEST_PACKAGE`), so list it alongside the others when the application relies on what it provides. Early 2.0
  builds called the key `modulePackages`.
- **An unrecognised framework configuration key is refused at bootstrap.** **Before:** Laravel read ten `ecotone.*`
  keys and ignored any other, so a 1.x `config/ecotone.php` keeping `skippedModulePackageNames` silently loaded every
  installed package. Symfony refused an unknown option with its generic `Unrecognized option "…" under "ecotone"`, and
  Tempest with PHP's `Unknown named parameter`, neither naming the replacement. **Now:** all three throw a
  `ConfigurationException` naming the unknown key and the keys Ecotone reads there; for `skippedModulePackageNames` and
  `modulePackages` it names `enabledModulePackageNames` and says whether the list's meaning inverted:
  `ConfigurationException: Ecotone does not recognise the \`skippedModulePackageNames\` key in config/ecotone.php. Since
  Ecotone 2.0 it is \`enabledModulePackageNames\`, and its meaning is inverted: …` **How to adapt:** rename or remove
  the key the message names; for `skippedModulePackageNames`, list the packages to load instead of the ones to skip.
- A handler referencing an unregistered channel fails at bootstrap with
  `ConfigurationException: Registered asynchronous endpoint \`orderHandler\`, however channel configuration for \`orders\` was not
  provided. Register it with SimpleMessageChannelBuilder::createQueueChannel('orders') as a ServiceConfiguration extension object
  or from a #[ServiceContext] method.`
  Flow tests (`bootstrapFlowTesting()`) do not throw this; they provide the in-memory channel instead.
- Default queue channels are delayable (see §9); nothing to change unless you relied on delays being ignored.
- **A message sent through a bus keeps its routing key on the asynchronous channel.** **Before:** the bus removed the
  routing key when it put the message on an asynchronous channel, so the handler, the outbox and the dead letter could
  not tell which name the message was sent with. **Now:** every message sent by `CommandBus`/`EventBus` to an
  `#[Asynchronous]` handler carries the header `ecotone_modelling_bus_routing_key`: the key given to `sendWithRouting()`
  or `publishWithRouting()` (for an event, the published name, not the handler's pattern), or the class-based name for a
  class-routed message. Read it with `#[Header]`. It is informational: routing on consume is unchanged, it is not passed
  on to messages the handler sends or publishes, and it stays on the stored message (outbox, dead letter) and when that
  message is replayed to another channel. A message sent to the handler by a `#[BusinessMethod]` or `#[MessageGateway]`
  routing key carries that routing key. **How to adapt:**
  nothing is required; tests asserting the exact header set of an asynchronous message see one more header.

## 2. Multi-tenancy requires Ecotone Enterprise

**Before:** `MultiTenantConfiguration`, `#[MultiTenantConnection]`, `#[MultiTenantObjectManager]`,
`#[OnTenantActivation]`, `#[OnTenantDeactivation]` and the Laravel/Symfony/Tempest tenant connection switching worked
without a licence. Only `#[WithTenantResolver]` and multi-tenant projections were gated.

**Now:** Any multi-tenant configuration or attribute requires a valid Enterprise licence key. Without it bootstrap
throws `LicensingException` ("Multi-tenancy requires Ecotone Enterprise licence … See https://docs.ecotone.tech/enterprise") naming
the offending `MultiTenantConfiguration` or attribute placement. Laravel's tenant database switching is registered only when a
Laravel-backed `MultiTenantConfiguration` exists and is licensed; Symfony and Tempest rely on the same core gate.

**How to adapt:** Provide the licence key (`ecotone.licenceKey` in Symfony,
`licenceKey` in `config/ecotone.php` in Laravel, `licenceKey` of `EcotoneConfig` in Tempest, `licenceKey:` argument of `EcotoneLite`). The shipped Laravel
config sets `'licenceKey' => null` and reads no environment variable; to keep the key in `.env`, write
`'licenceKey' => env('ECOTONE_LICENCE_KEY')` into your published `config/ecotone.php`. If you are not
licensed, replace multi-tenant connection switching with explicit per-tenant connection references and route
messages yourself.

### 2a. A console command run with a tenant header activates that tenant

**Before:** a console command run with `--header tenant:<name>` resolved header-driven connections (a
`#[MultiTenantConnection]` parameter, the DBAL connection factory) to that tenant, but never activated it:
`#[OnTenantActivation]` and `#[OnTenantDeactivation]` hooks did not run, so Laravel's tenant database switching and
anything else driven by those hooks stayed on the default connection. A Laravel projection reading `DB::connection()`
was initialized by `ecotone:projection:init --header tenant:b` in the wrong tenant's database.

**Now:** a `#[ConsoleCommand]` run with the tenant header runs between that tenant's activation and deactivation, as a
command, query, event or asynchronous message already did. Without the header nothing is activated.

**How to adapt:** nothing, unless an activation hook must not run for console commands.

## 3. Projections: v1 (Prooph-based) removed, `ProjectionV2` renamed to `Projection`

**Before:** Two projection systems coexisted: the Prooph-based v1 (`Ecotone\EventSourcing\Attribute\Projection`
with `fromStreams`/`fromCategories`/`fromAll`, `ProjectionManager`, `ProjectionRunningConfiguration`, the
`ecotone:es:*` console commands), and the new v2 (`Ecotone\Projecting\Attribute\ProjectionV2`). The
`FlowTestSupport` projection helpers — `initializeProjection()`, `resetProjection()`, `stopProjection()`,
`deleteProjection()`, `triggerProjection()` — served both: each drove a v2 projection when one had the name, and the v1
`ProjectionManager` otherwise.

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
- Code that injected the v1 `ProjectionManager` gateway (`Ecotone\EventSourcing\ProjectionManager`) has no injectable
  replacement. The 2.0 projection manager and its registry are internal: they carry the projection's positions and
  gap list, which are the event store's bookkeeping, not an application concern. Use the sanctioned paths instead:
  - **reading a projection's state** — declare a gateway method with `#[ProjectionStateGateway]`
    (`Ecotone\Api\Projecting\ProjectionStateGateway`). It returns the state the projection's handlers keep through
    `#[ProjectionState]`, and nothing about positions:
    ```php
    interface OrderListStateGateway
    {
        #[ProjectionStateGateway('order_list')]
        public function fetchState(): OrderListState;
    }
    ```
    A partitioned projection takes the partition key as the method's argument.
    `#[ProjectionStateGateway]`'s second parameter was spelled `projectioManagerReference` in 1.x, and its getter
    `getProjectioManagerReference()`. Both are corrected, to `projectionManagerReference` and
    `getProjectionManagerReference()`, without an alias, so a 1.x attribute that passes the old spelling by name
    fails with PHP's `Unknown named parameter $projectioManagerReference`. The 1.x value named the v1
    `ProjectionManager`, which is gone; drop the argument.
  - **lifecycle** — the console commands: `ecotone:projection:init <name>` (or `--all`), `ecotone:projection:backfill
    <name>`, `ecotone:projection:rebuild <name>` and `ecotone:projection:delete <name>`, in place of v1's `init`,
    `reset`/`rebuild` and `delete` operations.
  - **running** — there is nothing to call. A projection runs whenever an event it handles is published:
    synchronously in the publishing process, or through its channel's consumer (`ecotone:run <channel>`) when the
    projection is `#[Asynchronous]`, or through `ecotone:run <endpointId>` when it is `#[Polling]`. To catch a
    projection up without a new event, backfill it. In a flow test, `FlowTestSupport::triggerProjection()` runs it.
- Replace `ProjectionRunningConfiguration` / `ProjectionSetupConfiguration` / `ProjectionLifeCycleConfiguration`
  with `#[Polling(endpointId:)]`, `#[Streaming]`, `#[Partitioned]`, `#[Asynchronous]` on the projection class.
  `#[ProjectionInitialization]`/`#[ProjectionReset]`/`#[ProjectionDelete]` keep their names and move to
  `Ecotone\Api`.
- **Which of these need an Enterprise licence.** `#[Polling]`, `#[Streaming]`, `#[Partitioned]`,
  `#[ProjectionDeployment]`, `#[ProjectionRebuild]` and an asynchronous `#[ProjectionBackfill]` are Enterprise, and a
  projection using any of them is refused at bootstrap without a licence (`LicensingException`). A global projection,
  synchronous or `#[Asynchronous]`, is open core, and so are all four `ecotone:projection:init|backfill|rebuild|delete`
  commands — `ecotone:projection:rebuild` runs without a licence; `#[ProjectionRebuild]` only configures how a rebuild
  is batched and which channel runs it.
- Console: the `ecotone:es:*` commands are removed with no direct replacement for `reset-projection`,
  `trigger-projection` or `stop-projection`. The nearest v2 surface is `ecotone:projection:init|backfill|rebuild|delete`:
  `backfill` replays the stream into a projection from its current position, which is what `trigger-projection`
  did, and `rebuild` resets and replays it, which is what `reset-projection` did.
- `#[ProjectionState]` parameters must declare a default value (e.g. `array $state = []`, or
  `MyState $state = new MyState()`) — the framework passes `null` before a partition has any state, and a
  required, non-nullable parameter with no default cannot accept that.
- `EventStreamEmitter::emit()` and `linkTo()` are both open source. **Without an Enterprise licence, `emit()` writes
  to the default stream, `ecotone_event_stream`, whatever `#[Stream]` the projection declares.** `emit()` finds the
  projection's `#[Stream]` by the projection's name, and a projection handler receives its name (the
  `projection.name` header, `#[ProjectionName]`) only under a licence, so the open-core `emit()` has no name to look
  the stream up by. Emitting into a projection's own `#[Stream]` therefore needs the licence; without one, name the
  stream explicitly with `linkTo('my_stream', $events)`, which reaches any declared stream in open core.
- Tests: `FlowTestSupport::triggerProjection()`/`resetProjection()`/`initializeProjection()`/`deleteProjection()`
  now drive the projection directly and synchronously — there is no more queued/asynchronous delay
  before they take effect, so a test that relied on "nothing happens until the next `->run()`" needs to drop
  that intermediate `->run()` call. `initializeProjection()` drops its unused `$metadata` parameter.
  `stopProjection()` is removed with no replacement: stop a `#[Polling]` projection in a test by not calling
  `->run($endpointId)` again; a purely synchronous or async-channel projection has no "stop" concept to begin
  with. `deleteProjection()` no longer cascades into deleting a projection's `EventStreamEmitter`-emitted events,
  and `initializeProjection()` after a delete only recreates empty state — it does not replay history (use
  `resetProjection()` for that).
- **`FlowTestSupport::resetProjection()` replays, also for a projection that was `#[ProjectionV2]` in 1.x.**
  **Before:** for a v2 projection, `resetProjection()` deleted the projection and initialised it again, and stopped
  there: the projection was left empty until the next event or `triggerProjection()`. **Now:** it deletes, initialises
  and replays every event the projection reads, so the projection's handlers run again for each of them before the call
  returns. **How to adapt:** a test that reset a 1.x `#[ProjectionV2]` and asserted the read model was empty asserts
  the rebuilt read model instead; to see an empty projection, call `deleteProjection()` and `initializeProjection()`.
  A test that reset and then called `triggerProjection()` to rebuild can drop that second call.
- `EventSourcingConfiguration`'s projection-table-name and projection-manager-reference configuration are
  removed; only event-store-meaningful configuration remains.

### Projections partitioned by a tag's value: `#[FromTaggedStream]` (Enterprise)

**Before:** `#[Partitioned]` partitioned a projection only by aggregate: a projection over events a
`#[DecisionModel]` handler appends — events that belong to no aggregate — was refused with `Aggregate type must be
provided for projection X as partition header name is provided`, so it could only be global.

**Now:** `#[FromTaggedStream('concert')]` (`Ecotone\Api\Projecting\FromTaggedStream`) with `#[Partitioned]` keeps one
partition per value of the `concert` tag. A partition reads every event carrying that value, from whichever stream
appended it, in the tag's sequence order, and resumes from the last tag sequence it projected. A published event
reaches the partition named by its own `#[EventTag('concert')]` value; an array-valued tag reaches each of its values
once. `#[PartitionTagValue]` (`Ecotone\Api\Projecting\PartitionTagValue`) gives a handler, and the
`#[ProjectionReset]` a rebuild calls per partition, the value it runs for. Rebuild, backfill, `#[ProjectionState]` per
partition, `#[ProjectionStateGateway]` (its argument is the tag value) and Pulse's projection operations work as for
aggregate partitions, on the in-memory store and on DBAL (PostgreSQL, MySQL, MariaDB, SQLite), where the partitions
are the tag's distinct values in the tag index.

```php
#[Projection('concert_seats')]
#[FromTaggedStream('concert')]
#[Partitioned]
final class ConcertSeats
{
    #[EventHandler]
    public function whenReserved(SeatReserved $event, #[ProjectionState] array $state = []): array
    {
        $state['reserved'][] = $event->seatId;

        return $state;
    }

    #[ProjectionReset]
    public function reset(#[PartitionTagValue] string $concertId): void
    {
        // forget only this concert's rows: a rebuild resets and replays one concert at a time
    }
}
```

Refused at bootstrap, each naming the way out: `#[FromTaggedStream]` without `#[Partitioned]` (use `#[FromStream]`
for a global projection), with `#[Partitioned('header')]`, twice on one projection or beside `#[FromStream]`/
`#[FromAggregateStream]`, over a tag no event declares with `#[EventTag]`, over a filter-only tag
(`DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags()` — its sequence is always 0, so it cannot position a
partition), with `#[Streaming]`, `#[Polling]` or `#[ProjectionDeployment(manualKickOff: true)]`, and without an
Enterprise licence (`LicensingException`).

The partitions read through `EventStore::loadByCriteria()`, which gained `EventCriteria::limit(int $count)`: a limited
criterion loads its first events in tag-sequence order and `afterTagSequence()` is its cursor. Events appended
together share their tag's sequence, so a page never splits them — it completes the last sequence it reaches and may
hold more than `$count` events. `limit()` is refused on an `or()` combination.

**How to adapt:** nothing changes for existing projections. A projection over decision-model events that worked around
the refusal with a global `#[FromStream]` and a per-concert read model can become `#[FromTaggedStream('concert')]`
`#[Partitioned]` and drop the bookkeeping.

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
  it no longer invents a `projection_<name>` stream. Reaching the class's own `#[Stream]` takes an Enterprise
  licence; without one `emit()` writes to `ecotone_event_stream` (§3). `linkTo($streamName, ...)` still takes an
  explicit target, but the stream must be declared by a `#[Stream]` attribute somewhere — an unknown name is a
  configuration error instead of a table created behind your back. `emit()` is for projections only: called from
  any other handler it throws `ConfigurationException` naming `linkTo()` as the way to write events from there.
- **Tables are declared, not discovered.** Every stream table — including one declared with a non-default
  `connectionReferenceName` — is registered with `ecotone:migration:database:setup` under the `event_stream` feature,
  grouped by the connection it lives on; see §8 for the `--connection` option and how the missing-table error names the
  right connection and command. Nothing creates a missing stream table on first write any more, on any engine (§8);
  an `EcotoneLite` flow test's bootstrap sets the tables up on whichever connection each stream is declared for.
- `EventStreamingChannelAdapter::create(fromStream: ...)` takes a stream name, not an aggregate class; pass
  `aggregateType:` to filter.
- **`EventStore` gains two methods and a parameter.**
  **Before:** `EventStore::appendTo(string $streamName, array $streamEvents): void` took no condition, and `load()`
  read a stream: 1.x's `EventStore` had no method that loads one aggregate's events or loads and appends by tag. (Pre-release 2.0
  builds had both, behind two separate interfaces — `Ecotone\EventSourcing\EventStore\AggregateEventStore` and, Enterprise
  only, `Ecotone\Api\EventSourcing\TaggedEventStore`; 1.x had neither.) **Now:** both are methods of `EventStore`
  itself:

  ```php
  interface EventStore
  {
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
  `appendTo()`'s signature with the new optional parameter. Code written against a pre-release 2.0 build replaces
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
- **`EventSourcingConfiguration::withEventStreamTableName()` is renamed `withDefaultEventStreamTableName()`, and
  names a different table.** **Before:** it named Prooph's stream catalogue, default `event_streams` — the registry of
  which streams exist, not where any event was stored. **Now:** there is no catalogue, and
  `withDefaultEventStreamTableName()` (with `getDefaultEventStreamTableName()`, default
  `EventSourcingConfiguration::DEFAULT_EVENT_STREAM_TABLE_NAME`, `ecotone_event_stream`) names the table the default
  stream is stored in: every aggregate with no `#[Stream]`, `FlowTestSupport::withEvents()`, projections of the default
  stream and `ecotone:migration:database:setup` all use it. The old name is not kept as an alias, because a 1.x call
  that renamed the catalogue would otherwise move every aggregate's events into the catalogue's table. **How to
  adapt:** delete a 1.x `withEventStreamTableName()` call — the catalogue it configured is no longer read, and can be
  dropped by hand. Call `withDefaultEventStreamTableName()` only to store the default stream's events in a table other
  than `ecotone_event_stream`.
- **`EventStore::create()` is removed.** **Before:** `create(string $streamName, array $streamEvents = [], array $streamMetadata = [])`
  opened a stream, created its table if it was missing, and appended any events given. **Now:** the method is gone from
  the interface and from every implementation. Stream tables are created by `ecotone:migration:database:setup`
  (or the flow-test bootstrap, §8), not on the message path, so there was nothing left for
  `create()` to do that `appendTo()` does not: `appendTo()` opens the stream it writes to. The `streamMetadata`
  argument went with it — nothing read stream-level metadata back. **How to adapt:** delete a bare
  `$eventStore->create($streamName)` outright, and replace `create($streamName, $events)` with
  `appendTo($streamName, $events)`. If you relied on `create()` to make the table, run
  `ecotone:migration:database:setup --initialize` (or the equivalent for your integration, §8). An *empty* stream
  comes from setup alone: `hasStream()` answers whether the stream's table exists, so a declared stream the setup
  created exists, empty, before its first event, and a stream nothing declares never exists (§8).
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
  `MetadataMatcher`, `FieldType` and `Operator` are unchanged and still public, now under `Ecotone\Api\EventSourcing`
  (§13d) — `EventStore::load()`'s signature did not change. `EventSourcingRepository::save()` builds the aggregate's `AppendCondition` from
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

**What an append does to each counter.** An append changes a tag's counter only when other readers' boundaries
could see the difference — when one of its events carries that tag — and checks everything else it was given:

- a tag in the append condition that an appended event carries is **bumped guarded**: `UPDATE ... SET version =
  version + 1 WHERE version = :captured`, failing the append if the tag moved since it was read;
- an entry of the append condition that no appended event carries — an aggregate fetched read-only with `#[Fetch]`,
  a decision model's or boundary's tag the decision's events do not carry — is **checked, never bumped**: the append
  fails if it moved. The check is a guarded no-op write, `UPDATE ... SET version = version WHERE version =
  :captured`, and zero rows affected fails the append. MySQL and MariaDB count changed rows rather than matched ones
  unless the client sets `MYSQL_ATTR_FOUND_ROWS`, which belongs to your connection, so there the check is a guarded
  `version + 1` followed by a guarded `version - 1` in the same transaction: the committed version never moves, and
  no other connection sees the intermediate value. No pessimistic lock statement is used. A save of a fetched aggregate that committed first fails the decision; one that
  holds the row makes the decision wait and then fail; one that arrives later waits for the decision to commit and
  then succeeds. Two decisions checking the same counter wait for each other's commit and both succeed;
- a tag an appended event carries that is **not** in the append condition is **incremented** unconditionally
  (`version = version + 1`, no captured version): two appends with disjoint boundaries whose events share a tag
  value both commit, and a decision scoped by that tag that read before them still conflicts.

The counter of an aggregate an append saves, and of an instance a `#[DecisionModel(aggregate:)]` decides over, is
bumped guarded like a captured tag. The third rule applies only to an append whose condition carries tag entries — a
decision-model handler's append, or `appendTo()` with a condition from `loadByCriteria()`. **The exception: an append
without a tag condition — an `#[EventSourcingAggregate]` save, or `appendTo()` with no condition — keeps guarding the
tags its events carry,** as below.

**Aggregate saves are guarded on their tags.** With DCB enabled, an append without a tag condition — an
`#[EventSourcingAggregate]` save with no decision model injected, or `appendTo()` with no condition — captures each
tag its events carry with a consistent `SELECT` inside the transaction and bumps it with the guarded `UPDATE ... WHERE
version = :captured`. On `REPEATABLE READ` (InnoDB) the captured version is the one the transaction's snapshot saw when the
aggregate was loaded, so a competing commit made after the load fails the save with
`DecisionModelConcurrencyException` (a retry then sees it); on `READ COMMITTED` (the PostgreSQL default) the capture
is current. An explicit `AppendCondition`/decision-model version always wins over the captured one for the same tag.
Outside the third rule above, a counter is never bumped blindly, except by `backfill-tags`.

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
  used for this counter; on a document-store aggregate it is a separate check of its own (see "`DocumentStore` writes
  name the version they replace" in §14). The repository must write on the event store's connection so the counter
  and the aggregate commit together: a Dbal document-store or Doctrine ORM repository on another connection is a
  bootstrap `ConfigurationException`; for a repository Ecotone cannot inspect (your own, Eloquent, Tempest) this is
  your responsibility.
- **Opting a state-stored aggregate out of the new lock.** If callers of a particular state-stored aggregate rely on
  last write wins, name it and it keeps no counter at all — nothing captured at `findBy()`, nothing bumped at
  `save()`, exactly the 1.x behaviour:

  ```php
  return DynamicConsistencyBoundaryConfiguration::createWithDefaults()
      ->withoutCounterTagFor([ImportedContact::class, LegacyBasket::class]);
  ```

  It still declares `#[AggregateType]`, because that is the name its counter tag would carry and the exclusion can be
  lifted later. Fetching an excluded aggregate into a decision-model handler is a bootstrap `ConfigurationException`:
  the decision would rest on state nothing guards. A `#[DecisionBoundary]` whose criteria name only excluded
  aggregates is refused the same way, when it is evaluated rather than at bootstrap, because the criteria are built
  at runtime; mixing in a counted tag or an aggregate outside the opt-out is allowed and guards on those.
  Early 2.0 builds called this `withoutOptimisticLockFor()`; the name promised more than it did, so it is now
  `withoutCounterTagFor()` and the old name is gone — rename the call, the arguments are the same. The bootstrap
  refusals below name `withoutCounterTagFor()` too.
  **`withoutCounterTagFor()` switches off the counter tag only.** A document-store aggregate that also declares
  `#[Version]` is still version-checked by its repository: when both are present, `#[Version]` wins and a stale save
  fails with a `ConcurrencyException`. Remove `#[Version]` from the aggregate to get last write wins.
  Naming an `#[EventSourcingAggregate]` is a bootstrap error too —
  its stream's own version check is its lock and cannot be switched off — and so is naming a saga or a class that is
  no aggregate at all.
- **Transactions are mandatory for every aggregate save**, not only for tagged ones — see "Transactions are
  required" below. `#[WithoutDatabaseTransaction]` on an aggregate's command or event handler is a bootstrap
  `ConfigurationException`.
- **`#[Fetch]` in a decision-model handler.** A handler that injects a decision model or declares a
  `#[DecisionBoundary]` captures the counter of every aggregate it fetches with `#[Fetch]`, before invocation and in
  the same read as its models, and appends its events only if none of those aggregates was saved meanwhile. The
  append **checks** a fetched aggregate's counter and never bumps it ("What an append does to each counter" above).
  So decisions that only fetch the same aggregate (twelve `HoldSeat` decisions on
  different seats of one `Concert`) never fail each other — each waits for the one before it to commit, then
  commits — while a save of the fetched
  aggregate still conflicts: before the decision's append it fails the decision ("Concert c-1 changed since it was
  loaded ... another transaction saved it"), after it the save waits for the decision to commit. Early 2.0 builds
  bumped every fetched counter, so such decisions serialized on the aggregate and failed with a conflict blaming a
  save that never happened. A fetched aggregate that does not exist yet is captured at version 0, so deciding on its
  absence is safe and a concurrent creation conflicts; a `#[Fetch]` expression that resolves to no identifier
  contributes nothing. A `#[Fetch]` handler that is not a
  decision-model handler behaves exactly as before. A boundary made only of an aggregate is written with
  `EventCriteria::aggregate(Wallet::class, $command->walletId)` — a leaf that captures the counter and matches no
  events. Fetching a saga into a decision-model handler is a bootstrap `ConfigurationException`.
- **A decision model can be backed by an event-sourced aggregate.** `#[DecisionModel(aggregate: Wallet::class)]`
  scopes the model by one instance of `Wallet`: its events, of the types the model handles, are read from `Wallet`'s
  own stream by the stream's own `(aggregate_type, aggregate_id, no)` index, in `aggregate_version` order, with the
  event-name filter pushed into SQL. The boundary is the aggregate's counter tag, which already exists and is already
  bumped by every save, captured in the same read as the handler's other models and guarded on the append — and,
  unlike a `#[Fetch]`ed aggregate's counter, bumped by the decision's append too, so two decisions over one instance
  conflict just as two saves of it do. The
  aggregate's events need **no `#[EventTag]`**, and nothing is indexed, backfilled, migrated or configured:

  ```php
  #[DecisionModel(aggregate: Wallet::class)]
  final class WalletBalance
  {
      private int $balance = 0;

      #[Apply] public function credited(WalletCredited $e): void { $this->balance += $e->amount; }
      #[Apply] public function debited(WalletDebited $e): void   { $this->balance -= $e->amount; }

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
- **Conflicts name the aggregate and what moved it**: "Wallet w-1 changed since it was loaded (...): another
  transaction saved it or appended a #[DecisionModel(aggregate:)] decision over it, ...", still a
  `DecisionModelConcurrencyException`. A tag conflict says "another transaction appended an event carrying it".
- **A tag conflict names what was being decided.** The batch loader knows which decision model, or which
  `#[DecisionBoundary]` method, each captured tag belongs to, so the message says so:
  `Concurrent append conflict on tag username:ada (expected version 0, moved since it was read) while deciding
  App\Registration\UsernameAvailability. The tag moved after it was read: ... Otherwise retry the command: ...`. Several models sharing the
  conflicting tag are listed comma-separated; a handler with only a boundary is named as
  `while deciding App\Registration\Usernames::boundary`. An aggregate counter conflict keeps its own wording
  ("Wallet w-1 changed since it was loaded at change 3") and names no model. Neither names the version it moved to: a
  guarded write that misses does not report the row it found, and on InnoDB `REPEATABLE READ` a plain read returns
  the transaction's snapshot, so the number would be wrong on MySQL/MariaDB — every engine and the in-memory store
  print the same message. The exception also exposes the conflict as data —
  `conflictingTagName()`, `conflictingTagValue()`, `expectedVersion()`, `decidedBy()` — so you
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
should run again*. The command bus retries it by default when `DbalConfiguration` runs the command bus in a database
transaction (its default, see [§9](#9-changed-defaults)); without one, configure retry explicitly:

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

Without retry, a conflict surfaces to the caller as a technical exception naming the tag (or the aggregate)
and the version it was read at — not a business answer. Asynchronous endpoints in a database transaction retry 3 times by default too. Retry never
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
around the call (`$connection->transactional(fn () => $eventStore->appendTo(...))`). `EcotoneLite` flow tests given a
DBAL connection run with transactions on, as an application does, unless they pass their own `DbalConfiguration`. Untagged appends stay a single `INSERT` and need
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
  model, though, folds only the exact classes its `#[Apply]`s name — a handler for the parent does not
  receive the subclass. Tags are known from Ecotone's class scan: an event class outside the scanned namespaces is
  appended **without** tags (not indexed, not guarded) even if it declares `#[EventTag]` — keep tagged events in a
  scanned namespace. A decision model handling such an event fails at bootstrap, naming the scan as the cause.
- Declare a decision model with `#[DecisionModel]` and fold it with `#[Apply]`, exactly like an
  aggregate, with a public no-argument constructor:

  ```php
  #[DecisionModel]                        // tag names default to the intersection of handled events' own tags
  final class CourseCapacity
  {
      private int $capacity = 0;
      private int $seatsTaken = 0;

      #[Apply]
      public function defined(CourseDefined $event): void { $this->capacity = $event->capacity; }

      #[Apply]
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
  class-level `#[Stream]`. A method-level `#[Stream]` only chooses where that handler's events are appended; it does
  not declare the stream. `ecotone:migration:database:setup` creates tables for the default stream and for class-level
  `#[Stream]` attributes only, and the database event store refuses an append to a stream nothing declares — so also
  declare the same `#[Stream('<name>')]` on a class (an aggregate, or any class the application scans).
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
  is a no-op on every store: the condition is not checked, even when stale. A tag of the condition that no appended
  event carries is checked, not bumped, and a carried tag outside the condition is incremented — see "What an append
  does to each counter" above. Several criteria
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
`ecotone.dcb.conflict.expected_version` and `ecotone.dcb.conflict.model`, so
conflicts can be counted without any tracing set-up.

With `ecotone/open-telemetry` installed and the tracing package enabled, each boundary handler also produces two spans:

| Span | When | Attributes |
|---|---|---|
| `Decision Models: <Class::method>` | around the pre-invocation decision load | `ecotone.dcb.models` (the model classes folded), `ecotone.dcb.tags` (the captured `name:value` tags), `ecotone.dcb.captured_versions` (`name:value=version`), `ecotone.dcb.aggregates` (the `aggregate_<Type>:<id>` counters among them), `ecotone.dcb.events_folded` (how many events were folded) |
| `Conditional Append: <stream>` | around an append carrying a tag condition — a decision-model handler's result and an event-sourced aggregate save alike | `ecotone.dcb.tags`, `ecotone.dcb.events_appended` |

A conflict records the exception on the append span, sets its status to error, and adds a span event named
`dcb.conflict` whose attributes are the three `ecotone.dcb.conflict.*` fields above. List-valued attributes are
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
is traced — via the `#[Apply]`s of the aggregates that record it — to that aggregate's `#[Stream]`
connection; if it differs from the connection the injecting handler's own write stream lives on, bootstrap raises a
`ConfigurationException` naming the model, the event, and both connections, instead of silently loading a model that
can never see events committed on the other connection (cross-database consistency is a saga's job, not a
consistency boundary's). Events recorded only by a service handler — not an aggregate — cannot be traced this way
and are not checked; keep such a handler's stream and its injected models' aggregates on the same connection by
convention.

**A missing stream table stops a decision instead of answering it.** An aggregate-backed `#[DecisionModel]` reads
its events through the same `EventStore::loadAggregateEvents()` an aggregate `#[CommandHandler]` uses, and that read
raises the `ConfigurationException` §8 describes when the stream table does not exist, naming the `event_stream`
feature, the table and both setup commands — so the handler is refused instead of deciding on zero events. `ecotone:event-store:backfill-tags` reports the same error rather than a
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
  folded with. Deploy a model with one more `#[Apply]` and its old snapshots stop matching: the next
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
Ecotone-owned `<Package>ConnectionReference` in that package's `Api/` namespace — `DbalConnectionReference`,
`AmqpConnectionReference`, `SqsConnectionReference` and `RedisConnectionReference`, all four new in 2.0.
The reference name a user ever needs to type is one of these Ecotone classes; every default parameter across the
framework now points at `<Package>ConnectionReference::DEFAULT` instead of an Enqueue class name. Internally, the
factories still implement `Interop\Queue\ConnectionFactory` (`Interop\Amqp\AmqpConnectionFactory` for AMQP) — that
seam is unchanged and is what lets the underlying transport be replaced later without a user-facing change.

| Package | Before (Enqueue class you typed) | Now (Ecotone class you type) | Default reference name |
|---|---|---|---|
| DBAL | `Enqueue\Dbal\DbalConnectionFactory` | `Ecotone\Api\Dbal\DbalConnectionFactory` | `Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference::DEFAULT` |
| AMQP (default, `ext-amqp`) | `Enqueue\AmqpExt\AmqpConnectionFactory` | `Ecotone\Api\Amqp\AmqpExtConnectionFactory` | `Ecotone\Api\Amqp\AmqpConnectionReference::DEFAULT` |
| AMQP (RabbitMQ Streams, `php-amqplib`) | `Enqueue\AmqpLib\AmqpConnectionFactory` | `Ecotone\Api\Amqp\AmqpLibConnectionFactory` | `Ecotone\Api\Amqp\AmqpConnectionReference::DEFAULT_STREAM` |
| SQS | `Enqueue\Sqs\SqsConnectionFactory` | `Ecotone\Api\Sqs\SqsConnectionFactory` | `Ecotone\Api\Sqs\SqsConnectionReference::DEFAULT` |
| Redis | `Enqueue\Redis\RedisConnectionFactory` | `Ecotone\Api\Redis\RedisConnectionFactory` | `Ecotone\Api\Redis\RedisConnectionReference::DEFAULT` |

`DbalConnectionReference::defaultConnection()`, `AmqpConnectionReference::defaultConnection()` /
`defaultStreamConnection()`, `SqsConnectionReference::defaultConnection()` and `RedisConnectionReference::defaultConnection()`
each return the reference object, mirroring `SymfonyConnectionReference`, `LaravelConnectionReference` and
`TempestConnectionReference`. The four transport references are new in 2.0; the three framework ones existed in 1.x.
There they resolved to `Enqueue\Dbal\DbalConnectionFactory::class`; they now resolve to
`DbalConnectionReference::DEFAULT`, whose value is `'Ecotone\Api\Dbal\DbalConnectionFactory'`. Code that builds
them with `defaultConnection()` or the constructor follows on its own; a lookup of the old string id does not (see
below).

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
- For AMQP, SQS and Redis, code that reaches into a transport's `Context` / `Consumer` / `Producer` / `Destination` /
  `Message` classes (e.g. `Enqueue\AmqpExt\AmqpContext`, `Enqueue\Sqs\SqsContext`, `Enqueue\Redis\RedisContext`) is
  unaffected — only the `ConnectionFactory` class moved into Ecotone's namespace.
- **DBAL is different: every vendored `Enqueue\Dbal` class moved, not only the factory.** 1.x `ecotone/dbal` shipped
  its own copy of `enqueue/dbal` under the `Enqueue\Dbal` namespace (and declared a conflict with the real package).
  2.0 moves all of it to `Ecotone\Dbal\Connection`, under the same class names, and the `Enqueue\Dbal` namespace no
  longer exists:

  | 1.x | 2.0 |
  |---|---|
  | `Enqueue\Dbal\DbalConnectionFactory` | `Ecotone\Api\Dbal\DbalConnectionFactory` |
  | `Enqueue\Dbal\ManagerRegistryConnectionFactory` | `Ecotone\Api\Dbal\ManagerRegistryConnectionFactory` |
  | `Enqueue\Dbal\DbalContext` | `Ecotone\Dbal\Connection\DbalContext` |
  | `Enqueue\Dbal\DbalConsumer`, `DbalProducer`, `DbalSubscriptionConsumer` | `Ecotone\Dbal\Connection\DbalConsumer`, `DbalProducer`, `DbalSubscriptionConsumer` |
  | `Enqueue\Dbal\DbalDestination`, `DbalMessage` | `Ecotone\Dbal\Connection\DbalDestination`, `DbalMessage` |
  | `Enqueue\Dbal\DbalConsumerHelperTrait`, `DbalType`, `JSON` | `Ecotone\Dbal\Connection\DbalConsumerHelperTrait`, `DbalType`, `JSON` |

  Every row is in `upgrade/namespace-map-2.0.csv`. Apart from the factory, these are transport internals an application
  meets only when it writes transport code: an `Interop\Queue\Context` type-hint on a DBAL connection becomes
  `Ecotone\Dbal\Connection\DbalContext`, in place of 1.x's `Enqueue\Dbal\DbalContext`.
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
routing wildcards are unchanged: `*` matches any run of characters, dots included, so `userService.*` matches
`userService.billing.DetailsWereChanged` and `*` alone matches every event.

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
| `StandardRepository` interface | `Ecotone\Api\Modelling\StateStoredRepository` (same methods; §13j) |
| `EcotoneLite::bootstrapForTesting()`, `EcotoneLiteConfiguration`, `ecotone/lite-application` package | `EcotoneLite::bootstrapFlowTesting()` / `EcotoneLite::bootstrap()` |
| `MethodInvocation::getInterfaceToCall()`, `MethodInvocation::replaceArgument()` | `getObjectToInvokeOn()` / `getMethodName()`; pass changed values by returning a new message or header from `#[Before]` / `#[Presend]` |
| `Ecotone\Messaging\Gateway\Converter\Serializer` | `Ecotone\Api\Gateway\SerializerGateway` |
| `Type::STRING`, `Type::ARRAY`, `Type::OBJECT` | `Type::string()`, `Type::array()`, `Type::object()` |
| `Clock::get()` static access | inject `EcotoneClockInterface` |
| `FlowTestSupport::releaseAwaitingMessagesAndRunConsumer()` | `run()` |
| `BaseEventSourcingConfiguration::withSnapshots()` | `withSnapshotsFor()` |
| `ProjectingManager::backfill()` | `ecotone:projection:backfill <name>`; the manager is internal in 2.0 (§3) |
| `AmqpBackedMessageChannelBuilder::withPublisherAcknowledgments()` | `withPublisherConfirms()` |
| `DbalConfiguration::withCleanObjectManagerOnAsynchronousEndpoints()` | `withClearAndFlushObjectManagerOnAsynchronousEndpoints()` |
| `ProjectionRunningConfiguration::with*()` / `get*()` typed helpers | removed with projection v1 (§3) |
| `Module::canHandle()` | removed from the `Module` interface; modules receive every extension object and filter by `instanceof` inside `prepare()` |
| `TestConfiguration::withFailOnCommandHandlerNotFound()`, `withFailOnQueryHandlerNotFound()` | `withFailOnNotFoundHandler()`, one switch for both buses, `true` by default: a flow test sending a command or query nobody handles fails unless it opts out |
| `AmqpStreamChannelBuilder::create($channel, 'next')` | `first`, `last` or an absolute offset. A `next` consumer receives only what RabbitMQ sees published after it attaches, so messages published before the first poll or between two runs were lost; bootstrap now refuses it with a message naming the positions that replay |
| `CronExpression::factory()`, `CronExpression::YEAR` | `new CronExpression()`; the year field was never parsed |
| `ServiceActivatorBuilder` (internal) | `MessageProcessorActivatorBuilder::createForMethod($objectToInvokeOn, $method)`; invoker options such as changed headers or a custom result converter go on a `MethodInvokerBuilder` passed to `createForMethodInvoker()` |
| `#[ServiceActivator]` | `#[InternalHandler]` (1.x already had both names for one attribute; §7a) |
| `#[Transactional]` and the rest of `Ecotone\Messaging\Transaction` | `DbalConfiguration` transactions and `#[WithoutDatabaseTransaction]` (§7b) |
| `Configuration::registerMessageConverter()`, with `Ecotone\Messaging\MessageConverter\MessageConverter`, `MessageConverterRegistry` and `MessageConvertingException` | a module's `registerMessageConverter($referenceName)` made every gateway run that service's `toMessage()` on each call and `fromMessage()` on each reply. Nothing in Ecotone called it. Shape the request with `#[Payload]` / `#[Header]` gateway parameters and convert payloads and replies with `#[Converter]` or a `#[MediaTypeConverter]`. `HeaderMapper` and `DefaultHeaderMapper`, in the same namespace, are unchanged |


### 7a. `#[ServiceActivator]` is gone — `#[InternalHandler]` is the only name

**Before:** `#[InternalHandler]` and `#[ServiceActivator]` were two names for the same attribute, both in 1.x —
`InternalHandler extends ServiceActivator` and both worked identically.

**Now:** `Ecotone\Messaging\Attribute\ServiceActivator` (`Ecotone\Api\ServiceActivator` in early 2.0 builds) no longer exists. `#[InternalHandler]` is the only attribute.

**How to adapt:**
- Rename `#[ServiceActivator(...)]` to `#[InternalHandler(...)]` and update the `use` statement
  (`Ecotone\Messaging\Attribute\ServiceActivator` → `Ecotone\Api\Attribute\InternalHandler`).
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

### 7b. `#[Transactional]` and `Ecotone\Messaging\Transaction` are removed

**Before:** `Ecotone\Messaging\Transaction` held a `#[Transactional]` attribute taking a list of `TransactionFactory`
reference names, together with `Transaction`, `TransactionFactory`, `TransactionInterceptor`, `TransactionException`,
`NullTransaction` and `NullTransactionFactory`. Nothing in Ecotone registered `TransactionInterceptor`, so
`#[Transactional]` on a handler started no transaction, and the only `TransactionFactory` was the null one. Database
and broker transactions have always come from `DbalTransactionInterceptor` and `AmqpTransactionInterceptor`, which
never read `#[Transactional]`.

**Now:** the namespace is gone; no class replaces it, so `upgrade/namespace-map-2.0.csv` has no row for it.

- `#[Transactional([...])]` written on a handler and nothing else: the application still boots and handles messages
  exactly as before. PHP resolves an attribute class only when it is instantiated, and Ecotone never instantiates
  this one. Delete the attribute and its `use` statement; it did nothing.
- A pointcut naming it, for example `#[Around(pointcut: Transactional::class)]`, fails at bootstrap with
  `IncorrectPointcutException` (`'Ecotone\Messaging\Transaction\Transactional' is not a valid token`).
- An interceptor parameter typed with it fails at bootstrap with `InvalidArgumentException`
  (`Unknown type or class 'Ecotone\Messaging\Transaction\Transactional'`).
- A class implementing `Transaction` or `TransactionFactory`, or code calling `Transactional::createWith()` or
  `NullTransactionFactory`, fails with PHP's class-not-found error when it is loaded.

**How to adapt:**
- For database transactions, configure them on `DbalConfiguration` from a `#[ServiceContext]` method —
  `withTransactionOnCommandBus()`, `withTransactionOnAsynchronousEndpoints()` and `withTransactionOnConsoleCommands()`,
  all enabled by default — and opt a single handler out with `#[WithoutDatabaseTransaction]`
  (`Ecotone\Api\Attribute\WithoutDatabaseTransaction`). AMQP channel transactions are configured with the same three methods on
  `AmqpConfiguration`, where they are disabled by default.
- If you implemented `TransactionFactory` for some other resource, replace it with your own `#[Around]` interceptor
  that begins, commits and rolls back around `MethodInvocation::proceed()`, ordered with
  `Precedence::DATABASE_TRANSACTION_PRECEDENCE`, and pointed at an attribute your application owns.

If you implemented a custom `Module` / `AnnotationModule`, delete the `canHandle()` method and filter extension objects
with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)` inside `prepare()`. The base and marker
classes that concrete attributes extend (`EndpointAnnotation`, `IdentifiedAnnotation`, `ChannelAdapter`,
`MessageConsumer`, `StreamBasedSource`, …) are now `@internal`: application code never uses them, and custom modules
that type against them should expect them to change in minor versions.

### 7c. `#[EventSourcingHandler]` is renamed to `#[Apply]`

**Before:** an event-sourced aggregate or a decision model folded its events in methods marked
`#[EventSourcingHandler]` (`Ecotone\Modelling\Attribute\EventSourcingHandler`).

**Now:** the attribute is `#[Apply]` (`Ecotone\Api\Attribute\Apply`), and it is `final`. Nothing else changed: the
same methods are found, they are invoked in the same order with the same arguments, and the same mistakes are refused
at bootstrap. `Ecotone\Modelling\Attribute\EventSourcingHandler` no longer exists and has no alias.

```php
use Ecotone\Api\Attribute\Apply;

#[EventSourcingAggregate]
final class Wallet
{
    #[Apply]
    public function applyCredited(WalletCredited $event): void
    {
        $this->balance += $event->amount;
    }
}
```

The exception messages that named the "Event Sourcing Handler" now name the `#[Apply]` method, for example
`Using multiple parameters for #[Apply] method: Wallet::applyCredited is part of Enterprise features.` Update any test
that asserts one of them verbatim.

**How to adapt:**
- Replace `#[EventSourcingHandler]` with `#[Apply]` and the import
  `Ecotone\Modelling\Attribute\EventSourcingHandler` with `Ecotone\Api\Attribute\Apply` —
  `upgrade/namespace-map-2.0.csv` maps the old class straight to the new one.
- An attribute of your own that extended `EventSourcingHandler` cannot extend `Apply`, which is `final`. Drop the
  `extends`, keep your attribute for whatever it carries, and put `#[Apply]` beside it on each method:
  `#[Apply] #[AuditedChange('balance')] public function applyCredited(WalletCredited $event): void`.

## 8. Database tables are no longer created on the fly

**Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`,
`ecotone_error_messages`, `enqueue` and the rest during the first message. On MySQL and MariaDB that `CREATE TABLE`
statement commits the surrounding transaction implicitly, so Ecotone carried a workaround
(`ImplicitCommit::isImplicitCommitException()`) that string-matched the driver's error message and swallowed the
resulting commit failure. Deduplication additionally special-cased PostgreSQL, because only there could it insert its
row before running the handler without risking that insert being committed early by a table-creation statement later
in the same transaction.

**Now: Ecotone never creates a table while it handles a message, on any engine.** A table is created in exactly two
places:

- **`ecotone:migration:database:setup --initialize`** — or its programmatic twin
  (`DatabaseSetupManager` / `DatabaseSetupManagerRegistry`), or `--sql` fed to your own migration tool;
- **the test setup action, `$ecotone->setupDatabaseTables()`**, in an `EcotoneLite` flow test. The flow-test bootstrap
  (`bootstrapFlowTesting()`) calls it itself, for every connection the test
  registers, so a flow test does not create tables by hand.

Everywhere else — storing a dead letter, deduplicating a message, writing a document, appending events, reading the
tag index, a projection's state, sending to or receiving from a DBAL message channel (§8b) — a missing table raises a
`ConfigurationException` naming the feature, the table, and the exact command (or, without a console, the code) to
run for the integration your application is running under. The message is the same on PostgreSQL, MySQL, MariaDB and
SQLite: whether a table appeared mid-message no longer depends on the database underneath.

Reads are included. **Before:** `DeadLetterGateway::list()` and `count()` answered `[]` and `0` for a dead-letter
table that was never set up, and the DBAL `DocumentStore`'s reads and deletes (`findDocument()`, `getDocument()`,
`getDocumentVersion()`, `getAllDocuments()`, `countDocuments()`, `deleteDocument()`, `dropCollection()`) answered
`null`, `0`, `[]`, `DocumentNotFound` or nothing. **Now:** they raise the same `ConfigurationException`. **How to
adapt:** set the tables up; code that relied on an empty answer from a missing table was reading a configuration
mistake as data. Ecotone no longer asks the database whether a table exists before using it, so none of these
operations pays a schema query any more: the statement runs, and a missing table is reported from its failure.

`ImplicitCommit` is gone, `DbalTransactionInterceptor` raises a hard error again if a commit genuinely fails, and one
transaction wraps the whole message on every driver — including MySQL and MariaDB. Deduplication's concurrency
guarantee (a handler running twice for the same message collides on the primary key instead of running twice) works
on every driver, not just PostgreSQL. Deduplication cleanup runs on its own endpoint, outside your handler's
transaction, so it no longer holds row locks while your handler runs.

The removed configuration, one by one:

- **`DbalConfiguration::withAutomaticTableInitialization()` is removed**, with
  `isAutomaticTableInitializationEnabled()`. **Before:** the switch for creating tables on first use, on by default:
  with no call, the dead letter, deduplication, document store, DBAL channel and event store tables were each created
  by the first message that needed them, on every engine. `withAutomaticTableInitialization(false)` turned that off,
  leaving the tables to `ecotone:migration:database:setup --initialize`. **Now:** runtime creation is gone on every
  engine, so there is nothing left to switch — every application is in the position `false` put it in. **How to
  adapt:** delete the call, and run `ecotone:migration:database:setup --initialize` in your deploy (see below) — an
  application that relied on the default `true` has to add this step, or its first message fails on a missing table.
  In a flow test nothing replaces it: the bootstrap sets the tables up. A test that passed
  `false` to watch a missing table be refused passes
  `testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)` to
  `bootstrapFlowTesting()` instead, and calls `$ecotone->setupDatabaseTables()`
  when it wants the tables.
- **`AutoCreateLevel` and `DbalConfiguration::withAutoCreateLevel()` never shipped.** They existed only in pre-release
  2.0 builds, between `withAutomaticTableInitialization()` and its removal; 1.x never had them. **How to adapt:** only
  code written against a pre-release build has anything to do: delete `->withAutoCreateLevel(...)` and the
  `use Ecotone\Api\Dbal\AutoCreateLevel;` line.
- **`EventSourcingConfiguration::withInitializeEventStoreOnStart()` is removed**, with `isInitializedOnStart()`, and
  `LazyProophEventStore::INITIALIZE_ON_STARTUP`, its default, went with the Prooph store (§4). **Before:** on by default; `false` stopped the
  event store creating its tables on first append even while `withAutomaticTableInitialization()` was on — the two
  were AND-ed. It was not deprecated. **Now:** gone with what it modified. **How to adapt:** delete the call; stream
  tables come from the setup command like every other table.
- **`DbalConfiguration::withDocumentStore()` loses its `initializeDatabaseTable` parameter**, and
  `isInitializeDbalDocumentStore()` is removed. **Before:** `false` stopped the document store creating its table on
  first use even with `withAutomaticTableInitialization()` on. **Now:** it never creates it. **How to adapt:** drop the
  argument; it was the fourth positional one, so a positional call passing the arguments after it moves them one place
  left — or switch to named arguments.
- **`withDocumentStore()`'s `enableDocumentStoreStandardRepository` parameter is renamed
  `enableDocumentStoreStateStoredRepository`**, and `isEnableDocumentStoreStandardRepository()` is renamed
  `isEnableDocumentStoreStateStoredRepository()`, following `StandardRepository` → `StateStoredRepository` (§7). No
  alias is kept. **Before:** `withDocumentStore(enableDocumentStoreStandardRepository: true)`. **Now:** that call fails
  with PHP's `Unknown named parameter $enableDocumentStoreStandardRepository`. **How to adapt:**
  `withDocumentStore(enableDocumentStoreStateStoredRepository: true)`; the behaviour is unchanged.
- **A database event store without the DBAL module is refused at bootstrap.** **Before:** enabling
  `ModulePackageList::EVENT_SOURCING_PACKAGE` without `ModulePackageList::DBAL_PACKAGE` booted, and the first append
  failed on a missing event stream table whose instructions named `DatabaseSetupManager` and
  `ecotone:migration:database:setup` - both the DBAL module's, so neither existed. **Now:** the application refuses to
  start with a `ConfigurationException` that names the module to add. **How to adapt:** add
  `ModulePackageList::DBAL_PACKAGE` (`'dbal'` in `enabledModulePackageNames` — the Symfony bundle option, the
  `config/ecotone.php` key or the `EcotoneConfig` argument), or register `EventSourcingConfiguration::createInMemory()`
  to keep the events in memory.
- **New: `FlowTestSupport::setupDatabaseTables()`.** **Before:** 1.x had no such method: a flow test got its tables
  the way the application did, created on first use. (Pre-release 2.0 builds called it `initializeDatabase()`, and it
  caught every `InvalidArgumentException` and returned, so a test without the DBAL module, or a setup that failed
  half-way, carried on and failed later on a confusing missing-table error.) **Now:** it creates every
  table the test uses, on every connection that holds one, and refuses with what to do instead: with no module that
  keeps tables, it says to enable `ModulePackageList::DBAL_PACKAGE` or remove the call; with a used feature on a
  connection the test does not register, it names the feature and the connection reference to register, or the
  in-memory option for that feature. **How to adapt:** from 1.x, nothing — the bootstrap makes the call itself, so a
  test needs it only after opting out with `withDatabaseTablesSetUpOnBootstrap(false)`. Code written against a
  pre-release build renames `initializeDatabase()` to `setupDatabaseTables()`, and most such calls can go.

**How to adapt an application:**

- Add `ecotone:migration:database:setup --initialize` to your deploy pipeline (or repeat `--feature` for a
  subset: `--feature=deduplication --feature=dead_letter`; one value is one feature, so a comma-separated list is
  read as a single unknown feature name). It is the only way tables get created outside tests, on every engine — or use `--sql` to feed your own
  migration tool.
  — Symfony: `bin/console ecotone:migration:database:setup --initialize`
  — Laravel: `php artisan ecotone:migration:database:setup --initialize`
  — Tempest: `./tempest ecotone:migration:database:setup --initialize`
  — EcotoneLite / standalone: `$messagingSystem->getServiceFromContainer(DatabaseSetupManager::class)->initializeAll();`
- Add `--missing` to list only the tables that do not exist yet, instead of every used feature. Combine it with
  `--sql` (`ecotone:migration:database:setup --missing --sql`) to get `CREATE TABLE` statements for only what's
  missing — the shape you want when pasting into an existing migration, rather than dumping SQL for tables you
  already created.
- Run the setup again after deleting an event stream: `EventStore::delete()` drops the stream's table, and nothing
  recreates it on the next append any more. A stream no `#[Stream]` (and no aggregate) declares has no table the setup
  creates, so reaching it is a configuration mistake, refused with a `ConfigurationException` that names the stream,
  the `#[Stream('<name>')]` to add, the setup command and the streams that are declared: **at bootstrap** for a
  projection reading it through `#[FromStream('<name>')]`, and on `EventStore::appendTo()` for a name only known at
  runtime, once the append finds no table (a table your own migration created keeps working). Both apply to the database event store; `EventSourcingConfiguration::createInMemory()` has no tables, and
  still opens a stream on its first append. **How to adapt:** add `#[Stream('<name>')]` to the class that owns the
  stream's events — an event-sourced aggregate, the projection that emits into it, or any class Ecotone scans — and
  run the setup.
- In an `EcotoneLite` flow test, nothing: the bootstrap sets up the tables of every connection the test registers.
  A test that must see a table missing opts out with
  `TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)`; a Symfony, Laravel or Tempest
  kernel test, which does not go through the `EcotoneLite` bootstrap, runs the setup command in its own set-up.

Reading an event stream raises too. `EventStore::load()` and
`EventStore::loadAggregateEvents()` used to probe `information_schema` on every call and answer "no events" when the
stream table was absent, which turned a missing `ecotone:migration:database:setup` into an `AggregateNotFoundException`
for an aggregate whose events were never readable in the first place. **They raise the same `ConfigurationException`
as every other missing table**, and the probe they paid for on every aggregate load is gone. Projections reading a
stream table that does not exist raise it too, instead of reporting themselves up to date against a stream nobody
created. Event-sourced aggregate command handlers, `#[Fetch]`, `Repository::getFor()` and partitioned and global
projections all surface it. `EventStore::hasStream()` is unchanged: it answers whether the stream exists, so it still
returns `false` rather than raising.

`EventStore::hasStream()` is the only read that answers a missing table without raising. To list the tables that are
absent, run `ecotone:migration:database:setup --missing`.

Dropping a table is DDL too, and one runs when your code asks for it: `EventStore::delete()` drops the stream's table.
On MySQL and MariaDB, calling it while a database transaction is open — from a command handler, an asynchronous
handler or a `#[ConsoleCommand]`, each of which runs inside one by default, or from a `#[ProjectionReset]` handler,
which always runs inside the projection's own transaction — raises a `ConfigurationException` before anything is
dropped. Before, the `DROP TABLE` committed everything the message had written, and the message then failed at commit
with "There is no active transaction". Outside a transaction `delete()` works on every engine, and on PostgreSQL and
SQLite it also works inside one, because DDL is transactional there.

- Deduplication cleanup: run the endpoint with `ecotone:run ecotone.deduplication.cleanup` on a schedule (a
  Kubernetes CronJob, Supervisor timer, or your own scheduler), or keep using
  `ecotone:deduplication:remove-expired-messages` if you already trigger it from your own cron. Both call the same
  code; neither runs inside your handler's transaction any more.
- Remove any application code that relied on the implicit commit (for example, DDL issued from inside a handler on
  MySQL) — it is no longer swallowed, and a genuinely failing commit now throws.
- On MySQL/MariaDB, delete an event stream outside any database transaction: mark the handler or `#[ConsoleCommand]`
  that calls `EventStore::delete()` with `#[WithoutDatabaseTransaction]`, or turn the transaction off for that entry
  point with `DbalConfiguration::withTransactionOnCommandBus(false)`, `withTransactionOnAsynchronousEndpoints(false)`
  or `withTransactionOnConsoleCommands(false)`. A projection that deletes the stream it emits to does it in
  `#[ProjectionDelete]`, not `#[ProjectionReset]`, and is deleted before it is rebuilt — through
  `ecotone:projection:delete`, which like `ecotone:projection:init` and `ecotone:projection:backfill` runs outside the
  console-command transaction — and `ecotone:migration:database:setup --initialize` recreates the dropped stream table
  before the rebuild emits into it again.
- `#[ProjectionInitialization]` runs only when the projection is initialized, never while Ecotone handles a message.
  **Before:** the first event of a projection that nobody had initialized ran its `#[ProjectionInitialization]` inside
  that event's transaction — the command's, or the asynchronous endpoint's — so DDL in it committed implicitly on
  MySQL/MariaDB and the event failed with `There is no active transaction`; a partitioned projection ran it again for
  every new partition. **Now:** `ecotone:projection:init <name>` (or `--all`) runs it once, outside any transaction,
  and records the projection as initialized; running the command again leaves an initialized projection as it is, so
  it belongs in every deployment, after `ecotone:migration:database:setup`. A new partition of an initialized
  projection only gets its position recorded. An event reaching a projection that was never initialized is refused
  with a `ConfigurationException` naming the projection and the command. `#[ProjectionDeployment(manualKickOff: true)]`
  keeps its meaning for an initialized projection: it waits for `ecotone:projection:backfill` before projecting.
  **How to adapt:** add `ecotone:projection:init --all` to your deployment, or create what your projections project
  into in your own migrations and initialize them the same way. An EcotoneLite flow test initializes every projection
  on bootstrap together with the database tables, and `$ecotone->setupDatabaseTables()` does too —
  `setupDatabaseTables(initializeProjections: false)` sets up the tables only.
- **Before (early 2.0):** `ecotone:projection:init` and `ecotone:projection:delete` ran inside the console-command
  transaction, so a projection whose `#[ProjectionInitialization]` or `#[ProjectionDelete]` issues DDL failed on
  MySQL/MariaDB with `There is no active transaction`; and `ecotone:projection:init` left a global projection's state
  unrecorded, so its first event ran the initialization handler again, inside the command's transaction.
  **Now:** both commands run outside the console-command transaction, as `ecotone:projection:backfill` already did,
  and `ecotone:projection:init` records a global projection's state, so its first event does not initialize it again.
  **How to adapt:** drop `withTransactionOnConsoleCommands(false)` if you set it only for these commands.
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
(`#[Stream]`, `DbalConfiguration::withDeduplication()`, `withDeadLetter()`, `withDocumentStore()`, and each
`CustomDeadLetterGateway`'s connection) — and a
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
- **Multi-tenant connections are covered tenant by tenant.** When a connection Ecotone knows about is a
  multi-tenant one (`MultiTenantConfiguration`), every invocation runs against the database of each tenant in its
  `tenantToConnectionMapping`, plus its default connection, each physical connection once. `--initialize` creates the
  missing tables in every tenant database; `ecotone:migration:database:delete` drops them in every one; the status
  table reports a feature `Initialized` only when every tenant database has its table; and `--sql` prints each
  tenant database's statements under a `-- connection '<reference>'` line, since tenants may run different engines.
  `--connection=` takes the multi-tenant reference, not a tenant's own. The tenant list is the configured mapping,
  not header-driven discovery: a tenant added later is provisioned by running the command again. If one tenant
  database is unreachable, the command stops with that connection's error; the tables already created stay, and
  running it again finishes the rest, since it skips tables that exist. Early 2.0 refused here with `Lack of context
  about tenant in Message Headers`.
- **`DatabaseSetupManager` is `final`.** Nothing in Ecotone extended it; an application that did composes it instead.
- This generalizes beyond event streams: any table manager whose feature is configured on a non-default connection —
  today that is deduplication, dead letter and the document store, alongside event streams — is now covered the same
  way. `message_queue` (DBAL-backed channels/publishers) and `projection_state` are still tied to the default
  connection; they do not yet accept a per-feature `connectionReferenceName`.

### 8b. DBAL message channels no longer create the `enqueue` table

**Before:** a DBAL-backed message channel (`DbalBackedMessageChannelBuilder`) or a DBAL message publisher created the
`enqueue` table on its first send or receive, when it found it missing (on PostgreSQL and SQLite under the test
bootstraps' `AutoCreateLevel::CreateOnly`; in 1.x, everywhere). **Now:** it never does. A channel that finds the table
missing — on its first send, its first receive, or its declare-on-startup check — raises the missing-table
`ConfigurationException` naming the `message_queue` feature, the `enqueue` table and the command to run, on every
engine. This is the change most likely to break an upgrade: an application whose queue table used to appear by itself
now needs the setup command before its first message.

**How to adapt:** run `ecotone:migration:database:setup --initialize` (or `--feature=message_queue` for this table
alone, or `--sql` for your migration tool) in your deploy before the application sends or consumes its first DBAL
message — the same step §8 asks for every other table. `DbalBackedMessageChannelBuilder::withAutoDeclare()` keeps its
name and still checks for the table before the channel's first send or receive, but checking is all it does now. An `EcotoneLite` flow
test needs nothing: its bootstrap sets the table up.

## 9. Changed defaults

| Setting | 1.x default | 2.0 default | Where to override |
|---|---|---|---|
| JMS: serialize `null` properties | off | on | `JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)` |
| JMS: native enum support | off | on | `->withDefaultEnumSupport(false)` |
| JMS: `DateTime` / `DateTimeImmutable` format | `Y-m-d\TH:i:sP` (seconds) | `Y-m-d\TH:i:s.uP` (microseconds); payloads stored in the old format still deserialize | a `#[Converter]` for `DateTimeInterface`, as before |
| `SimpleMessageChannelBuilder::createQueueChannel()` delayable | `false` | `true` | `createQueueChannel('x', delayable: false)` |
| `ExecutionPollingMetadata::createWithTestingSetup()` messages handled | 1 | 100 | `createWithTestingSetup(handledMessageLimit: 1)` |
| `ExecutionPollingMetadata::withTestingSetup()` messages handled | 1 | 100 | `withTestingSetup(handledMessageLimit: 1)` |
| `PollingMetadata::withTestingSetup()` messages handled | 1 | 100 | `withTestingSetup(handledMessageLimit: 1)` |
| Instant retries on asynchronous endpoints | disabled | enabled (3 retries, so up to 4 handler invocations per delivery) when `DbalConfiguration` runs asynchronous endpoints in a database transaction (its default), except an endpoint in `withoutTransactionOnAsynchronousEndpoints()` or a handler with `#[WithoutDatabaseTransaction]`; an asynchronous projection, which manages its own transaction, always; anything else not | `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)`, or `(true)` to retry without a transaction |
| Instant retries on the command bus | disabled | enabled (3 retries, every exception) when `DbalConfiguration` runs the command bus in a database transaction (its default), except a command whose handler has `#[WithoutDatabaseTransaction]`; without one not | `InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false)`, or `(true)` to retry without a transaction |
| Module packages | all except explicitly skipped | Core + Asynchronous + what `withEnabledModulePackageNames()` lists; with no call, all installed packages load | `ServiceConfiguration::withEnabledModulePackageNames([...])` |

Test-suite impact of the new defaults:
- A `DateTimeImmutable` round-trips through a JSON-serialized channel, event store or document store with its microseconds, so
  a comparison such as `$updatedAt > $createdAt` holds on both sides of an asynchronous channel. A test asserting the serialized
  string sees `2021-01-01T12:00:00.000000+00:00` where it saw `2021-01-01T12:00:00+00:00`.
- A test that asserted "exactly one message handled per `run()`" now sees up to 100; pass
  `ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)`, or
  `->withTestingSetup(handledMessageLimit: 1)` on an `ExecutionPollingMetadata` it already builds (or
  `--handledMessageLimit=1` on `ecotone:run`).
- Instant retry never retries a `ConfigurationException`, whatever `InstantRetryConfiguration` or `#[InstantRetry]` lists:
  a missing table, a refused setup or an uninitialized projection fails the same way on every attempt, so it reaches the
  caller or the error channel on the first one.
- A flow test applies the instant-retry defaults of the two rows above too. A test that expected the first consumer run to fail
  and a second run to succeed now sees the failure retried instantly inside the first run, and a test that expects a command or
  a consumer to fail runs it up to 4 times before the failure reaches it. Switch the defaults off for such a test with
  `testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false)` — an `InstantRetryConfiguration`
  the test sets still applies — or assert the retried outcome.
- Instant retries multiply the delayed ones: `ErrorHandlerConfiguration` counts *deliveries*, and each delivery runs the
  handler up to `1 + asynchronousRetryTimes` times before the error channel sees it. With the default of 3 instant retries and a
  `RetryTemplateBuilder` of 3 retries, a message that always fails runs its handler 16 times before it reaches the dead letter.
  The dead-letter log line and the final `MessageHandlingException` name both budgets: `4 failed deliveries (1 initial + 3
  retries), each delivery tried up to 4 times by instant retry (InstantRetryConfiguration): up to 16 handler invocations`.

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

**Now:** a handler's routing key may equal an asynchronous channel name; sending through the bus by that routing key
(`sendWithRouting()`, `publishWithRouting()`) reaches the handler. Routing keys are not a separate namespace from
channels, though: a `#[CommandHandler]` or `#[QueryHandler]` routing key still registers a message channel of the same
name, which a `#[BusinessMethod]` gateway, an `outputChannelName` or a direct channel send reaches.

**How to adapt:** Nothing; remove workarounds that renamed channels to avoid the clash.

## 12. Framework configuration: `ServiceContext` only

**Before:** Symfony `config/packages/ecotone.yaml`, Laravel `config/ecotone.php` and Tempest `EcotoneConfig` each
exposed most `ServiceConfiguration` options (`serviceName`, `defaultSerializationMediaType`, `defaultErrorChannel`,
`defaultMemoryLimit`, `defaultConnectionExceptionRetry`, `headersInLogContext`, `failFast`, `cacheConfiguration`), and
`ServiceConfiguration` also carried the discovery settings (`withNamespaces()`, `withEnvironment()`,
`withEnabledModulePackageNames()`, `withLicenceKey()`, `withExtensionObjects()`, `withCacheDirectoryPath()`). A
`ServiceConfiguration` returned from a `#[ServiceContext]` method was merged into the framework's one, and only its
serialization media type and default error channel survived the merge; every other value set there was ignored.

**Now:** `ServiceConfiguration` is an extension object like `DbalConfiguration`: it holds the application settings
only - `withServiceName()`, `withDefaultSerializationMediaType()`, `withDefaultErrorChannel()`,
`withConsumerMemoryLimit()`, `withConnectionRetryTemplate()`, `withHeadersInLogContext()` - and is returned from exactly
one `#[ServiceContext]` method (or passed once in `EcotoneLite`'s `extensionObjects:`). Nothing is merged: a second
one is refused at bootstrap, naming both origins:
`ConfigurationException: Ecotone was given more than one ServiceConfiguration: App\Billing::first() and
App\Shipping::second() each return one. Ecotone reads exactly one ServiceConfiguration, so it cannot tell which
serviceName, defaultSerializationMediaType, defaultErrorChannel, defaultMemoryLimit, connectionRetryTemplate or
headersInLogContext to apply. Keep one #[ServiceContext] method returning ServiceConfiguration::createWithDefaults()
and move every with*() call onto it.`

The framework configuration keeps only what has to be known before the `#[ServiceContext]` methods can be found:
`namespaces`, `loadSrcNamespaces` / `loadAppNamespaces`, `enabledModulePackageNames`, `test` and `licenceKey`. The
environment comes from the framework. `failFast` is gone (it was read nowhere) and `cacheConfiguration` is gone: the
dumped container is reused as is in the production environment, or when the framework caches its own configuration
(Laravel after `config:cache`, Tempest with `DISCOVERY_CACHE=true`), and rebuilt whenever a scanned file changes
otherwise. Symfony's kernel cache always owned the Ecotone container, so nothing changes there. A removed key is
refused with its replacement:
`ConfigurationException: Ecotone does not recognise the \`defaultErrorChannel\` key in config/ecotone.php. Since Ecotone
2.0 it is set on ServiceConfiguration, returned from a #[ServiceContext] method:
ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel'). Move it there and remove the key.
The keys Ecotone reads in config/ecotone.php are: enabledModulePackageNames, licenceKey, loadAppNamespaces, namespaces, test.`

`EcotoneLite` takes the discovery settings as parameters instead of a `configuration:` object: `extensionObjects:`,
`enabledModules:`, `licenceKey:`, `namespaces:`, `environment:` and `cacheDirectoryPath:`. There is no catalog
parameter: `withLoadCatalog('src')` becomes `namespaces: ['App']`, the namespace your composer autoload maps to that
directory, and the scan reads the same files.

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

Delete the corresponding keys from `ecotone.yaml` / `config/ecotone.php` / `EcotoneConfig`. In tests, replace the
`configuration:` argument:

```php
// Before
EcotoneLite::bootstrapFlowTesting(
    [OrderService::class],
    [new OrderService()],
    ServiceConfiguration::createWithDefaults()
        ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
        ->withDefaultErrorChannel('errorChannel')
        ->withExtensionObjects([SimpleMessageChannelBuilder::createQueueChannel('orders')])
        ->withLicenceKey($licenceKey),
);

// Now
EcotoneLite::bootstrapFlowTesting(
    [OrderService::class],
    [new OrderService()],
    extensionObjects: [
        ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel'),
        SimpleMessageChannelBuilder::createQueueChannel('orders'),
    ],
    enabledModules: [ModulePackageList::DBAL_PACKAGE],
    licenceKey: $licenceKey,
);
```

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
  `Ecotone\Api\Projecting\ProjectionDelete`, `Ecotone\Api\Projecting\ProjectionStateGateway`) — a module-scoped class stays
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

**The Kafka broker's default container id changes with its class.** Kafka consumers and publishers look the broker up
by `brokerConfigurationReference`, whose default is `KafkaBrokerConfiguration::class` — so the default id moves from
`Ecotone\Kafka\Configuration\KafkaBrokerConfiguration` to `Ecotone\Api\Kafka\KafkaBrokerConfiguration`. Code that
registers the broker with `KafkaBrokerConfiguration::class` follows the move once its import is updated; a broker
registered under the old id as a string (a Symfony service id, a Laravel binding) or a `brokerConfigurationReference`
passed as the old string is no longer found. Register it under the new id.

**The gateways' container ids change with their classes too.** Ecotone registers each gateway below — and the clock —
under its interface's `::class`, so its container id is the class's FQCN and moves with it. No alias is kept under the
1.x id:

| Service | 1.x id | 2.0 id | Defaults that follow it |
|---|---|---|---|
| `CommandBus` | `Ecotone\Modelling\CommandBus` | `Ecotone\Api\Gateway\CommandBus` | |
| `EventBus` | `Ecotone\Modelling\EventBus` | `Ecotone\Api\Gateway\EventBus` | |
| `QueryBus` | `Ecotone\Modelling\QueryBus` | `Ecotone\Api\Gateway\QueryBus` | |
| `DistributedBus` | `Ecotone\Modelling\DistributedBus` | `Ecotone\Api\Gateway\DistributedBus` | `DistributedServiceMap::initialize(referenceName:)`, `FlowTestSupport::getDistributedBus()` |
| `MessagePublisher` | `Ecotone\Messaging\MessagePublisher` | `Ecotone\Api\Gateway\MessagePublisher` | the default reference name of `AmqpMessagePublisherConfiguration`, `SqsMessagePublisherConfiguration`, `RedisMessagePublisherConfiguration` and `KafkaPublisherConfiguration`; `getMessagePublisher()` |
| `DocumentStore` | `Ecotone\Messaging\Store\Document\DocumentStore` | `Ecotone\Api\Gateway\DocumentStore` | `DbalConfiguration::withDocumentStore(reference:)` |
| `DeadLetterGateway` | `Ecotone\Dbal\Recoverability\DeadLetterGateway` | `Ecotone\Api\Dbal\Gateway\DeadLetterGateway` | |
| `SerializerGateway` (1.x `Serializer`, renamed; §7) | `Ecotone\Messaging\Gateway\Converter\Serializer` | `Ecotone\Api\Gateway\SerializerGateway` | |
| `EcotoneClockInterface` | `Ecotone\Messaging\Scheduling\EcotoneClockInterface` | `Ecotone\Api\Gateway\EcotoneClockInterface` | |
| `EventStore` (§13b) | `Ecotone\EventSourcing\EventStore` | `Ecotone\Api\EventSourcing\EventStore` | `EventSourcingConfiguration::create(eventStoreReferenceName:)`, `#[FromStream]` and `#[FromAggregateStream]`'s `eventStoreReferenceName` |

**Code that names these by `::class` is unaffected** once its import is updated: a constructor or handler parameter
typed `CommandBus`, `$container->get(CommandBus::class)`, `$app->make(EventStore::class)`,
`$ecotone->getGateway(DeadLetterGateway::class)`, and every default in the last column follow the class. **What breaks
is the 1.x id spelled as a string**: a Symfony `services.yaml` argument or alias such as `'@Ecotone\Modelling\CommandBus'`,
a Laravel `app('Ecotone\EventSourcing\EventStore')` or a binding keyed on the old name, an
`EventSourcingConfiguration::create(eventStoreReferenceName: 'Ecotone\EventSourcing\EventStore')`, a
`#[FromStream('orders', eventStoreReferenceName: 'Ecotone\EventSourcing\EventStore')]`, or a publisher, distributed bus or
document store reference passed as the old string. Nothing is registered under the old id any more, so a lookup
fails, and a reference name that was meant to match the default now names a separate service. A clock you registered
yourself under the old `EcotoneClockInterface` id is not the one Ecotone uses: it looks the clock up under the new id
and, finding nothing there, uses its own. Spell the new id, or use `::class`. `EventStore::RAW_REFERENCE` is the
exception: it is a string constant, still `'ecotone.eventSourcing.eventStore.instance'`.

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

**How to adapt:** replace the three imports. Nothing else changes — same class names, same methods, same enum
cases — except one method on `MediaType`, below.

**`MediaType::getTypeParameter()` is removed.** It returned `Ecotone\Messaging\Handler\Type`, an internal class, so
the one `Api` method an application could call kept it on the public surface. The `type` parameter is still on the
media type; read it as the string it is:

```php
// Before
$mediaType->getTypeParameter();                          // Ecotone\Messaging\Handler\Type, internal
// Now
$mediaType->getParameter(MediaType::TYPE_PARAMETER);    // 'array<App\Order>'
```

`MediaType::TYPE_PARAMETER`, private until now, is public for that call. `hasTypeParameter()` is unchanged.

Only the builder moved, not what it builds: `RetryTemplateBuilder::build()` still returns
`Ecotone\Messaging\Handler\Recoverability\RetryTemplate`, which stays internal because the framework, not the
application, calls `build()`. `MediaType` is also still what `CommandBus`, `QueryBus`, `EventBus`,
`DistributedBus`, `MessagePublisher` and `#[ContentType]` name, which is why it could not stay in `src`: a public
signature was forcing an `@internal` import on every application that used it. The rule this closes is
[conventions rule 12a](docs/coding-conventions.md#12a-the-boundary-holds-in-both-directions).

### 13b. `EventStore` moved into `Api`; `ConversionService` moved in and back out

**Before:** the event store gateway sat in an `@internal` namespace, although an application fetches it:

```php
use Ecotone\EventSourcing\EventStore;                  // internal
```

**Now:** `EventStore` lives beside the `AppendCondition`, `EventCriteria` and `LoadedEvents` it takes and returns,
so a typical usage imports from one namespace:

```php
use Ecotone\Api\EventSourcing\EventStore;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\EventSourcing\EventStore` | `Ecotone\Api\EventSourcing\EventStore` |

**How to adapt:** replace the import; the row is in `upgrade/namespace-map-2.0.csv`. Same interface name, same
methods. **The gateway's container id moves with the class**, from `Ecotone\EventSourcing\EventStore` to
`Ecotone\Api\EventSourcing\EventStore`, and so does the default `eventStoreReferenceName` of
`EventSourcingConfiguration::create()`, `#[FromStream]` and `#[FromAggregateStream]` — the table in §13 lists what
breaks: a lookup or reference spelling the 1.x id as a string. Only `EventStore::RAW_REFERENCE`, a string constant,
is unchanged: still `'ecotone.eventSourcing.eventStore.instance'`.

**`ConversionService` is internal, at its 1.x name — this is its second move inside 2.0.** An earlier 2.0 build moved
it to `Ecotone\Api\Conversion\ConversionService`, and this section then told you to follow it. It has moved back to
`Ecotone\Messaging\Conversion\ConversionService`: its `convert()` and `canConvert()` take the internal
`Ecotone\Messaging\Handler\Type`, so it cannot be public surface. The application-facing conversion API is
`Ecotone\Api\Gateway\SerializerGateway`, which takes media types and target types as strings:

| | class | container id |
|---|---|---|
| 1.x | `Ecotone\Messaging\Conversion\ConversionService` | `'Ecotone\Messaging\Conversion\ConversionService'` |
| first 2.0 move | `Ecotone\Api\Conversion\ConversionService` | `'Ecotone\Api\Conversion\ConversionService'` |
| 2.0 | `Ecotone\Messaging\Conversion\ConversionService`, internal | `'Ecotone\Messaging\Conversion\ConversionService'` |

`ConversionService::REFERENCE_NAME` is `self::class`, so the container id followed the class both times.

**How to adapt:**

- **Coming from 1.x:** the class name and the container id are what they were, so nothing breaks. Code that
  converts through the engine should move to `SerializerGateway`, since the engine is no longer covered by the
  upgrade guarantees.
- **Coming from an earlier 2.0 build that followed the first note:** the `Ecotone\Api\Conversion\ConversionService`
  import and the `'Ecotone\Api\Conversion\ConversionService'` id no longer exist — in `getServiceFromContainer()`,
  a `#[Reference('...')]`, or a framework alias or binding. Move to `SerializerGateway` rather than back to the
  internal name.

```php
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\SerializerGateway;

#[CommandHandler]
public function handle(ExportOrder $command, SerializerGateway $serializer): void
{
    $json = $serializer->convertFromPHP($command->order, MediaType::APPLICATION_JSON);
    $order = $serializer->convertToPHP($json, MediaType::APPLICATION_JSON, Order::class);
}
```

### 13c. Aggregate traits, `Event`, interceptor types, `TimeSpan`, `MessageHeaders`, `ModulePackageList` and `DynamicMessageChannelBuilder` moved into `Api` too

**Before:** nine types an application writes sat in `@internal` namespaces. Several were already named by a public
`Api` signature — `EventStore` returned `Event`, `#[Around]` and its siblings defaulted to a `Precedence` constant,
`#[Delayed]` and `#[TimeToLive]` took a `TimeSpan` — so an application using the public class still had to import
an internal one:

```php
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;    // internal
use Ecotone\Messaging\Config\ModulePackageList;                              // internal
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;      // internal
use Ecotone\Messaging\MessageHeaders;                                        // internal
use Ecotone\Messaging\Precedence;                                            // internal
use Ecotone\Messaging\Scheduling\TimeSpan;                                   // internal
use Ecotone\Modelling\Event;                                                 // internal
use Ecotone\Modelling\WithAggregateVersioning;                               // internal
use Ecotone\Modelling\WithEvents;                                            // internal
```

**Now:** each sits beside the public types that take or return it. The aggregate traits get a `Modelling` area,
`Event` joins the `EventStore` and `LoadedEvents` that return it, the two types every interceptor writes get an
`Interceptor` area, and the two `#[ServiceContext]` values join the other extension objects:

```php
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Modelling\WithEvents;
use Ecotone\Api\Scheduling\TimeSpan;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Modelling\WithEvents` | `Ecotone\Api\Modelling\WithEvents` |
| `Ecotone\Modelling\WithAggregateVersioning` | `Ecotone\Api\Modelling\WithAggregateVersioning` |
| `Ecotone\Modelling\Event` | `Ecotone\Api\EventSourcing\Event` |
| `Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation` | `Ecotone\Api\Interceptor\MethodInvocation` |
| `Ecotone\Messaging\Precedence` | `Ecotone\Api\Interceptor\Precedence` |
| `Ecotone\Messaging\Scheduling\TimeSpan` | `Ecotone\Api\Scheduling\TimeSpan` |
| `Ecotone\Messaging\MessageHeaders` | `Ecotone\Api\Messaging\MessageHeaders` |
| `Ecotone\Messaging\Config\ModulePackageList` | `Ecotone\Api\ExtensionObject\ModulePackageList` |
| `Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder` | `Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder` |

**How to adapt:** replace the imports; every row is in `upgrade/namespace-map-2.0.csv`. Same class names, same
methods, same constants with the same values — a header named with `MessageHeaders::MESSAGE_ID` is still `'id'`, a
skip list built from `ModulePackageList::DBAL_PACKAGE` is still `'dbal'`, and `Precedence::DATABASE_TRANSACTION_PRECEDENCE`
is still `-2000`. None of the nine is a container service, so no service id changes with them.

A trait is resolved when its class is loaded, so an aggregate that still says `use Ecotone\Modelling\WithEvents;`
fails at that point with PHP's `Trait "Ecotone\Modelling\WithEvents" not found`, not at bootstrap; a stale
`Precedence` or `MessageHeaders` import fails as soon as the attribute or constant is read. `Event` stays
extendable — its constructor is still `protected`.

**The framework-only members did not come with them.** `MessageHeaders` and `ModulePackageList` moved because an
application names their constants; their static helpers are module and transport plumbing that only Ecotone calls,
so they left the public classes:

| Removed from `Api` | Now |
|---|---|
| `MessageHeaders::unsetAllFrameworkHeaders()`, `unsetNonUserKeys()`, `unsetEnqueueMetadata()`, `unsetAggregateKeys()`, `propagateContextHeaders()` | internal, `Ecotone\Messaging\Support\FrameworkHeaders` |
| `MessageHeaders::getFrameworksHeaderNames()`, `unsetCoreFrameworkHeaders()`, `unsetAsyncKeys()`, `unsetDistributionKeys()`, `unsetBusKeys()` | internal and private to `FrameworkHeaders` |
| `MessageHeaders::unsetFrameworkKeys()`, `unsetTransportMessageKeys()` | gone — nothing called them |
| `ModulePackageList::allPackages()` | internal, `Ecotone\Messaging\Config\ModuleClassList::packageNames()`, which now includes `TEST_PACKAGE` |
| `ModulePackageList::getModuleClassesForPackage()` | internal, `ModuleClassList::forPackage()` |
| `ModulePackageList::allPackagesExcept()` | gone — pass the packages it kept to `withEnabledModulePackageNames()` (§1) |
| `ModulePackageList::ASYNCHRONOUS_PACKAGE` | gone — asynchronous processing is part of Core and always loads (§1) |

**How to adapt:** nothing, unless you called one of them. The set of keys Ecotone treats as its own is not a contract
and changes between releases, so do not re-create these filters from the internal class: an application that
forwarded metadata with framework keys stripped keeps the keys it set itself, by name —
`array_intersect_key($metadata, array_flip(['tenantId', 'userId']))`. Code that listed every package to build a
`withEnabledModulePackageNames()` argument names the packages it needs instead, as `ModulePackageList` constants.

Only these nine moved here. `EcotoneLite` stayed behind at first: it hands the application `FlowTestSupport` and
`ConfiguredMessagingSystem`, so moving it alone would have relocated the boundary without closing it. The three
moved together later, in §13e. `ErrorMessage` moved last, in §13i. The rule this closes is
[conventions rule 12a](docs/coding-conventions.md#12a-the-boundary-holds-in-both-directions).

### 13d. `MetadataMatcher`, the clock's types, `Future`, `ErrorContext`, `LoggingLevel` and `FetchMode` moved into `Api` too

**Before:** each of these was named by a public `Api` signature or written as an attribute argument, while it sat
in an `@internal` namespace. `EventStore::load()` took a `MetadataMatcher`, which is built from `Operator` and
`FieldType`; `EcotoneClockInterface::now()` returned a `DatePoint`, the interface extended `SleepInterface`, and both
work in `Duration`s; `MessagePublisher::publishDeferred()` returned a `Future`; Dbal's `DeadLetterGateway::list()`
returned `ErrorContext[]`; and `#[LogError]` and `#[DbalQuery]` took a `LoggingLevel` and a `FetchMode` constant:

```php
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;                    // internal
use Ecotone\EventSourcing\EventStore\FieldType;                  // internal
use Ecotone\EventSourcing\EventStore\MetadataMatcher;            // internal
use Ecotone\EventSourcing\EventStore\Operator;                   // internal
use Ecotone\Messaging\Future;                                    // internal
use Ecotone\Messaging\Handler\Logger\LoggingLevel;               // internal
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;       // internal
use Ecotone\Messaging\Scheduling\DatePoint;                      // internal
use Ecotone\Messaging\Scheduling\Duration;                       // internal
use Ecotone\Messaging\Scheduling\SleepInterface;                 // internal
```

**Now:** each sits beside the public type that takes or returns it — the matcher and its enums with `EventStore`,
the clock's types with `TimeSpan`, `Future` and `ErrorContext` with `MessageHeaders`, `FetchMode` in
`Ecotone\Api\Dbal` — and `LoggingLevel` gets a `Logging` area of its own:

```php
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\EventSourcing\FieldType;
use Ecotone\Api\EventSourcing\MetadataMatcher;
use Ecotone\Api\EventSourcing\Operator;
use Ecotone\Api\Logging\LoggingLevel;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Future;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\SleepInterface;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\EventSourcing\EventStore\MetadataMatcher` | `Ecotone\Api\EventSourcing\MetadataMatcher` |
| `Ecotone\EventSourcing\EventStore\Operator` | `Ecotone\Api\EventSourcing\Operator` |
| `Ecotone\EventSourcing\EventStore\FieldType` | `Ecotone\Api\EventSourcing\FieldType` |
| `Ecotone\Messaging\Scheduling\DatePoint` | `Ecotone\Api\Scheduling\DatePoint` |
| `Ecotone\Messaging\Scheduling\SleepInterface` | `Ecotone\Api\Scheduling\SleepInterface` |
| `Ecotone\Messaging\Scheduling\Duration` | `Ecotone\Api\Scheduling\Duration` |
| `Ecotone\Messaging\Future` | `Ecotone\Api\Messaging\Future` |
| `Ecotone\Messaging\Handler\Recoverability\ErrorContext` | `Ecotone\Api\Messaging\ErrorContext` |
| `Ecotone\Messaging\Handler\Logger\LoggingLevel` | `Ecotone\Api\Logging\LoggingLevel` |
| `Ecotone\Dbal\DbaBusinessMethod\FetchMode` | `Ecotone\Api\Dbal\FetchMode` |

**How to adapt:** replace the imports; every row is in `upgrade/namespace-map-2.0.csv`. Same class names, same
methods, same constants with the same values — `FetchMode::FIRST_ROW` is still `2`, `LoggingLevel::CRITICAL` is still
PSR-3's `'critical'`, `Operator::EQUALS` is still `'='`, and `ErrorContext`'s constants are still the header names a
failed message carries, so dead letters stored before the upgrade read back unchanged. None of the ten is a
container service, so no service id changes with them.

A stale import does not fail where it is written. PHP evaluates an attribute's arguments only when the attribute
is instantiated, so `#[DbalQuery('…', fetchMode: FetchMode::FIRST_ROW)]` with the old import fails then, with
`Class "Ecotone\Dbal\DbaBusinessMethod\FetchMode" not found`; a PSR clock that implements the old `SleepInterface`
fails when the clock class is loaded.

The namespace `FetchMode` left is renamed too: `Ecotone\Dbal\DbaBusinessMethod` was a typo for
`Ecotone\Dbal\DbalBusinessMethod`, and `DbaBusinessMethodModule` is `DbalBusinessMethodModule`. Everything left in it
is internal — the module, `DbalBusinessMethodHandler` and `DbalParameterConfig` — so an application only ever met
the old name through `FetchMode`; the three rows are in the namespace map for code that named them anyway.

`ErrorMessage` stayed `Ecotone\Messaging\Support\ErrorMessage` at this step, now returning the public `ErrorContext`
from `getErrorContext()`. It implements `Message` and takes one in `create()` and `createFromMessage()`, and `Message`
was then still internal; once `Message` moved (§13g), `ErrorMessage` followed it (§13i). The rule this closes is
[conventions rule 12a](docs/coding-conventions.md#12a-the-boundary-holds-in-both-directions).

### 13e. `EcotoneLite`, `FlowTestSupport`, `ConfiguredMessagingSystem` and the channel interfaces moved into `Api` — two container ids change with them

**Before:** the entry point of every flow test and standalone application, and both types it returns, were
internal. So were the channel interfaces those two hand back:

```php
use Ecotone\Lite\EcotoneLite;                               // internal
use Ecotone\Lite\Test\FlowTestSupport;                      // internal
use Ecotone\Lite\Test\MessagingTestSupport;                 // internal
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;     // internal
use Ecotone\Messaging\MessageChannel;                       // internal
use Ecotone\Messaging\MessagePoller;                        // internal
use Ecotone\Messaging\PollableChannel;                      // internal
```

**Now:** the factory and its test support have a `Lite` area, and the messaging system sits with the channels it
returns:

```php
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Lite\Test\MessagingTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Api\Messaging\MessagePoller;
use Ecotone\Api\Messaging\PollableChannel;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Lite\EcotoneLite` | `Ecotone\Api\Lite\EcotoneLite` |
| `Ecotone\Lite\Test\FlowTestSupport` | `Ecotone\Api\Lite\Test\FlowTestSupport` |
| `Ecotone\Lite\Test\MessagingTestSupport` | `Ecotone\Api\Lite\Test\MessagingTestSupport` |
| `Ecotone\Messaging\Config\ConfiguredMessagingSystem` | `Ecotone\Api\Messaging\ConfiguredMessagingSystem` |
| `Ecotone\Messaging\MessageChannel` | `Ecotone\Api\Messaging\MessageChannel` |
| `Ecotone\Messaging\PollableChannel` | `Ecotone\Api\Messaging\PollableChannel` |
| `Ecotone\Messaging\MessagePoller` | `Ecotone\Api\Messaging\MessagePoller` |

`MessagingTestSupport` moved because `FlowTestSupport::getMessagingTestSupport()` returns it, and `MessagePoller`
because `PollableChannel` extends it.

**How to adapt:** replace the imports; every row is in `upgrade/namespace-map-2.0.csv`. Same class names, same
methods, same parameters — `EcotoneLite::bootstrapFlowTesting(classesToResolve: …, licenceKey: …)` takes the same
named arguments, and a channel you implemented yourself keeps its methods, now implementing the `Api` interface.

**Two container ids change**, as the gateways' did in §13 and `EventStore`'s in §13b; §13a, §13c and §13d moved no
container service. Two of these types are services registered under their class name, so the id is the new FQCN:

| Service | 1.x and early 2.0 id | 2.0 id |
|---|---|---|
| `ConfiguredMessagingSystem` | `Ecotone\Messaging\Config\ConfiguredMessagingSystem` | `Ecotone\Api\Messaging\ConfiguredMessagingSystem` |
| `MessagingTestSupport`, a gateway registered only when testing is enabled | `Ecotone\Lite\Test\MessagingTestSupport` | `Ecotone\Api\Lite\Test\MessagingTestSupport` |

Ecotone registers both by `::class` and bridges them the same way, so nothing that names the class changes beyond
its import. That covers Symfony and Laravel, which receive both through `EcotoneContainer::registerBridgesInto()`,
and Tempest, which binds `ConfiguredMessagingSystem` through an initializer typed on the interface: a
`$container->get(ConfiguredMessagingSystem::class)`, a `$app->make(...)`, a constructor argument autowired by type,
and `getGatewayByName(MessagingTestSupport::class)` all resolve once the import is updated. No alias is kept under
the old id. What breaks is a lookup that spells the old id as a string — a `services.yaml` argument or alias naming
`Ecotone\Messaging\Config\ConfiguredMessagingSystem`, a `$container->get('Ecotone\Messaging\Config\ConfiguredMessagingSystem')`,
a Laravel binding keyed on the old name. Each fails because nothing is registered under that id any more; spell the
new id, or use `::class`.

**Clear Ecotone's cache when deploying the upgrade.** A container compiled before it holds the old ids, and the
gateway proxies generated with it take the old `ConfiguredMessagingSystem` in their constructor. Delete Ecotone's cache
directory before the first request: `ecotone/` under the kernel's build directory in Symfony (`var/cache/<env>/ecotone`
by default), `storage/framework/cache/data/ecotone` in Laravel, and the `ServiceConfiguration::withCacheDirectoryPath()`
directory in Ecotone Lite.

**What is still internal**, and why each one is not a reason to keep the old imports:

- `Message`, named by five `FlowTestSupport` methods, the `popRecorded…Messages…()` methods of
  `MessagingTestSupport`, `MessageChannel::send()`, `PollableChannel::receive()` and
  `MessagePoller::receiveWithTimeout()`, was internal when this section was written and has since moved (§13g)
- `InMemoryConsoleWriter`, returned by `FlowTestSupport::getInMemoryConsoleWriter()`, was internal when this section
  was written and has since moved with the rest of the console writer types (§13f)
- `Gateway` and `GatewayProxyMethodReference`, named by `ConfiguredMessagingSystem::getNonProxyGatewayByName()`, and
  `GatewayProxyReference`, the element type of `getGatewayList()`. Only the framework calls those two methods
- `ConfiguredMessagingSystemWithTestSupport` and `InMemoryPSRContainer`: `EcotoneLite` builds them, and no signature
  an application calls names either

The rule this closes is
[conventions rule 12a](docs/coding-conventions.md#12a-the-boundary-holds-in-both-directions).

### 13f. The console writer types moved into `Api` — the `ConsoleWriter` and `InMemoryConsoleWriter` container ids change with them

**Before:** a `#[ConsoleCommand]` that writes output takes a `ConsoleWriter` parameter and may call its
`progressBar()`, and a flow test reads what the command wrote through `FlowTestSupport::getInMemoryConsoleWriter()` —
all four types internal:

```php
use Ecotone\Messaging\Console\ConsoleWriter;                // internal
use Ecotone\Messaging\Console\ConsoleProgressBar;           // internal
use Ecotone\Messaging\Console\InMemoryConsoleWriter;        // internal
use Ecotone\Messaging\Console\InMemoryConsoleProgressBar;   // internal
```

**Now:**

```php
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Console\ConsoleProgressBar;
use Ecotone\Api\Console\InMemoryConsoleWriter;
use Ecotone\Api\Console\InMemoryConsoleProgressBar;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Messaging\Console\ConsoleWriter` | `Ecotone\Api\Console\ConsoleWriter` |
| `Ecotone\Messaging\Console\ConsoleProgressBar` | `Ecotone\Api\Console\ConsoleProgressBar` |
| `Ecotone\Messaging\Console\InMemoryConsoleWriter` | `Ecotone\Api\Console\InMemoryConsoleWriter` |
| `Ecotone\Messaging\Console\InMemoryConsoleProgressBar` | `Ecotone\Api\Console\InMemoryConsoleProgressBar` |

`ConsoleProgressBar` moved because `ConsoleWriter::progressBar()` returns it, and `InMemoryConsoleProgressBar` because
`InMemoryConsoleWriter::getProgressBars()` returns the ones it created.

**How to adapt:** replace the imports; every row is in `upgrade/namespace-map-2.0.csv`. Same methods, same
parameters. A console command keeps taking the writer as a parameter:

```php
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Console\ConsoleWriter;

final class ImportProducts
{
    #[ConsoleCommand('products:import')]
    public function import(ConsoleWriter $writer): void
    {
        $progressBar = $writer->progressBar(2);
        $progressBar->advance();
        $progressBar->finish();
    }
}
```

A writer you implemented yourself keeps its methods and now implements `Ecotone\Api\Console\ConsoleWriter`.

**Two container ids change**, as in §13e. Both types are services registered under their class name, so the id is the
new FQCN:

| Service | 1.x and early 2.0 id | 2.0 id |
|---|---|---|
| `ConsoleWriter`, the writer a console command receives | `Ecotone\Messaging\Console\ConsoleWriter` | `Ecotone\Api\Console\ConsoleWriter` |
| `InMemoryConsoleWriter`, registered only when testing is enabled | `Ecotone\Messaging\Console\InMemoryConsoleWriter` | `Ecotone\Api\Console\InMemoryConsoleWriter` |

Ecotone registers and looks up both by `::class`, so nothing that names the class changes beyond its import: a
`ConsoleWriter` parameter on a `#[ConsoleCommand]`, `FlowTestSupport::getInMemoryConsoleWriter()`, and
`getServiceFromContainer(ConsoleWriter::class)`. Symfony receives `ConsoleWriter` as a service of the same id and
passes it to every Ecotone console command; Laravel's artisan commands and Tempest's generated console commands fetch
it from Ecotone by `::class`. No alias is kept under either old id. What breaks is a lookup that spells an old id as a
string — a `services.yaml` argument naming `Ecotone\Messaging\Console\ConsoleWriter`, a
`$container->get('Ecotone\Messaging\Console\ConsoleWriter')`; spell the new id, or use `::class`.

**Clear Ecotone's cache when deploying the upgrade**, in the directories §13e lists. Tempest needs it for one more
reason: the console command classes it generates import `ConsoleWriter`, and they are regenerated only when the
configuration changes, so a stale one names a type that no longer exists. Run `ecotone:cache:clear`, or delete
`ecotone_tempest` under the system temporary directory.

**What stays internal:** the writers the framework constructs — `DelegatingConsoleWriter`, `PlainConsoleWriter`,
`SymfonyConsoleWriter`, Tempest's `TempestConsoleWriter` and their progress bars. An application never names them;
they implement the `Api` interfaces.

**`ConsoleCommandResultSet` moved with them.** A `#[ConsoleCommand]` that prints a table returns
`Ecotone\Messaging\Config\ConsoleCommandResultSet`; it is now `Ecotone\Api\Console\ConsoleCommandResultSet`, built
with the same `ConsoleCommandResultSet::create($columnHeaders, $rows)`. Its `getColumnHeaders()` and `getRows()` are the
public readonly properties `columnHeaders` and `rows`, which matters to a test that reads the result a console command
returned: `$result->getRows()` becomes `$result->rows`.

### 13g. `Message` moved into `Api`

**Before:** the message itself was internal, although an application meets it whenever it works below the buses — a
handler or interceptor that takes the whole message, a flow test that sends to or receives from a channel, the
dead-letter gateway:

```php
use Ecotone\Messaging\Message;   // internal
```

**Now:** it sits beside `MessageHeaders`, which its `getHeaders()` returns, and the channel interfaces that send and
receive it:

```php
use Ecotone\Api\Messaging\Message;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Messaging\Message` | `Ecotone\Api\Messaging\Message` |

The signatures it closes, all of them methods an application calls: `FlowTestSupport::popRecordedMessagesFrom()`
and `sendDirectToChannelWithMessageReply()` (its `receiveMessageFrom()`, `sendMessageDirectToChannel()` and
`sendMessageDirectToChannelWithMessageReply()` were removed later, see §15); the `popRecorded…Messages…()` methods of `MessagingTestSupport`;
`MessageChannel::send()`, `PollableChannel::receive()` and `MessagePoller::receiveWithTimeout()`; Dbal's
`DeadLetterGateway::store()` and `show()`; and `getFailedMessage()` on the exceptions an application catches, such as
`DecisionModelConcurrencyException`.

**How to adapt:** replace the import; the row is in `upgrade/namespace-map-2.0.csv`. Same two methods,
`getHeaders()` and `getPayload()`. A handler that takes the whole message keeps doing so with the new import:

```php
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Messaging\Message;

final class ErrorChannelService
{
    #[InternalHandler('default_dead_letter')]
    public function errorChannel(Message $errorMessage): void
    {
    }
}
```

A parameter still typed with the old name does not silently stop receiving the message: bootstrap refuses it with
`Interface ErrorChannelService has problem with type declaration. Error while parsing 'Ecotone\Messaging\Message'.
Unknown type or class 'Ecotone\Messaging\Message'`. Fix the import.

**No container id changes.** `Message` is not a service: Ecotone uses its class name only to decide whether a
parameter or a reply is the whole message, and that follows the import. Clear Ecotone's cache on deploy all the same,
in the directories §13e lists, because a compiled container records parameter types by name.

**What stays internal:** the implementations — `GenericMessage` and `MessageBuilder`, in
`Ecotone\Messaging\Support`. An implementation in `src` of an `Api` interface is the permitted direction. One of them
an application used to meet: a test that called `sendMessageDirectToChannel()` built its `Message` with
`MessageBuilder`, since `Api` has no way to construct one — closed by removing that method (§15). The third
implementation, `ErrorMessage`, is one an application types its error-channel handler's parameter with, so it moved
into `Api` (§13i).

### 13h. `MessageChannelBuilder` moved into `Api`

**Before:** the type `DynamicMessageChannelBuilder` takes for its internal channels was internal, so an application
building a dynamic channel, or declaring a `#[ServiceContext]` that returns any channel builder, imported it from
`src`:

```php
use Ecotone\Messaging\Channel\MessageChannelBuilder;   // internal
```

**Now:** it sits beside `DynamicMessageChannelBuilder` and `SimpleMessageChannelBuilder`:

```php
use Ecotone\Api\ExtensionObject\MessageChannelBuilder;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Messaging\Channel\MessageChannelBuilder` | `Ecotone\Api\ExtensionObject\MessageChannelBuilder` |

The signatures it closes: `DynamicMessageChannelBuilder::createWithSendOnlyStrategy()` and `withInternalChannels()`
take it, and `createRoundRobin()` and `createRoundRobinWithDifferentChannels()` take a list of it.

**How to adapt:** replace the import; the row is in `upgrade/namespace-map-2.0.csv`. The interface is unchanged —
`getMessageChannelName()`, `isPollable()`, `isStreamingChannel()` and the `compile()` it inherits — so a class
implementing it needs only the import.

**No container id changes.** Channels are registered under their channel names, not under this type.

**What stays internal:** `CompilableBuilder`, which it extends, and `MessageChannelWithSerializationBuilder`, which
extends it. Only the framework calls what they add — `compile()`, `getConversionMediaType()`, `getHeaderMapper()`.

### 13i. `ErrorMessage` moved into `Api`

**Before:** an error-channel handler that wants the failure as a typed object, rather than reading `ErrorContext`'s
header keys itself, types its parameter `ErrorMessage`, and that class was internal:

```php
use Ecotone\Messaging\Support\ErrorMessage;   // internal
```

**Now:** it sits beside the `Message` it implements and the `ErrorContext` its `getErrorContext()` returns:

```php
use Ecotone\Api\Messaging\ErrorMessage;
```

| 1.x and early 2.0 | 2.0 |
|---|---|
| `Ecotone\Messaging\Support\ErrorMessage` | `Ecotone\Api\Messaging\ErrorMessage` |

Nothing it names had to move with it: `Message`, `MessageHeaders` and `ErrorContext` were already in `Api`.

**How to adapt:** replace the import; the row is in `upgrade/namespace-map-2.0.csv`. Same methods —
`getErrorContext()`, `getExceptionMessage()` and the other `getException…()` accessors, `getHeaders()`, `getPayload()`:

```php
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Messaging\ErrorMessage;

final class ErrorHandler
{
    #[InternalHandler('errorChannel')]
    public function handle(ErrorMessage $errorMessage): void
    {
    }
}
```

A parameter still typed with the old name is refused at bootstrap, as §13g describes for `Message`, naming the class
it cannot find. **No container id changes** — `ErrorMessage` is not a service.

### 13j. Types the documentation tells applications to name moved into `Api`

**Before:** writing the 2.0 documentation found internal `src` classes that an application has to name because the
tree offered nothing else — a repository it implements, an attribute it targets with a pointcut, a value it constructs
or an exception it catches. Each sat outside `Api`.

**Now:** each one below is in its package's `Api`, with the same methods. A type moves together with any type the
methods an application calls on it name — `AmqpBinding` with `AmqpQueue` and `AmqpExchange`, `DataProtectionConfiguration`
with `Key` — so none of those methods points back into `src`. Some keep an internal parent the application never calls
directly (`ConcurrencyException` and `AggregateNotFoundException` extend `MessagingException`; `AmqpStreamChannelBuilder`
extends `EnqueueMessageChannelBuilder`, as `AmqpBackedMessageChannelBuilder` already did).

| 1.x and early 2.0 | 2.0 | Why an application names it |
|---|---|---|
| `Ecotone\Modelling\StandardRepository` (1.x; `Ecotone\Modelling\StateStoredRepository` in pre-release 2.0) | `Ecotone\Api\Modelling\StateStoredRepository` | a custom `#[Repository]` implements it |
| `Ecotone\Modelling\EventSourcedRepository` | `Ecotone\Api\Modelling\EventSourcedRepository` | a custom event-sourced `#[Repository]` implements it |
| `Ecotone\Modelling\EventStream` | `Ecotone\Api\Modelling\EventStream` | a custom event-sourced `#[Repository]` returns it from `findBy()` |
| `Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint` | `Ecotone\Api\Attribute\AsynchronousRunningEndpoint` | the pointcut that targets every asynchronously running handler: `#[Around(pointcut: AsynchronousRunningEndpoint::class)]` |
| `Ecotone\Messaging\Attribute\AsynchronousEndpointAttribute` | `Ecotone\Api\Attribute\AsynchronousEndpointAttribute` | a custom attribute passed in `#[Asynchronous(asynchronousExecution: [...])]` implements it |
| `Ecotone\Messaging\BatchMessage` | `Ecotone\Api\Messaging\BatchMessage` | an application builds one and hands it to `MessagePublisher` to deliver a set of messages as one unit |
| `Ecotone\Messaging\Channel\Manager\ChannelInitializationConfiguration` | `Ecotone\Api\ExtensionObject\ChannelInitializationConfiguration` | returned from a `#[ServiceContext]` method, it is the one switch for automatic AMQP and SQS channel initialization |
| `Ecotone\Projecting\EventStoreAdapter\EventStreamingChannelAdapter` | `Ecotone\Api\Projecting\EventStreamingChannelAdapter` | returned from a `#[ServiceContext]` method, it feeds an event stream into a streaming channel (Enterprise) |
| `Ecotone\Messaging\Support\ConcurrencyException` | `Ecotone\Api\Messaging\ConcurrencyException` | a custom repository throws `ConcurrencyException::forStaleAggregate()`, and retry configuration and `catch` blocks name it |
| `Ecotone\Modelling\AggregateNotFoundException` | `Ecotone\Api\Modelling\AggregateNotFoundException` | thrown to the caller when a command or a `#[Repository]` lookup finds no aggregate; §14 tells applications which catches of it move |
| `Ecotone\Messaging\Config\ConnectionReference` | `Ecotone\Api\ExtensionObject\ConnectionReference` | an `#[OnTenantActivation]` / `#[OnTenantDeactivation]` hook receives it, and `MultiTenantConfiguration` takes it; every `*ConnectionReference` extends it |
| `Ecotone\Amqp\AmqpStreamChannelBuilder` | `Ecotone\Api\Amqp\AmqpStreamChannelBuilder` | returned from a `#[ServiceContext]` method, it declares a RabbitMQ Streams channel (Enterprise; §5, §6) |
| `Ecotone\Amqp\AmqpQueue` | `Ecotone\Api\Amqp\AmqpQueue` | returned from a `#[ServiceContext]` method, it declares a RabbitMQ queue |
| `Ecotone\Amqp\AmqpExchange` | `Ecotone\Api\Amqp\AmqpExchange` | declares a RabbitMQ exchange; `AmqpQueue::withDeadLetterExchangeTarget()` and `AmqpBinding` take it |
| `Ecotone\Amqp\AmqpBinding` | `Ecotone\Api\Amqp\AmqpBinding` | binds an `AmqpQueue` to an `AmqpExchange` |
| `Ecotone\DataProtection\Configuration\DataProtectionConfiguration` | `Ecotone\Api\DataProtection\DataProtectionConfiguration` | returned from a `#[ServiceContext]` method, it switches data protection on and names its keys (Enterprise) |
| `Ecotone\DataProtection\Configuration\ChannelProtectionConfiguration` | `Ecotone\Api\DataProtection\ChannelProtectionConfiguration` | returned from a `#[ServiceContext]` method, it protects one channel (Enterprise) |
| `Ecotone\DataProtection\Encryption\Key` | `Ecotone\Api\DataProtection\Key` | an application creates or loads a key (`Key::createNewRandomKey()`, `Key::loadFromAsciiSafeString()`) to pass to `DataProtectionConfiguration` |
| `Ecotone\EventSourcing\EventStreamEmitter` | `Ecotone\Api\EventSourcing\EventStreamEmitter` | a projection handler takes it as a parameter to `emit()` or `linkTo()` events (§3) |

**How to adapt:** replace the imports; every row is in `upgrade/namespace-map-2.0.csv`. Clear Ecotone's cache on
deploy, since the compiled container records class names. **One container id changes:** `EventStreamEmitter` is a
gateway registered under its class name, so its id is now `Ecotone\Api\EventSourcing\EventStreamEmitter`. A handler
parameter typed with it, and `$container->get(EventStreamEmitter::class)` with the new import, follow on their own; a
lookup by the old id as a string no longer finds it. None of the other types is a service.

### 13k. The connection factories moved into `Api`

**Before:** the connection factory an application constructs and registers in its container (§5) lived under each
package's `src`: `Ecotone\Dbal\Connection\DbalConnectionFactory` and `ManagerRegistryConnectionFactory`,
`Ecotone\Amqp\Connection\AmqpExtConnectionFactory` and `AmqpLibConnectionFactory`,
`Ecotone\Sqs\Connection\SqsConnectionFactory`, `Ecotone\Redis\Connection\RedisConnectionFactory`.

**Now:** each is in its package's `Api` namespace, with the same constructor:

| Pre-release 2.0 | 2.0 |
|---|---|
| `Ecotone\Dbal\Connection\DbalConnectionFactory` | `Ecotone\Api\Dbal\DbalConnectionFactory` |
| `Ecotone\Dbal\Connection\ManagerRegistryConnectionFactory` | `Ecotone\Api\Dbal\ManagerRegistryConnectionFactory` |
| `Ecotone\Amqp\Connection\AmqpExtConnectionFactory` | `Ecotone\Api\Amqp\AmqpExtConnectionFactory` |
| `Ecotone\Amqp\Connection\AmqpLibConnectionFactory` | `Ecotone\Api\Amqp\AmqpLibConnectionFactory` |
| `Ecotone\Sqs\Connection\SqsConnectionFactory` | `Ecotone\Api\Sqs\SqsConnectionFactory` |
| `Ecotone\Redis\Connection\RedisConnectionFactory` | `Ecotone\Api\Redis\RedisConnectionFactory` |

`DbalConnectionReference::DEFAULT`, `AmqpConnectionReference::DEFAULT` / `DEFAULT_STREAM`,
`SqsConnectionReference::DEFAULT` and `RedisConnectionReference::DEFAULT` are the factory class names, so their string
values move with them: `DbalConnectionReference::DEFAULT` is now `'Ecotone\Api\Dbal\DbalConnectionFactory'`. A
container that registers the factory under the `::DEFAULT` constant, or that passes the reference object, follows on its
own. One that registers it under the literal pre-release string has to use the constant.

**How to adapt:** change the import; `upgrade/namespace-map-2.0.csv` has every row. The transport internals
(`DbalContext`, `DbalConsumer`, the Enqueue contexts) stay where §5 lists them.

## 14. Smaller behaviour changes

- **`EventStore::load()` and `loadAggregateEvents()` refuse a `count` of `0` on DBAL too.** Before, DBAL returned no
  events while in-memory refused; both now throw `InvalidArgumentException` saying a count caps how many events are
  loaded and to pass `null` for all of them. **How to adapt:** pass `null`, or skip the call, where you passed `0`.
- **A document given as a string must be JSON on every engine and every write.** SQLite stored a non-JSON string,
  and the in-memory store refused one on `addDocument()` but stored it on `updateDocument()` / `upsertDocument()`;
  every store now refuses it with a `DocumentException` naming the document and the JSON error. The in-memory
  document store also keeps a copy: changing an object after storing it, or one it returned, no longer changes what
  is stored, as on DBAL.
- **The in-memory event store refuses a repeated aggregate version or event id** with `ConcurrencyException`, as
  DBAL's unique indexes do, and records nothing from that append. A flow test that appended the same message id
  twice to one stream gives each event its own `MessageHeaders::MESSAGE_ID`.
- **A negative metadata match keeps the events that lack the field.** **Before:** `MetadataMatcher::withMetadataMatch()`
  with `Operator::NOT_EQUALS` or `Operator::NOT_IN` dropped every event that carried no such metadata key — the
  in-memory store failed any match on a missing key, and DBAL's `NULL NOT IN (…)` / `NULL <> ?` filtered the row —
  while an empty `NOT_IN` list kept them on DBAL and dropped them in memory. **Now:** a missing field satisfies a
  negative match on every store, so `withMetadataMatch('tenant', Operator::NOT_IN, $excludedTenants)` excludes the
  listed tenants and keeps the events with no tenant, whatever the list holds. Positive matches (`EQUALS`, `IN`,
  the comparisons) still never return an event lacking the field. **How to adapt:** if you used a negative match to
  load only the events that *carry* a field, there is no operator for that any more: add an `EQUALS`/`IN` on the
  values you expect, or filter the loaded events yourself.
- **A missing dead-letter table fails fast.** **Before:** `DbalDeadLetterStore` treated a missing table as a transient
  failure and retried storing the failed message three times on an exponential backoff, closing the connection each
  time, before raising the setup instructions. **Now:** the `ConfigurationException` naming the
  `ecotone:migration:database:setup` command is raised on the first attempt; a lost connection is still retried, as
  before. Nothing to adapt.
- **An unknown case name for a pure enum is reported as a `ValueError`.** Converting a scalar to a non-backed enum
  (a header `'PENDING'` into a `#[Header] Status $status` parameter, say) failed with a `MethodInvocationException`
  whose cause was a `ReflectionException` ("Case … does not exist"); the cause is now a `ValueError` reading
  `"PENDING" is not a valid case name for enum Status`, as an unknown value for a backed enum always was. **How to
  adapt:** only code inspecting the previous exception's class changes.
- Internal, nothing to adapt unless you used these directly: the 42 module and builder classes whose
  `create(): static` returned `new self` (`AggregateModule`, `DbalConnectionModule`, `EventSourcingModule`, …) are
  `final`; `MessagingException::create()`, `createWithFailedMessage()` and `createFromPreviousException()` declare
  `static` instead of `self`, so a subclass overriding one declares `static` too; `Ecotone\Messaging\Scheduling\TaskExecutor`
  is gone, `SyncTaskScheduler` and `ScheduledTaskConsumer` take `PollToGatewayTaskExecutor`;
  `Clock::createBasedOnConfig()` is `Clock::createWithFallback(?ClockInterface $clock, ClockInterface $fallbackClock)`,
  the testing module supplying `StaticPsrClock` as the fallback; and `RetryRunner` no longer retries a
  `ConfigurationException`.
- **An aggregate's identifier travels on its events as a string.** **Before:** an aggregate's events carried
  `MessageHeaders::EVENT_AGGREGATE_ID` (`_aggregate_id`) as the identifier's raw PHP value, so an `int`
  `#[Identifier]` recorded a JSON integer, while `EventStore::loadAggregateEvents()` takes the id as a `string`.
  **Now:** a single identifier is cast to its string form when the events are enriched — in the metadata an
  event-sourced aggregate records, on every event store, and on the event messages published after any aggregate's
  save, state-stored ones included; `#[Identifier] int $id = 42` carries `'42'`.
  Ids already recorded as integers stay readable: SQLite and the in-memory store compare metadata by its text form, as
  PostgreSQL, MySQL and MariaDB always did, so an aggregate whose history mixes both forms still loads. A composite
  identifier is unchanged. **How to adapt:** a handler reading the header with `#[Header('_aggregate_id')] int $id`
  declares `string` (or casts), and code comparing `$event->getMetadata()['_aggregate_id']` with `===` against an
  `int` compares against the string.
- **`Message` and `MessageChannel` extend `Stringable`.** **Before:** neither interface declared `__toString()`, yet
  Ecotone interpolates both into exception messages and descriptions, so a channel or message that could not be
  stringified turned the exception being built into `Object of class … could not be converted to string`. Six of
  Ecotone's own channels were such channels. **Now:** `Ecotone\Api\Messaging\Message` and
  `Ecotone\Api\Messaging\MessageChannel` (and so `PollableChannel` and `SubscribableChannel`) extend `Stringable`, and
  every implementation Ecotone ships describes itself. **How to adapt:** a class of yours implementing `Message`,
  `MessageChannel`, `PollableChannel` or `SubscribableChannel` adds `public function __toString(): string` — PHP
  refuses to load it otherwise. A message describes its payload and headers (`(string) $this->getHeaders()` is their
  JSON); a channel returns its name. Internal, nothing to adapt: `MessageHandler`, `MessageHandlerBuilder` and
  `RouteSelector` extend `Stringable` too, which fixes the one crash reachable from an application — a `#[Router]`
  subscribed to a direct channel that already has a handler threw `Object of class … could not be converted to string`.
- **Two handlers on one direct input channel are refused at bootstrap.** **Before:** a `#[Router]`, `#[InternalHandler]`
  or any other handler sharing a direct channel with another handler booted, and the first message to reach the
  channel failed — with the cast error above in 1.x, and in early 2.0 builds with a `WrongHandlerAmountException`. A
  direct channel delivers a message to one handler only, so the configuration could never work. **Now:** bootstrap
  throws `WrongHandlerAmountException` naming the channel and both handlers (`Router - invocation of Class::method`,
  `Service Activator - Class::method`, …) and the two ways out. **How to adapt:** give one of the handlers its own
  input channel, or register the channel with `SimpleMessageChannelBuilder::createPublishSubscribeChannel('<name>')`
  so that each of them receives the message. A channel subscribed by hand at runtime keeps the same refusal on the
  second `subscribe()`.
- `ServiceConfiguration::withSkippedModulePackageNames()` → `withEnabledModulePackageNames()`, and the framework key
  `skippedModulePackageNames` → `enabledModulePackageNames`, each listing packages to load rather than skip (see §1/§9).
- `MessageHeaders::STREAM_BASED_SOURCED` is removed. Projections set it on every projected event, and nothing read
  it: a stream-sourced endpoint is recognised by the `#[StreamBasedSource]` attribute `#[Projection]` extends.
  **How to adapt:** delete any reference to the constant; a handler reading the `streamBasedSourced` header no longer
  receives it.
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
- **A parameter documented as a list receives the list.** An `array` parameter documented `@param string[]`,
  `@param int[]` or `@param array<string>` — as the payload or a `#[Header]`, reached through a bus, a
  `#[BusinessMethod]`, an asynchronous channel or the `EventStore` gateway — failed on any non-empty array with
  `Cannot resolve parameter '…'` and `Reason: Call to a member function accepts() on null`. One documented as a list
  of objects (`@param Order[]`) refused a header already holding `Order`s with
  `Can't convert … from array<Order> to array<Order>`. Both now resolve; elements that are not of the documented type
  are still converted, or refused when no converter exists. If you removed a `@param` annotation or added
  `#[IgnoreDocblockTypeHint]` to get past either error, you can put it back.
- **`#[LogBefore]` / `#[LogAfter]` work.** In 1.x they threw on the first handler call and were unusable; they now log
  the payload (and headers with `logFullMessage: true`) through the configured PSR logger. They moved, with
  `#[LogError]`, to `Ecotone\Api\Attribute\LogBefore`, `Ecotone\Api\Attribute\LogAfter` and `Ecotone\Api\Attribute\LogError` (§13).
- **New, Enterprise: `#[ChannelInterceptor('channelName')]`.** A method with this attribute runs as a pre-send
  interceptor for that exact channel, with the same `changeHeaders` / `precedence` semantics as `#[Before]` /
  `#[Presend]`. In 1.x the attribute existed but did nothing. Nothing to change unless you had it in code expecting it
  to be ignored; without a licence bootstrap throws `LicensingException`.
- **The channel builders' `with…()` methods return the builder you called them on.** `withReceiveTimeout()`,
  `withAutoDeclare()`, `withFinalFailureStrategy()`, `withHeaderMapping()`, `withDefaultTimeToLive()`,
  `withDefaultDeliveryDelay()` and `withDefaultConversionMediaType()` were declared on an internal parent as `: self`,
  so static analysis read `DbalBackedMessageChannelBuilder::create('orders')->withReceiveTimeout(100)` as the internal
  `EnqueueMessageChannelBuilder` parent — a `#[ServiceContext]` declaring `: DbalBackedMessageChannelBuilder` was
  reported as returning the wrong type at phpstan level 3, and a subclass method chained after an inherited one was
  an undefined method. They now return `static`, as do the fluent methods of `AmqpBackedMessageChannelBuilder`,
  `DbalBackedMessageChannelBuilder` and `AmqpStreamChannelBuilder`; `AmqpBackedMessageChannelBuilder::create()`,
  which declared no return type, returns `self`. Runtime behaviour is unchanged. Nothing to change; drop any
  `@var` or baseline entry you added to silence it.
- **`EventSourcingConfiguration::withDefaults()` returns an `EventSourcingConfiguration`.** It was inherited from an
  internal base and built that base, so a `#[ServiceContext]` declaring `: EventSourcingConfiguration` failed with a
  `TypeError`, and chaining `withLoadBatchSize()` or any other `EventSourcingConfiguration` method was a call to an
  undefined method. It now behaves as `createWithDefaults()`.
- **DBAL 3 compatibility layer removed (internal).** With DBAL 4 as the minimum (see the top of this guide),
  `Ecotone\Dbal\Compatibility\QueryBuilderProxy` and `Ecotone\Dbal\Compatibility\SchemaManagerCompatibility` are gone,
  together with the version checks around `getSchemaManager()`, `ArrayParameterType`, `ParameterType` and
  `connect()`/`close()`; Ecotone calls DBAL 4 directly. Both classes were internal (`src/`, never part of `Api/`), so
  there is nothing to change. If you referenced them anyway, use the native methods: `$connection->createQueryBuilder()`
  with its own `executeQuery()` / `executeStatement()` / `fetch*()`, and
  `$connection->createSchemaManager()->tableExists($table)`.

### `#[Fetch]` without an Enterprise licence is refused at bootstrap

**Before:** `#[Fetch]` is an Enterprise feature, but the licence was checked only when the parameter was resolved: the
first message sent to a handler declaring it failed with a `MethodInvocationException` wrapping a
`LicensingException`. An application running without a licence booted normally, and one whose `#[Fetch]` handler was
never called never found out.

**Now:** bootstrap refuses `#[Fetch]` without an Enterprise licence, wherever it is declared — a command, event or
query handler, an asynchronous handler, an aggregate or saga handler, a `#[Before]` or `#[Around]` interceptor, or a
`#[ConsoleCommand]`. The `LicensingException` names the parameter, the method and both ways out:

```
#[Fetch] on $user in App\OrderService::place is available as part of Ecotone Enterprise, and this application runs without an Enterprise licence. Either obtain an Enterprise licence (see https://docs.ecotone.tech/enterprise), or remove #[Fetch] and load App\User in the handler body through its repository, for example a business interface method marked with #[Repository] that returns it.
```

An application that runs open-core today with `#[Fetch]` on a handler nothing calls stops booting.

**How to adapt:** provide the licence key (`licenceKey:` argument of `EcotoneLite`, `ecotone.licenceKey` in Symfony,
`licenceKey` in `config/ecotone.php` in Laravel, `licenceKey:` argument of `EcotoneLite`), or remove
`#[Fetch]` and load the aggregate in the method body through its repository — a business interface method marked
with `#[Repository]` that returns it. A handler nothing calls can simply be deleted.

### `#[ProjectionName]` that cannot be absent is refused at bootstrap without a licence

**Before:** a projection handler reading `#[ProjectionName] string $projectionName` booted without an Enterprise
licence and then failed on every event with a `MethodInvocationException` about the missing `projection.name` header,
which is set only under a licence.

**Now:** bootstrap refuses it with a `LicensingException` when the parameter is neither nullable nor defaulted, on an
event handler or a `#[ProjectionInitialization]`, `#[ProjectionDelete]`, `#[ProjectionFlush]` or `#[ProjectionReset]`
handler. The message names the parameter, the handler and the three ways out:

```
#[ProjectionName] on $projectionName of App\OrderList::onOrderPlaced requires the projection name, which is passed to a projection handler only under an Ecotone Enterprise licence, and this application runs without one: a parameter that is neither nullable nor defaulted would fail on every call. Either declare it as ?string $projectionName = null to receive null without a licence, remove #[ProjectionName] if the handler does not need the name (the projection declares it in #[Projection('order_list')]), or obtain an Enterprise licence (see https://docs.ecotone.tech/enterprise) and pass it with ServiceConfiguration::withLicenceKey().
```

A nullable or defaulted parameter is unchanged: without a licence it receives `null` (or its default), as before.

**How to adapt:** pick one of the three ways out the message names.

### A bare boolean console option switches it on, on Symfony and Laravel

**Before:** a boolean option given without a value — `bin/console ecotone:migration:database:setup --initialize`,
`php artisan ecotone:run orders --stopOnFailure` — reached the command as `null` and was read as its default, so
`--initialize` created no table and exited successfully. Only `--initialize=true` (or `true` from code) worked, while
Ecotone's own missing-table messages print the bare form.

**Now:** a bare boolean option is `true`; `--initialize=false` and leaving the option out keep their meaning.

**How to adapt:** nothing. Deployment scripts that already pass `--initialize=true` behave as before.

### Symfony and Laravel `defaultConnectionExceptionRetry` is applied as configured

**Before:** both integrations passed `maxAttempts` as the back-off multiplier and `multiplier` as a maximum delay in
milliseconds, and set no attempt limit. With the shipped example (`initialDelay: 100, maxAttempts: 3, multiplier: 2`)
the first retry's 100 ms delay already exceeded the "maximum delay" of 2 ms, so a lost connection was never retried;
with `initialDelay: 0` it was retried forever.

**Now:** `initialDelay` and `multiplier` shape an exponential back-off and `maxAttempts` is the number of reconnection
attempts before the consumer gives up.

**How to adapt:** check that the configured values describe the reconnection behaviour you want — they now take effect.
The key itself has since moved to `ServiceConfiguration::withConnectionRetryTemplate()` in a `#[ServiceContext]` method (§12).

### `#[Partitioned]` with `#[ProjectionDeployment(manualKickOff: true)]` is refused at bootstrap

**Before:** a partitioned projection ignored `manualKickOff: true` without a word — each partition initialized and
projected on its first event, exactly as with no `#[ProjectionDeployment]` at all.

**Now:** bootstrap fails with `ConfigurationException`: `Projection 'order_list' combines #[Partitioned] with
#[ProjectionDeployment(manualKickOff: true)], but a partitioned projection initializes each partition on that
partition's first event, so it cannot wait to be kicked off and manualKickOff would be ignored. …`

**How to adapt:** remove `manualKickOff: true` (the projection behaves as it already did), or remove `#[Partitioned]`
to keep a global projection that waits for `ecotone:projection:init <name>`.

### Aggregate snapshots are invalidated when the aggregate folds other events

**Before:** A stored aggregate snapshot was loaded whenever one existed for the instance. Adding or removing an
`#[Apply]` left every stored snapshot loadable, so the aggregate came back folded by the old rules
and silently stayed that way until enough new events accumulated to overwrite it.

**Now:** Every snapshot is written beside a marker recording the fold it was taken with — the aggregate class and
its sorted `#[Apply]` event types — in a sibling collection `aggregate_snapshot_fold_shapes_<Class>`
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


### `DocumentStore` writes name the version they replace, and document-store aggregates are version-checked

**Before:** `DocumentStore::updateDocument()` and `DocumentStore::upsertDocument()` took the collection, the id and
the document, and replaced whatever was stored. Two writers that read the same document both succeeded, and the one
that wrote last silently discarded the other's change. The document-store aggregate repository and the in-memory
repository `EcotoneLite::bootstrapFlowTesting()` uses behaved the same way: a command carrying a stale
`#[TargetVersion]`, or two handlers saving one aggregate at once, overwrote each other.

**Now:** every document has a version. `addDocument()` stores it at version 1, and each write moves it by one.
`updateDocument()` and `upsertDocument()` take a **required** fourth parameter, `int $expectedVersion` — the version
the caller read — and refuse the write with `Ecotone\Api\Messaging\ConcurrencyException` when the document has
moved since. `0` means "must not be stored yet". `DocumentStore::getDocumentVersion()` returns the current version,
`0` for a document that is not stored. A write that should replace whatever is stored says so explicitly:

```php
use Ecotone\Api\Gateway\DocumentStore;

$version = $documentStore->getDocumentVersion('baskets', $userId);
$basket = $documentStore->getDocument('baskets', $userId);
$documentStore->updateDocument('baskets', $userId, $basket->withProduct($productId), $version);

$documentStore->upsertDocument('wallet_balances', $walletId, $balance, DocumentStore::LAST_WRITE_WINS);
```

The parameter is required rather than optional so that no call can skip the check without saying so:
an application that calls these methods fails loudly at the call site after upgrading, instead of keeping 1.x
semantics silently. `addDocument()` is unchanged — adding already means the document must not exist.

**State-stored aggregates:** an aggregate or saga saved through the document-store repository
(`DbalConfiguration::withDocumentStore(enableDocumentStoreStateStoredRepository: true)`), or through the in-memory
repository of a flow test, is now checked against the version it was loaded at:

- **With `#[Version]` on the aggregate**, a save from a stale load — another handler saved it in between, or the
  command's `#[TargetVersion]` names an older version — is refused with a `ConcurrencyException` naming the aggregate,
  the version it was loaded at and the version it is at now.
- **Without `#[Version]`, a document-store aggregate is last-write-wins**, exactly as in 1.x: there is no version to
  compare, so two concurrent saves still overwrite each other. Add `#[Version]` to the aggregate to turn the check on:

  ```php
  use Ecotone\Api\Attribute\Aggregate;
  use Ecotone\Api\Attribute\Version;

  #[Aggregate]
  final class Basket
  {
      #[Version]
      private int $version = 0;
  }
  ```

- **Creating an aggregate under an id that is already stored is refused**, with or without `#[Version]`. In 1.x the
  document-store and in-memory repositories silently replaced the stored aggregate. A flow test that creates the same
  aggregate twice now fails with a `ConcurrencyException`.
- **A Tempest-model aggregate or saga with `#[Version]` is refused at bootstrap.** **Before:** Tempest persisted the
  version, but its database API does not report whether a guarded update matched a row
  (`UpdateQueryBuilder::execute()` returns `?PrimaryKey`, `Database::execute()` returns `void`), so a conflict could
  not be observed and a stale save silently overwrote a newer one while the class promised a check. **Now:** a
  state-stored aggregate or saga that uses `Tempest\Database\IsDatabaseModel` and declares `#[Version]` is a
  `ConfigurationException` when Ecotone boots, naming the class, the version property, why the check cannot work and
  the three ways out. **How to adapt:** remove `#[Version]` and accept last write wins, which is what such a model got
  until now; or stop making the class a Tempest model and store it through the DBAL document-store repository
  (`DbalConfiguration::withDocumentStore(enableDocumentStoreStateStoredRepository: true)`), where `#[Version]` is
  checked; or make it an `#[EventSourcingAggregate]`. A Tempest model without `#[Version]` is unchanged.
- **An Eloquent aggregate with `#[Version]` is version-checked.** **Before:** `#[Version]` on an Eloquent model was
  accepted and had no effect: Ecotone wrote the declared PHP property, which Eloquent never saves, read it back as `0`
  on every load, and concurrent saves overwrote each other — so applications registered a `#[Repository]` of their own
  running `UPDATE … WHERE id = ? AND version = ?`. **Now:** Ecotone's Eloquent repository keeps the `#[Version]`
  property in a column of the same name: `findBy()` sets the property from the column, the first save inserts the row
  at version 1, and every later save moves the column from the version it loaded to the next one with
  `UPDATE … WHERE <key> = ? AND <version column> = ?` before Eloquent's own `save()` writes the changed attributes. A
  save whose loaded version is no longer stored, and a create under
  an id that is already stored, throw `Ecotone\Api\Messaging\ConcurrencyException::forStaleAggregate()`, as every
  other backend does. **How to adapt:** give the table a non-null integer column named after the `#[Version]`
  property (`$table->integer('version')`); a repository of your own written only to get the check can be removed.
  An Eloquent model without `#[Version]` is unchanged: last write wins, and a duplicate id raises Laravel's own
  `UniqueConstraintViolationException`.

A conflict is a technical failure, not a business answer. Retry it by reloading: the command bus and asynchronous
endpoints retry by default when `DbalConfiguration` runs them in a database transaction ([§9](#9-changed-defaults));
without one, send the command again or configure
`InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [ConcurrencyException::class])`. A save made earlier in the same transaction — a command sent from inside the
handler whose own handler saves the same aggregate — fails on every retry; decide both in one handler instead.

**The document store table gains a `version` column.** A table created by Ecotone 1.x is refused on the first write
(and on `getDocumentVersion()`) with a `ConfigurationException` naming the statement to run:

```sql
ALTER TABLE ecotone_document_store ADD COLUMN version INTEGER NOT NULL DEFAULT 1;
```

It is safe to run on a populated table, on PostgreSQL, MySQL, MariaDB and SQLite alike: the default puts every stored
document at version 1, which is what `getDocumentVersion()` then reports and what the next write expects. Reads —
`getDocument()`, `findDocument()`, `getAllDocuments()`, `countDocuments()` — work on the 1.x table before the column is
added. Tables created by 2.0 already have it.

**Unchanged, and stated here because the conformance suite measured it: on MySQL and MariaDB a document id compares
under the database's collation.** Ecotone creates the table without a collation of its own, so `collection` and
`document_id` inherit the database default — `utf8mb4_0900_ai_ci` on MySQL 8.0, `utf8mb4_uca1400_ai_ci` on MariaDB
11.4 — which ignores case and accents. Ids such as `order-a` and `ORDER-A`, or `cafe` and `café`, are then one
document: adding the second is refused with a `DocumentException`, and `findDocument('orders', 'ORDER-A')` returns
`order-a`. MariaDB also ignores trailing spaces. PostgreSQL, SQLite and the in-memory document store keep them apart.
If your ids must stay distinct, convert both columns to a binary collation yourself:

```sql
ALTER TABLE ecotone_document_store
    MODIFY collection VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    MODIFY document_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
```

Neither engine can change a key column's collation in place, so the statement copies the table, and writes to it wait
until it finishes. Afterwards a lookup has to use the id exactly as it was stored. Ecotone does not run it for you.

**The DBAL event store checks `AppendCondition::forAggregate()`.** It used to rely only on its unique index on
aggregate type, id and version, so an append under a stale expected version whose new event carried a version not yet
recorded went through. It now reads the aggregate's current version first, as the in-memory event store always did,
and refuses with the same `ConcurrencyException`. Event-sourced aggregates saved through Ecotone already carried
consecutive versions, so for them nothing changes; an application appending through `EventStore::appendTo()` with a
hand-built `AppendCondition::forAggregate()` may now see the refusal it was always meant to get.

**How to adapt:**

1. Run the `ALTER TABLE` above on every database holding an `ecotone_document_store` table — before deploying 2.0.
2. At every `updateDocument()` / `upsertDocument()` call, pass the version you read with `getDocumentVersion()`, or
   `DocumentStore::LAST_WRITE_WINS` where the document is a cache, a projection row or anything else with a single
   writer.
3. Add `#[Version]` to each document-store aggregate or saga that is changed concurrently.
4. Fix flow tests that create one aggregate id twice.

### Projection state is saved under a version check, not a row lock

**Before:** a projection run loaded its partition's position with `SELECT ... FOR UPDATE` (except on SQLite), so a
second run of the same partition waited for the first. **Now:** the run loads the position without a lock and saves it
with `UPDATE ... WHERE version = ?`. When another run of the same partition saved first, the save matches no row and
the run fails with `ConcurrencyException` ("Projection 'x' (partition: global) was loaded at version 3, but another
run of the same partition saved it first. ..."), and its transaction rolls back, so nothing it projected is kept. For a
synchronous projection the run is the command's transaction, so the command fails; for an asynchronous one, the
message. Both are retried by default where a database transaction wraps them, and an asynchronous projection always
([§9](#9-changed-defaults)), so the retry projects from the position the other run stored. A projection that sends
mail or calls another service from its handlers can do so twice when a run is retried: only what it writes inside the
transaction is rolled back.

**How to adapt:**
- The `ecotone_projection_state` table gains a `version` column. A table created by an earlier 2.0 beta is refused on
  the first projection run (as is one created by 1.x `ProjectionV2`) with a `ConfigurationException` naming the statement:
  `ALTER TABLE ecotone_projection_state ADD COLUMN version INTEGER NOT NULL DEFAULT 0;` It is safe to run on a
  populated table; every partition keeps its position.
- Without a database transaction around the command bus, a synchronous projection's conflict reaches the caller; enable
  `InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [ConcurrencyException::class])` or send
  the command again.
- A `#[StateStorage]` class implementing `ProjectionStateStorage`: `loadPartition()` no longer takes `bool $lock`, and
  `savePartition()` saves only when the stored `ProjectionPartitionState::$version` is the one the state was loaded at,
  storing the next one, and throws `ConcurrencyException::forStaleProjection()` otherwise.

### Framework headers that never leave the process are renamed to `ecotone_{module}_{feature}_{action}`

**Before:** the headers Ecotone adds for its own use inside one process were named in five styles —
`ecotone.modelling.called_aggregate_class`, `projection.partitionKey`, `dbal_acknowledge`, `replyChannel`,
`ecotone.dbal.business_method.sql`. **Now:** each is lower snake_case under the reserved `ecotone_` prefix. None of
these headers is written to a queue, the outbox, the dead letter table or the event store, so no stored or in-flight
message carries one, and nothing needs migrating. Code that reads them through their constant is unaffected.

| Constant | 1.x value | 2.0 value |
|---|---|---|
| `MessageHeaders::REPLY_CHANNEL` | `replyChannel` | `ecotone_messaging_reply_channel` |
| `MessageHeaders::REPLY_CONTENT_TYPE` | `replyContentType` | `ecotone_messaging_reply_content_type` |
| `MessageHeaders::CONSUMER_ACK_HEADER_LOCATION` | `consumerAcknowledgeCallbackHeader` | `ecotone_messaging_consumer_acknowledge_header` |
| `MessageHeaders::CONSUMER_POLLING_METADATA` | `consumerPollingMetadata` | `ecotone_messaging_consumer_polling_metadata` |
| `KafkaHeader::ACKNOWLEDGE_HEADER_NAME` | `kafka_acknowledge` | `ecotone_kafka_consumer_acknowledge` |
| the DBAL, Enqueue (AMQP, SQS, Redis), Laravel queue and in-memory acknowledge headers | `dbal_acknowledge`, `enqueue_acknowledge`, `ecotone.laravel.acknowledge`, `ecotone.in_memory_queue.ack`, `ecotone.in_memory_shared.ack` | `ecotone_dbal_consumer_acknowledge`, `ecotone_enqueue_consumer_acknowledge`, `ecotone_laravel_consumer_acknowledge`, `ecotone_in_memory_queue_consumer_acknowledge`, `ecotone_in_memory_shared_consumer_acknowledge` |
| the headers `#[ProjectionState]` and `#[ProjectionName]` read | `projection.state`, `projection.name` | `ecotone_projection_state`, `ecotone_projection_name` |
| the other projection execution headers | `projection.event_name`, `projection.live`, `projection.partitionKey`, `projection.canInitialize`, `projection.manual_initialization`, `projection.shouldReset` | `ecotone_projection_event_name`, `ecotone_projection_live`, `ecotone_projection_partition_key`, `ecotone_projection_can_initialize`, `ecotone_projection_manual_initialization`, `ecotone_projection_should_reset` |
| a console command's parameters, as headers | `ecotone.oneTimeCommand.{parameter}` | `ecotone_console_one_time_command_{parameter}` |
| aggregate, decision model, DBAL business method, document store, dead letter gateway and serializer plumbing | `ecotone.modelling.*`, `ecotone.modeling.test_setup.*`, `ecotone.eventSourcing.tagging.*`, `ecotone.dbal.business_method.*`, `ecotone.dbal.documentStore.*`, `ecotone.dbal.deadletter.limit` / `.offset`, `ecotone.serializer.*`, `ecotone.consoleCommand.name` | the same names as `ecotone_modelling_*`, `ecotone_modelling_test_setup_*`, `ecotone_event_sourcing_tagging_*`, `ecotone_dbal_business_method_*`, `ecotone_dbal_document_store_*`, `ecotone_dbal_deadletter_limit` / `_offset`, `ecotone_serializer_*`, `ecotone_console_command_name` |

`MessageHeaders::CHANNEL_SEND_RETRY_NUMBER` and `MessageHeaders::TEMPORARY_SPAN_CONTEXT_HEADER` are removed: nothing
set either header.

Headers that do leave the process keep their names: `id`, `timestamp`, `correlationId`, `parentId`,
`polledChannelName`, `inboundRequestChannel`, `routingSlip`, `ecotone.distributed.*`, `aggregate.id`,
`ecotone_retry_number`, and the dead letter's `exception-*` headers.

**How to adapt:** only code that wrote one of the old values as a string literal changes — a `#[Header('replyChannel')]`,
a `#[Header('projection.name')]` in place of `#[ProjectionName]`, or an expression such as
`headers['ecotone.oneTimeCommand.userId']`. Use the constant, or the 2.0 value from the table.

### Renamed parameters and methods that have no entry of their own

**Before:** each name in the left column worked in 1.x. **Now:** each is renamed with no alias, or changed as
described. A call that uses an old name fails loudly — PHP's `Unknown named parameter $…` for a named argument,
`Call to undefined method …` for a method — and none of the renames changes what the call does.

| 1.x | 2.0 |
|---|---|
| `CommandHandler::getIdentifierMapping()`, `EventHandler::getIdentifierMapping()` (read by code that inspects the attribute instance, such as a custom module) | `getAggregateIdentifierMapping()` |
| `class OrderStream extends Stream` — `#[Stream]` could be extended | `Ecotone\Api\EventSourcing\Stream` is `final`; a subclass fails to load with PHP's `Class … cannot extend final class Ecotone\Api\EventSourcing\Stream`. Write `#[Stream('orders')]` where the subclass was used |
| `Duration::minutes(seconds: 5)` — the parameter was misnamed and always meant minutes | `Duration::minutes(minutes: 5)`; positional `Duration::minutes(5)` is unchanged |
| `EventSourcedRepository::findBy($aggregateClassName, $identifiers, fromAggregateVersion: 3)` | `findBy(…, fromVersion: 3)`; an implementing class keeps working whatever it names the parameter |
| `DbalDeadLetterBuilder::createList()`, `createShow()`, `createCount()`, `createDelete()`, `createDeleteAll()`, `createReply()`, `createReplyAll()` — each `($referenceName, $connectionReferenceName)` | `createList($referenceName)` and so on, with `createReplay()` / `createReplayAll()` for the last two (§15). The connection is no longer an argument: the builder addresses the dead letter registered under `$referenceName`, which carries its own connection. PHP ignores a surplus positional argument, so a 1.x positional call still runs and its connection argument is dropped; a named `connectionReferenceName:` fails |
| `DbalDeadLetterBuilder::createStore($connectionReferenceName)` | `createStore($referenceName, $inputChannelName)`; a 1.x one-argument call fails with PHP's `Too few arguments` |
| `DistributedBus::sendMessage($targetServiceName, targetChannelName: 'ticket.create', …)` | `sendMessage($targetServiceName, routingKey: 'ticket.create', …)`, the name every other `DistributedBus` method gives its routing argument; positional calls are unchanged |

**How to adapt:** rename the call. `DbalDeadLetterBuilder`'s factories are the dead letter module's own plumbing; an
application reaches the dead letter through `DeadLetterGateway`, and through the builder only for its channel-name
constants and `getChannelName()`, which are unchanged — except `REPLAY_CHANNEL` and `REPLAY_ALL_CHANNEL`, whose values
end in `replay` and `replayAll` (§15).

### A tracer provider that cannot flush no longer breaks the first bus call
### Getters that only returned a property are `public readonly` properties

**Before:** these `Api` classes exposed a value through a method whose body was only `return $this->property;`.
**Now:** the method is removed and the property it returned is `public readonly`, under the name it already had —
read `$attribute->property` instead of calling the method. Writing to it fails with PHP's
`Cannot modify readonly property`; that includes `EventHandler::$listenTo` and `TargetIdentifier::$identifierName`,
which were already public and writable. A call to a removed method fails with PHP's `Call to undefined method`.

| Class | 1.x | 2.0 |
|---|---|---|
| `Ecotone\Api\Dbal\Attribute\DbalParameter` | `getConvertToMediaType()`, `isIgnored()` | `$convertToMediaType`, `$ignored` |
| `Ecotone\Api\Dbal\Attribute\DbalQuery` | `getFetchMode()` | `$fetchMode` |
| `Ecotone\Api\Attribute\AddHeader` and its subclasses `Delayed`, `TimeToLive`, `Priority`, `ContentType` | `getHeaderValue()` | `$headerValue` |
| `Ecotone\Api\Attribute\Asynchronous` | `getAsynchronousExecution()` | `$asynchronousExecution` |
| `Ecotone\Api\Attribute\ConsoleCommand` | `getDescription()` | `$description` |
| `Ecotone\Api\Attribute\Deduplicated` | `getDeduplicationHeaderName()`, `getTrackingName()` | `$deduplicationHeaderName`, `$trackingName` |
| `Ecotone\Api\Attribute\Distributed` | `getDistributionReference()` | `$distributionReference` |
| `Ecotone\Api\Attribute\Environment` | `getNames()` | `$names` |
| `Ecotone\Api\Attribute\EventHandler` | `getListenTo()` | `$listenTo` |
| `Ecotone\Api\Attribute\EventSourcingAggregate`, `EventSourcingSaga` | `hasInternalEventRecorder()` | `$withInternalEventRecorder` |
| `Ecotone\Api\Attribute\IdentifierMethod` | `getIdentifierPropertyName()` | `$identifierPropertyName` |
| `Ecotone\Api\Attribute\InternalHandler` and its subclasses `OnConsumerStop`, `OnTenantActivation`, `OnTenantDeactivation` | `isChangingHeaders()`, `isRequiresReply()` | `$changingHeaders`, `$requiresReply` |
| `Ecotone\Api\Attribute\MessageGateway`, `BusinessMethod` | `getRequestChannel()`, `getReplyTimeoutInMilliseconds()` | `$requestChannel`, `$replyTimeoutInMilliseconds` |
| `Ecotone\Api\Attribute\PropagateHeaders` | `doPropagation()` | `$doPropagation` |
| `Ecotone\Api\Attribute\RelatedAggregate` | `getClassName()` | `$className` |
| `Ecotone\Api\Attribute\Revision` | `getRevision()` | `$revision` |
| `Ecotone\Api\Attribute\Router` | `isResolutionRequired()` | `$isResolutionRequired` |
| `Ecotone\Api\Attribute\Scheduled` | `getRequestChannelName()` | `$requestChannelName` |
| `Ecotone\Api\Attribute\TargetIdentifier` | `getIdentifierName()` | `$identifierName` |
| `Ecotone\Api\Attribute\Version` | `isAutoIncreased()` | `$autoIncrease` |
| `Ecotone\Api\EventSourcing\Event` — what `EventStore::load()` returns | `getEventName()`, `getMetadata()` | `$eventName`, `$metadata` |
| `Ecotone\Api\EventSourcing\EventCriteria` | `tags()`, `eventTypes()`, `tagSequenceLowerBound()` | `$tags`, `$eventTypes`, `$tagSequenceLowerBound` |
| `Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration` | `filterOnlyTagNames()`, `snapshottedModelClasses()`, `classesWithoutCounterTag()` | `$filterOnlyTagNames`, `$snapshottedModelClasses`, `$classesWithoutCounterTag` |
| `Ecotone\Api\Lite\Test\FlowTestSupport` | `getMessagingTestSupport()` | `$testSupportGateway` |
| `Ecotone\Api\Console\InMemoryConsoleProgressBar` | `getMaxSteps()` | `$maxSteps` |
| `Ecotone\Api\Messaging\ErrorContext` | `getFailedTimestamp()`, `getStackTrace()`, `getFile()`, `getLine()` | `$failedTimestamp`, `$stackTrace`, `$file`, `$line` |
| `Ecotone\Api\Logging\LoggingLevel` | `isFullMessageLog()` | `$logFullMessage` |
| `Ecotone\Api\ExtensionObject\MediaType` | `getSubtype()`, `getParameters()` | `$subtype`, `$parameters` |
| `Ecotone\Api\ExtensionObject\ConnectionReference` and every connection reference extending it | `getConnectionName()` | `$connectionName` |
| `Ecotone\Api\ExtensionObject\CombinedMessageChannel` | `getCombinedChannels()` | `$combinedChannels` |
| `Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration` | `getDeadLetterQueueChannel()`, `getDelayedRetryTemplate()` | `$deadLetterChannel`, `$delayedRetryTemplate` |
| `Ecotone\Api\ExtensionObject\InstantRetryConfiguration` | `isEnabledForCommandBus()`, `getCommandBusRetryTimes()`, `getCommandBuExceptions()`, `isEnabledForAsynchronousEndpoints()`, `getAsynchronousRetryTimes()`, `getAsynchronousExceptions()` | `$isEnabledForCommandBus`, `$commandBusRetryTimes`, `$commandBuExceptions`, `$isEnabledForAsynchronousEndpoints`, `$asynchronousRetryTimes`, `$asynchronousExceptions` |
| `Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder` | `isAutoAcked()` | `$isAutoAcked` |
| `Ecotone\Api\Amqp\AmqpBinding` | `toEnqueueBinding()` | `$enqueueBinding` |
| `Ecotone\Api\Amqp\AmqpStreamChannelBuilder` | `getMessageGroupId()` | `$messageGroupId` |
| `Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManagerRegistry` | `getAllManagers()` | `$managersByConnectionReferenceName` |
| `Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration` | `getTenantHeaderName()`, `getTenantToConnectionMapping()`, `getDefaultConnectionName()` | `$tenantHeaderName`, `$tenantToConnectionMapping`, `$defaultConnectionName` |
| `Ecotone\Api\Kafka\KafkaBrokerConfiguration` | `getBootstrapServers()`, `isSetupForTesting()` | `$bootstrapServers`, `$setupForTesting` |
| `Ecotone\Api\Kafka\KafkaConsumer` | `getGroupId()`, `getCommitIntervalInMessages()` | `$groupId`, `$commitIntervalInMessages` |
| `Ecotone\Api\Kafka\KafkaPublisherConfiguration` | `getBrokerConfigurationReference()`, `getDefaultTopicName()` | `$brokerConfigurationReference`, `$defaultTopicName` |
| `Ecotone\Api\Laravel\LaravelConnectionReference` | `getLaravelConnectionName()` | `$laravelConnectionName` |
| `Ecotone\Api\EventSourcing\EventSourcingConfiguration` | `getEventStoreReferenceName()` | `$eventStoreReferenceName` |
| `Ecotone\Api\Tempest\TempestConnectionReference` | `getConfigTag()` | `$configTag` |
| `Ecotone\Api\ExtensionObject\ChannelInitializationConfiguration` | `isAutomaticChannelInitializationEnabled()` | `$automaticChannelInitialization` |
| `Ecotone\Api\EventSourcing\MetadataMatcher` | `data()` | `$data` |
| `Ecotone\Api\Messaging\BatchMessage` | `getEntries()` | `$entries` |
| `Ecotone\Api\ExtensionObject\DistributedServiceMap` | `getCommandMapping()`, `getDistributedBusAnnotations()` | `$commandMapping`, `$distributedBusAnnotations` |
| `Ecotone\Api\DataProtection\DataProtectionConfiguration` | `keys()` | `$keys` |
| `Ecotone\Api\ExtensionObject\TestConfiguration` | `isFailingOnCommandHandlerNotFound()`, `isFailingOnQueryHandlerNotFound()` (merged into one flag, see §7), `getPollableChannelMediaTypeConversion()`, `getChannelToConvertOn()`, `getSpiedChannels()`, `isInMemoryConsumerPositionTrackerEnabled()`, `isSettingUpDatabaseTablesOnBootstrap()` | `$failOnNotFoundHandler`, `$pollableChannelMediaTypeConversion`, `$channelToConvertOn`, `$spiedChannelNames`, `$inMemoryConsumerPositionTracker`, `$databaseTablesSetUpOnBootstrap` |
| `Ecotone\Api\ExtensionObject\ExecutionPollingMetadata` | `getCron()`, `getHandledMessageLimit()`, `getMemoryLimitInMegabytes()`, `getExecutionTimeLimitInMilliseconds()`, `getStopOnError()`, `getFinishWhenNoMessages()`, `getExecutionAmountLimit()` | `$cron`, `$handledMessageLimit`, `$memoryLimitInMegabytes`, `$executionTimeLimitInMilliseconds`, `$stopOnError`, `$finishWhenNoMessages`, `$executionAmountLimit` |
| `Ecotone\Api\ExtensionObject\ServiceConfiguration` | `getServiceName()`, `getEnvironment()`, `getNamespaces()`, `getCacheDirectoryPath()`, `getLoadedCatalog()`, `failFast()`, `isFailingFast()`, `getLicenceKey()`, `getConnectionRetryTemplate()`, `getExtensionObjects()`, `getDefaultMemoryLimitInMegabytes()`, `getDefaultErrorChannel()`, `getDefaultSerializationMediaType()`, `getPollableEndpointAnnotations()` | `$serviceName`, `$environment`, `$namespaces`, `$cacheDirectoryPath`, `$loadCatalog`, `$failFast` (both methods), `$licenceKey`, `$connectionRetryTemplate`, `$extensionObjects`, `$defaultMemoryLimitInMegabytes`, `$defaultErrorChannel`, `$defaultSerializationMediaType`, `$pollableEndpointAnnotations` |
| `Ecotone\Api\ExtensionObject\PollingMetadata` | `getCron()`, `getHandledMessageLimit()`, `getMemoryLimitInMegabytes()`, `getExecutionTimeLimitInMilliseconds()`, `getExecutionAmountLimit()`, `getConnectionRetryTemplate()`, `isErrorChannelEnabled()`, `isStoppedOnError()`, `isWithSignalInterceptors()`, `getMaxMessagePerPoll()`, `getTriggerReferenceName()`, `getTaskExecutorName()`, `getFixedRateInMilliseconds()`, `getInitialDelayInMilliseconds()`, `finishWhenNoMessages()`, `getFixedRateExpression()`, `getCronExpression()`, `getPolledChannelName()` | `$cron`, `$handledMessageLimit`, `$memoryLimitInMegabytes`, `$executionTimeLimitInMilliseconds`, `$executionAmountLimit`, `$connectionRetryTemplate`, `$isErrorChannelEnabled`, `$stopOnError`, `$withSignalInterceptors`, `$maxMessagePerPoll`, `$triggerReferenceName`, `$taskExecutorName`, `$fixedRateInMilliseconds`, `$initialDelayInMilliseconds`, `$finishWhenNoMessages`, `$fixedRateExpression`, `$cronExpression`, `$polledChannelName` |
| `Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration` | `isDoctrineORMRepositoriesEnabled()`, `getDoctrineORMRepositoryConnectionReference()`, `getDoctrineORMClasses()`, `isDeduplicatedEnabled()`, `minimumTimeToRemoveMessageFromDeduplication()`, `deduplicationRemovalBatchSize()`, `isDeduplicationInMemory()`, `isDeadLetterEnabled()`, `isDeadLetterInMemory()`, `isTransactionOnAsynchronousEndpoints()`, `isTransactionOnCommandBus()`, `isTransactionOnConsoleCommands()`, `getDefaultConnectionReferenceNames()`, `isClearObjectManagerOnAsynchronousEndpoints()`, `isClearAndFlushObjectManagerOnCommandBus()`, `isClearAndFlushObjectManagerOnProjectionBatch()`, `isFlushingDuringPersisting()`, `isEnableDbalDocumentStore()`, `getDbalDocumentStoreReference()`, `getDocumentStoreConnectionReference()`, `isInMemoryDocumentStore()`, `isEnableDocumentStoreStateStoredRepository()`, `getDocumentStoreRelatedAggregates()`, `getDisabledTransactionsOnAsynchronousEndpointNames()`, `isConsumerPositionTrackingEnabled()`, `getConsumerPositionTrackingConnectionReference()` | `$enableDoctrineORMRepositories`, `$doctrineORMRepositoryConnectionReference`, `$doctrineORMClasses`, `$deduplicatedEnabled`, `$minimumTimeToRemoveMessageInMilliseconds`, `$deduplicationRemovalBatchSize`, `$deduplicationInMemory`, `$deadLetterEnabled`, `$deadLetterInMemory`, `$transactionOnAsynchronousEndpoints`, `$transactionOnCommandBus`, `$transactionOnConsoleCommands`, `$defaultConnectionReferenceNames`, `$clearObjectManagerOnAsynchronousEndpoints`, `$clearAndFlushObjectManagerOnCommandBus`, `$clearAndFlushObjectManagerOnProjectionBatch`, `$flushDuringPersisting`, `$enableDbalDocumentStore`, `$dbalDocumentStoreReference`, `$documentStoreConnectionReference`, `$inMemoryDocumentStore`, `$enableDocumentStoreStateStoredRepository`, `$documentStoreRelatedAggregates`, `$disableTransactionsOnAsynchronousEndpointNames`, `$consumerPositionTrackingEnabled`, `$consumerPositionTrackingConnectionReference` |

**How to adapt:** only code that reads these objects is affected — a custom module, an interceptor that inspects the
attribute instance, or a test. Replace each call with the property in the right column. Constructing these objects is unchanged.

### Four `ServiceConfiguration` methods return a copy instead of changing the instance

**Before:** `ServiceConfiguration` mixed two kinds of `with*()`. Nine returned a changed copy and left the instance
alone; four — `withConnectionRetryTemplate()`, `withLicenceKey()`, `withEnabledModulePackageNames()` and
`withConsumerMemoryLimit()` — changed the instance they were called on and returned it. So a call written as a
statement, `$configuration->withLicenceKey('...');`, kept its change for those four and silently lost it for the
other nine.

**Now:** all thirteen return a changed copy, and the instance they are called on is never changed — its properties
are `readonly`. A statement-form call to one of the four above still runs, but its value is silently lost: the
licence key, the enabled module packages, the consumer memory limit or the connection retry template you set never
reaches the configuration Ecotone uses.

**How to adapt:** assign the result, as the other `with*()` methods always required — chain the calls, or
`$configuration = $configuration->withConsumerMemoryLimit(512);`. Configuration returned from a `#[ServiceContext]` method
as a chained expression is unaffected. (`withLicenceKey()` and `withEnabledModulePackageNames()` have since left
`ServiceConfiguration` for the framework configuration and the `EcotoneLite` parameters, §12.)

### `ServiceConfiguration`, `DbalConfiguration` and `DataProtectionConfiguration` are `final`

**Before:** the three classes were open to extension, and a subclass could add state beside the configuration's own
or override a `with*()` method.

**Now:** all three are `final`, and their constructors are `private` behind the named factories
(`ServiceConfiguration::createWithDefaults()`, `DbalConfiguration::createWithDefaults()`,
`DbalConfiguration::createForTesting()` and `DataProtectionConfiguration::create()`). Extending one is a fatal error
at compile time. Their `with*()` methods return `new self(...)` rather than `new static(...)`, so a copy is always
the class itself.

**How to adapt:** nothing, unless you extended one. A subclass that only added configuration calls becomes a
`#[ServiceContext]` method returning the chained configuration. A subclass that added state of its own becomes a
separate extension object, registered beside the configuration rather than inside it.

### `AmqpQueue` and `AmqpExchange` name their mutators `set*()`

**Before:** both classes named nine mutators `with*()` — `withArgument()`, `withDurability()`,
`withAutoDeletion()`, `withExclusivity()`, `withDeadLetterExchangeTarget()` and
`withDeadLetterForDefaultExchange()` — although each one **changes the instance** and returns
`$this`. The name said a copy; the method mutated. Ecotone's own delay strategy relied on that:
`$queue->withArgument('x-message-ttl', $delay);` as a bare statement.

**Now:** each is `set*()`, under the same behaviour — it still mutates and still returns `$this`,
so chained calls are unchanged. `AmqpQueue::createWith('orders')->setDurability(true)` reads as
what it does.

**How to adapt:** rename the call. A call to the old name fails with PHP's `Call to undefined
method`, so nothing changes silently. This is the opposite trade from
`PollingMetadata` above, where the methods returned a copy and the `set*()` name lied in the
other direction; both now say what they do. Note these two classes were deliberately **not** made
immutable: converting them would have made a statement-form call a silent no-op, with no error
PHP can raise before 8.5's `#[\NoDiscard]`.

### `PollingMetadata`'s `set*()` methods are `with*()`

**Before:** `PollingMetadata` named its nineteen copy methods `set*()` — `setCron()`, `setHandledMessageLimit()`,
`setStopOnError()` and the rest — although each returned a changed copy and left the instance alone. A name that
reads as a mutator invites `$metadata->setStopOnError(false);` as a statement, which silently lost the change.
**Now:** each is named `with*()`, as every other copy method in the `Api` is, and the `set*()` names are gone with
no alias; calling one fails with PHP's `Call to undefined method`. What each method does is unchanged.

| 1.x | 2.0 |
|---|---|
| `setStopOnError()` | `withStopOnError()` |
| `setCron()` | `withCron()` |
| `setErrorChannelName()` | `withErrorChannelName()` |
| `setMaxMessagePerPoll()` | `withMaxMessagePerPoll()` |
| `setTriggerReferenceName()` | `withTriggerReferenceName()` |
| `setTaskExecutorName()` | `withTaskExecutorName()` |
| `setEnabledErrorChannel()` | `withEnabledErrorChannel()` |
| `setFixedRateInMilliseconds()` | `withFixedRateInMilliseconds()` |
| `setInitialDelayInMilliseconds()` | `withInitialDelayInMilliseconds()` |
| `setConnectionRetryTemplate()` | `withConnectionRetryTemplate()` |
| `setExecutionTimeLimitInMilliseconds()` | `withExecutionTimeLimitInMilliseconds()` |
| `setHandledMessageLimit()` | `withHandledMessageLimit()` |
| `setMemoryLimitInMegaBytes()` | `withMemoryLimitInMegaBytes()` |
| `setSignalInterceptors()` | `withSignalInterceptors()` |
| `setExecutionAmountLimit()` | `withExecutionAmountLimit()` |
| `setFinishWhenNoMessages()` | `withFinishWhenNoMessages()` |
| `setPolledChannelName()` | `withPolledChannelName()` |
| `setFixedRateExpression()` | `withFixedRateExpression()` |
| `setCronExpression()` | `withCronExpression()` |

**How to adapt:** rename the call. A `set*()` call written as a statement was already losing its change in 1.x; assign
the `with*()` result instead.

### Forced trace flushing refuses a tracer provider that cannot flush

**Before:** with the `tracing` package loaded, `TracerProviderInterface` bound to an API-level provider such as
OpenTelemetry's `NoopTracerProvider` let the application boot, and the first command, query or distributed bus call
died with `Call to undefined method OpenTelemetry\API\Trace\NoopTracerProvider::forceFlush()`, after its handler
had run.

**Now:** the forced flush, which `TracingConfiguration` turns on by default for bus calls and asynchronous messages,
flushes only a provider that can — an SDK one. Any other provider handles messages normally and is not flushed.

**How to adapt:** nothing. An in-memory or noop provider, which is what a test set-up binds, now works with the
forced flush left on. Bind an SDK tracer provider (`OpenTelemetry\SDK\Trace\TracerProvider`) when you want spans
actually flushed.

### Tracing is part of Core, and OpenTelemetry is one of its exporters

**Before:** every span was produced by the `ecotone/open-telemetry` package: `TracerInterceptor` around the buses and
handlers, `TracingChannelInterceptor` on every pollable channel, and `AddSpanEventLogger` turning log lines into span
events. The trace context travelled between processes as a JSON `ecotoneTracingCarrier` header, the parent of a
synchronous span came from OpenTelemetry's ambient context, and without that package there was no trace at all.

**Now:** Core produces the spans. `Ecotone\Api\Tracing` holds the model — `Span`, `SpanKind`, `SpanStatus`,
`SpanEvent` — plus the `SpanRecorder` seam and an `InMemorySpanRecorder`; `TracingConfiguration::createWithDefaults()
->withSpanRecorder($referenceName)` names the recorders that receive every ended span. The trace context travels as
the W3C `traceparent` and `tracestate` headers (`MessageHeaders::TRACEPARENT`, `MessageHeaders::TRACESTATE`), which
are framework headers: they are not copied into the next message as user metadata, are not reported as span
attributes, and a root span continues the trace a message already carries, so a command sent with a `traceparent`
header from an HTTP request becomes a child of that request's span. Span kinds, the message attributes and the
`exception` event are unchanged. Span names follow the OpenTelemetry messaging convention, `{operation} {target}`,
short and in lower case, with the detail in attributes: a bus call is `send command`, `send event`, `send query` or
`send distributed`; a channel send is `send <channel>` and its consumption `receive <channel>`; a handler is
`process <Class::method>` with the short class name, the fully qualified one in `code.function.name`; a projection
run is `process <projection>`. Three spans are added. Every route the event bus resolves is a PRODUCER span,
`route <endpointId>` or `route <projection>`, between the `send event` span and the handler it leads to, synchronous
or asynchronous, so a trace shows which handlers an event was dispatched to and a failing synchronous handler fails
its route. An event-driven projection run is a CONSUMER span. And every span carries the OpenTelemetry messaging
attributes: `messaging.system` is `ecotone`, `messaging.operation.type` is `send` for buses, routes and channel sends
and `process` for handlers, consumers and projections, `messaging.destination.name` names the route or channel, and
`ecotone.endpoint.id` or `ecotone.projection.name` name the handler; the names are constants on
`Ecotone\Api\Tracing\SpanAttribute`. Every scalar user header of the message is a span attribute as well, unless
`TracingConfiguration::createWithDefaults()->withSpanAttributesFromHeaders('tenant', 'orderId')` names the ones to
report, which an application holding tokens or personal data in headers should do; a header whose name starts with
`ecotone.`, `messaging.` or `code.` is never reported, so a message cannot forge the attributes the framework sets.
A flow entered through an application's own `#[MessageGateway]` or `#[OrchestratorGateway]` is traced from that
call, `send <requestChannel>` or `send orchestrator`, so an orchestrator's steps share one trace. The tracing interceptors are registered only when a recorder is configured or a
tracing package hands over its `Tracer`, so an application without tracing pays nothing. `ecotone/open-telemetry`
becomes an implementation of that `Tracer`: it starts live SDK spans with the same ids Core reports, keeps the
forced flush and the Dynamic Consistency Boundary spans, and records nothing when a flow test is given no
`TracerProviderInterface`, in which case Core's own tracer serves the configured recorders. Its configuration moved
to `Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration`; the never-settable tracing level is gone.
`MessageHeaders::TEMPORARY_SPAN_CONTEXT_HEADER`, which nothing set, is removed.

**How to adapt:** rename dashboards and alerts keyed on the 1.x span names: `Command Bus` is `send command`,
`Event Bus` is `send event`, `Query Bus` is `send query`, `Sending to Channel: orders` is `send orders`,
`Receiving from channel: orders` is `receive orders`, `Command Handler: App\OrderService::place` is
`process OrderService::place`, and `Projection: orders` is `process orders`. A trace tree asserted in a test gains
the `route` level under `send event`; give the handler an explicit `endpointId` to make that span's name stable. Rename `Ecotone\OpenTelemetry\Configuration\TracingConfiguration` to
`Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration`; `withForceFlushOnBusExecution()` and
`withForceFlushOnAsynchronousMessageHandled()` keep their names. A consumer outside Ecotone that read the
`ecotoneTracingCarrier` header reads `traceparent` instead, which any OpenTelemetry propagator already does. To
assert on spans in a flow test without the OpenTelemetry SDK, register an `InMemorySpanRecorder` as a service and
name it in `TracingConfiguration`:

```php
$recorder = new InMemorySpanRecorder();
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderService::class],
    [new OrderService(), InMemorySpanRecorder::class => $recorder],
    [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
);
$ecotone->sendCommand(new PlaceOrder('1'));
$recorder->recordedSpans(); // Span[] with traceId, spanId, parentSpanId, name, kind, status, attributes, events
```

### Flow tracking answers "has it completed yet?" (Enterprise, `ecotone/dbal`)

**Before:** an application that wrote through a command and then read through an asynchronous projection had no way
to know when the projection had caught up: it polled its own read model, or guessed with a delay. Spans existed only
in an external collector, after the fact.

**Now:** registering `Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration::createWithDefaults()` from a
`#[ServiceContext]` method tracks every flow a message starts: the handlers, routes, channels and projections it
reaches, synchronously or asynchronously, across processes. What it tracks is kept in one table, `ecotone_tracing`,
created by `ecotone:migration:database:setup`; a flow test given no connection keeps it in memory.
Two features are on by default and each can be turned off while the flows are still tracked:

- **`TracingGateway`** has three methods. `isCompletedFor($tracingId)` tells whether the flow is complete, and
  `isCompletedFor($tracingId, $destination)` whether one `endpointId`, projection name or class is.
  `isCompletedWithin($tracingId, $timeout, $destination = null)` polls until that holds and returns `true`, or
  returns `false` once the timeout passed; it never throws for a flow that is late. `fetchFlow($tracingId)` returns
  a `Flow` with `isCompleted`, `pending` (the handlers and channels still waited for), `reached` (the destinations
  the flow was sent to or processed by) and `steps` (its stored spans, in the order they started), or `null` for a
  flow nobody recorded, so a `false` is explained by reading the flow rather than by an exception.
  A destination is complete once it processed every message the flow sent it and nothing still pending upstream of
  it could send it another: only work its own handling caused, through a new message, may still be pending. A
  message it forwards unchanged, through an output channel, is waited for too, since it carries the same message id
  as an event's copy queued for a sibling handler. A class stands for everything in it the flow can reach: a
  `#[Projection]` class for its projection, any other class for every handler method in it, endpoint id or not, so
  `isCompletedWithin($tracingId, $timeout, UsersListProjection::class)` needs no name to be repeated, and the class
  completes once every one of its handlers the flow reached ran. A class with no handler or projection is refused at
  once. Waiting blocks the calling process while it polls, so a timeout above 15 minutes is refused, pointing to
  `#[OnFlowStepCompleted]`: a flow that takes longer is reacted to as its steps commit, not waited for.
  `withTracingGateway(false)` turns it off; the gateway stays injectable and every call names that setting.
- **Notifications**: a method with `#[OnFlowStepCompleted]` receives a `FlowStepCompleted` for every step of the
  flow whose unit of work committed, a bus call, a handler run, a route, a message sent or received, with the step's
  span in `$event->step`. To react to the whole flow, or to one destination, the subscriber asks
  `TracingGateway::isCompletedFor()`: it is called after its unit of work committed, so the part of the flow that
  commits last always sees the flow complete. When two parts commit at the same moment, both can see it complete, so
  a reaction to completion is delivered at least once. `withNotifications(false)` turns notifications off, and an
  application that still declares the attribute is refused at bootstrap.

The flow is named by the **`tracingId`** header, `MessageHeaders::TRACING_ID`: a UUID or 32 hexadecimal characters
the application chooses and sends with the command, then passes to `TracingGateway`. Ecotone turns it into the W3C
`traceparent` of the first span, so a command that already carries a `traceparent`, as one from an instrumented HTTP
request does, is tracked by that trace id instead; a message carrying both is refused, because it is not clear which
one names the flow, and a `tracingId` in neither format is refused naming the expected one. A `tracingId` names where
a flow starts, so one passed to a bus or gateway called from inside a handler is refused, naming the flow the call
already belongs to. A handler reads the id of
the flow it runs in from `Ecotone\Api\Tracing\CurrentTrace::tracingId()`.

A flow starts at a command, event or query bus call, a distributed bus send, an application or orchestrator gateway
call, or a consumer receiving a message that carries no trace, such as one from an application without tracing or a
scheduled poll. A flow is complete once the work that started it ended, every route it sent has an ended handler, and every message
it sent to a channel was consumed from that channel, so an asynchronous command, event or projection keeps the flow
pending until its consumer processed that exact message. A destination counts messages by id, so a redelivery
satisfies its own delivery only, and a message keeps the delivery it started as through dynamic channels, the
high-throughput outbox and a dead-letter replay. A flow handed more work after completing, as when a request sends
two commands under one `tracingId`, is pending again until that work is done. A message sent to another service through the
`DistributedBus` is handed off: this service's store never sees it consumed, so a call that names a destination,
`isCompletedFor($tracingId, $destination)` or `isCompletedWithin($tracingId, $timeout, $destination)`, does not wait for it.
Across a hand-off the destination counts as reached on the **first processed reply**: a flow that sent several distributed
requests under one `tracingId` is true for the class that receives the replies as soon as one reply was processed, whether the
service that answered tracks its flows or not and whether the reply was consumed by `run()` or `ecotone:run`. A caller that needs
every reply sends each request with its own `tracingId` and awaits each one. A call without a destination,
`isCompletedFor($tracingId)`, is unchanged: a trace that sent a distributed message never completes in this store. `isCompletedFor()` and
`isCompletedWithin()` refuse a `#[Polling]` projection by name, because a polled run is not part of the flow that appended its
events.

Everything is written inside the transaction of the unit of work it describes, a bus call or an asynchronous
consumption: the spans buffered during the work, and the span that wraps it as it stands at that moment. Whether a
flow is complete is never stored: it is decided from the committed spans each time it is asked. An asynchronous projection, which commits once per batch, is recorded inside each
batch's transaction, and is marked processed in the batch that finds it caught up. A path run with no transaction at
all, an event bus call from a controller or a command bus with `withTransactionOnCommandBus(false)`, has none to be
inside and writes when its work ends. Nothing is written after the commit or after a rollback, so a database that goes down
right after a commit cannot fail a consumer or a command whose work already committed, and an outage inside the
transaction rolls back with the work and leaves the message to be retried. A unit of work that fails stores nothing;
its failure is visible in OpenTelemetry. Notifications are published from memory once the transaction committed, at
most once: a subscriber that throws does not fail the work that committed, is logged, and is not called again. Each
notification is delivered in a trace of its own, marked `ecotone=untracked` in the W3C `tracestate`, so whatever a
subscriber sends, synchronously or through an asynchronous channel, is neither stored nor notified and can never reopen
the flow it was told about; OpenTelemetry still exports it, as its own trace. A subscriber runs synchronously, as that delivery
happens; `#[Asynchronous]` on it is refused at bootstrap, so work meant for the background is a command it sends to an
`#[Asynchronous]` handler. A subscriber whose first parameter is not `FlowStepCompleted`, and a
`FlowTrackingConfiguration` registered twice, are refused at bootstrap too. Each step is its own row, keyed by its
trace and span id, so parts of one flow committing at the same moment never write the same row and never wait for
each other, on PostgreSQL, MySQL and MariaDB alike.

`withOnlyRequestedFlows(true)` tracks only flows sent with a `tracingId` or a `traceparent`, carrying that choice
across channels in the W3C `tracestate` as `ecotone=carried` next to any other vendor's entry, so ordinary traffic
costs nothing. `withRetention()` sets how long `ecotone:tracing:remove-expired-traces` keeps a flow, counted from its
first step, seven days by default, completed or not. Nothing runs the command for you: schedule it, from cron or the framework's scheduler, or the tables keep every
flow. It removes expired flows in batches of 1000, each batch's spans in one short transaction, until
none is left, so a large backlog never holds one long lock; `withRemovalBatchSize()` changes the size. A multi-tenant application tracks each flow in the database of its tenant, inside the tenant's transaction, so a
flow is never complete before the tenant's data committed. `TracingGateway` is then asked with the tenant,
`$tracingGateway->isCompletedWithin($tracingId, $timeout, metadata: ['tenant' => 'tenant_a'])`, and a call without one is refused
naming that metadata; the retention command runs once per tenant,
`ecotone:tracing:remove-expired-traces --header=tenant:tenant_a`.

```php
#[ServiceContext]
public function flowTracking(): FlowTrackingConfiguration
{
    return FlowTrackingConfiguration::createWithDefaults();
}

$tracingId = Uuid::v7()->toRfc4122();
$commandBus->sendWithRouting('order.place', $order, metadata: [MessageHeaders::TRACING_ID => $tracingId]);
if (! $tracingGateway->isCompletedWithin($tracingId, Duration::seconds(5), 'orders_list')) {
    $pending = $tracingGateway->fetchFlow($tracingId)->pending;
}

final class OrdersWebsocket
{
    #[OnFlowStepCompleted]
    public function push(FlowStepCompleted $event, TracingGateway $tracingGateway): void
    {
        if ($tracingGateway->isCompletedFor($event->step->traceId, 'orders_list')) {
        }
    }
}
```

**How to adapt:** nothing is required. To use it, register `FlowTrackingConfiguration`, run the setup command for its
table, and give each handler you want to ask about an explicit `endpointId`.

### A service answers Ecotone Pulse over the Distributed Bus (Enterprise)

**Before:** there was no way for a tool outside the application to ask it what it runs: versions, handlers, the
message flow, or how it is wired to other services.

**Now:** registering `Ecotone\Api\Pulse\PulseConfiguration::createWithDefaults(pulseServiceName: 'ecotone_pulse')` from a
`#[ServiceContext]` method makes the service answer the routing key `ecotone.pulse.snapshot`. The Pulse service sends
`DistributedBus::sendMessage($service, 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey:
'ecotone.pulse.snapshot_received')` and receives one JSON reply: `schemaVersion`, `serviceName`, `takenAt`, an
`overview` (Ecotone and PHP versions, framework, licence), the `flowGraph`, the distributed `topology` of the
`DistributedServiceMap`s and the `channels` the service consumes: a list of `{name, kind}` with kind `async` for a
pollable channel an `#[Asynchronous]` handler consumes and `queue` for a pollable channel no handler is bound to (the
channels a Service Map only sends to are left out), which is what Pulse offers as a dead letter replay target. Only requests from the configured Pulse service are served; any other source fails the
handler with a message naming the expected service. The application needs an Enterprise licence and a service name, otherwise
bootstrap is refused; registering `PulseConfiguration` twice is refused as well. The reply mapping to the Pulse service
is implied: replies go to a channel named after the Pulse service, or to the one chosen with
`PulseConfiguration::createWithDefaults(...)->withReplyChannel('pulse_replies')`, and no `DistributedServiceMap` is
needed for it. The reply channel is registered for you with the builder class and connection of the request channel (the
channel named after the service, or `->withRequestChannel('...')`), for Amqp, Dbal, Redis and Sqs channels; queue names,
topics and tables are not copied. For Amqp and Sqs it is registered with `withRequiredExistingDestination()` (see
below): the Pulse service creates its reply queue, so a service that replies before it exists fails the reply instead of
creating a queue nobody consumes. A reply channel you register yourself wins. When the request channel is not registered,
or is a Kafka channel, bootstrap is refused with a message naming `withRequestChannel` and the builder to register
(for example `AmqpBackedMessageChannelBuilder::create('ecotone_pulse')`). A `DistributedServiceMap`
that maps the Pulse service still works and wins; one that maps it to a different channel than `withReplyChannel` is
refused, naming both.

**How to adapt:** nothing is required. To use it, register `PulseConfiguration` next to the request channel the service already consumes. An existing `DistributedServiceMap` mapping to the Pulse service can stay or be removed.

The snapshot of a service that has the Dbal package carries a `deadLetter` section, `{"enabled": true|false}`, next to `deadLetters` (which is still present only when dead letters are enabled), so a consumer of the snapshot tells "dead letter storage is enabled and empty" from "not configured". The field is additive: a snapshot without it is read by whether `deadLetters` is present, as before.

With the DBAL dead letter enabled, the snapshot gains a `deadLetters` section: the `total`, the `groups` by message name
and exception class, and the 100 `recent` dead letters newest first. Each recent dead letter has its message id, name,
`failedAt`, exception class and message, stack trace and payload (both cut at 4096 bytes with a `... [truncated]`
marker), content type and original channel.

The name is the distributed routing key; else the routing key the message was sent with (see §1); else the
`__TypeId__`, when it is a class or a type alias, for messages that never went through a bus.

Pulse acts on dead letters with the routing keys `ecotone.pulse.dead_letter.replay` (optionally with a `targetChannel`)
and `ecotone.pulse.dead_letter.delete`, sending `{"messageIds": [...]}` or `{"filter": {"messageName": ...,
"exceptionClass": ..., "failedFrom": ..., "failedTo": ...}}`, and receives a fresh snapshot as the reply.

A filter applies to every stored dead letter and matches the name shown in the snapshot. `failedFrom` and `failedTo` are
inclusive at whole-second precision, and `failedFrom` later than `failedTo` is refused, as is an empty filter. A dead
letter removed while a filtered action runs is skipped. A service without a dead letter refuses the action with how to
enable it.

With flow tracking (`FlowTrackingConfiguration`), the snapshot gains a `tracing` section, absent without flow tracking:
`storage` (`dbal` or `in_memory`), `retention` in seconds, `statsWindow` (1000) and `flowTypes`, up to 100, most runs
first. A flow type has `runs` (every retained run), `windowRuns` (the most recent runs, at most `statsWindow`),
`runsWithErrors`, `p50Ms`, `p95Ms`, `maxMs` (all three over those `windowRuns`: the duration of the span the flow
entered this service with) and `lastStartedAt`. Reading the section costs one index-only count per snapshot and one
read of at most `statsWindow` entries per flow type.

Flow types are case-sensitive on every engine (on MySQL and MariaDB the column is `utf8mb4_bin`).

A flow type is the routing name the flow entered this service through: the routing key of a command, event or query
sent to its bus, the class name when it was sent by class, and, for a flow that arrived through a channel or from
another service, the name of the first message its handling sent (bus sends before channel sends, then the earliest,
then alphabetical), else the channel name. The same business flow therefore has a different flow type in each
service; it is joined across services by its trace id.

`runsWithErrors` counts the runs in which an error was recorded in this service's part of the flow: a step that failed
inside a unit of work that still completed, for instance an exception a handler caught, or one recorded by a later
step. A step whose unit of work failed is rolled back with everything it recorded, so it leaves no span and is never
counted: it is found among the dead letters, where every `recent` entry now carries its `traceId` (the trace id of the
message's `traceparent`, else its `tracingId`; `null` for a message that carried none), the id the trace query takes.

Pulse reads the traces on demand, with `DistributedBus::sendMessage($service, $routingKey, $payload,
MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey:
'ecotone.pulse.query_result_received')`. The query id must be sent, and is returned in the payload and, as a user
header, with the reply. `ecotone.pulse.traces.list` takes `{"flowType": "create.ticket", "page": 1, "perPage": 25}`
(`page` from 1, `perPage` from 1 to 25) and replies `queryId`, `flowType`, `page`, `perPage`, `total` and `traces`,
newest first, each with `traceId`, `startedAt`, `durationMs`, `status` (`ok` or `error`, as `runsWithErrors` counts)
and `rootEndpoint`; a flow type nobody ran is an empty list. `ecotone.pulse.traces.trace` takes `{"traceId": "..."}`
and replies `queryId`, `traceId`, `totalSpans`, `truncated` and the first 1000 `spans` this service kept, each parent
before its children, with `spanId`, `parentSpanId`, `name`, `kind`, `status`, `statusDescription`, `startedAt`,
`endedAt`, `attributes` and `events`; text is cut at 4096 bytes. Both queries are served to the Pulse service only,
and every request under the `ecotone.pulse.` routing prefix is kept out of flow tracking.

A service with a `MultiTenantConfiguration` is monitored per tenant, and Pulse needs no tenant configuration for it. A
snapshot request without the tenant header returns the tenant-independent sections (`overview`, `flowGraph`,
`topology`, `channels`, `deadLetter`, `tracing.storage`) plus `tenancy: {header, tenants}`, the tenant header name and
the tenants of the mapping; `deadLetters` and `tracing.flowTypes` are left out instead of failing. A request with the
tenant header (`metadata: ['tenant' => 'tenant_a']`) returns `schemaVersion`, `serviceName`, `takenAt`, `tenant` and
the sections of that tenant only, and the dead letter replay and delete actions and the trace queries with the header
act on the database of that tenant and echo `tenant` in their reply. A tenant the service does not advertise is
refused, because the connection of an unmapped tenant falls back to the default one. A snapshot of a single-tenant service has no
`tenancy` key and is unchanged. Bootstrap is refused when the Pulse request or reply channel is a DBAL channel on the
multi-tenant connection reference, naming the ways out: a dedicated non-tenant `DbalConnection` reference for the Pulse
channels, or a broker transport; MultiTenantConfigurations that name different tenant headers are refused as well.

The `projections` section is tenant-scoped as well: a request without the tenant header leaves it out, and a request with the header reports the projections from the event store of that tenant. DBAL channels on the multi-tenant connection are listed in the tenant-less `channels` without a depth (all fields `null`, no `probeError`) and measured in the `channels` of each tenant's reply; channels of other transports are measured in the tenant-less reply only. The dead letter replay and delete by `failureId` act on the requested tenant only.

**How to adapt:** nothing is required for a single-tenant service. A multi-tenant service that sends Pulse over a DBAL channel on its multi-tenant connection moves the Pulse channels to a connection that is not multi-tenant, for example `DbalBackedMessageChannelBuilder::create('orders', 'pulse_connection')`. Pulse can narrow the tenants it asks for with `PULSE_SERVICES=orders{tenants=tenant_a,tenant_b}`.

A trace crosses services under one trace id. The first span a service stores for a message it received from another
service, `receive <channel>`, has as `parentSpanId` the `spanId` of the `send <channel>` span of the sending service,
for a distributed command and for a distributed event alike, one per subscribing service; a handler that consumes
asynchronously continues from its own `send` span in the receiving service. Pulse nests a service's spans under the
sender's by that link.

A spans table created by an earlier 2.0 beta lacks the `flow_type` and `flow_status` columns the section and the
queries need. The first write or read that meets it fails with a `ConfigurationException` naming the statements below.

**How to adapt:** add the columns and the index for your database, replacing `ecotone_tracing` with your table name.
Flows stored by the beta have no flow type, so they are not listed and are removed when their retention passes.

PostgreSQL and SQLite:

```sql
ALTER TABLE ecotone_tracing ADD COLUMN flow_type VARCHAR(255) DEFAULT NULL;
ALTER TABLE ecotone_tracing ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL;
CREATE INDEX ecotone_tracing_flow_type_started_at_idx ON ecotone_tracing (flow_type, started_at);
```

MySQL and MariaDB:

```sql
ALTER TABLE ecotone_tracing
    ADD COLUMN flow_type VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL,
    ADD INDEX ecotone_tracing_flow_type_started_at_idx (flow_type, started_at);
```

Or drop the table, as tracked flows are only kept for the retention, and create it again:
`DROP TABLE ecotone_tracing;` followed by `ecotone:migration:database:setup --initialize --feature=tracing`.

### Ecotone Pulse tracks the projection rebuilds it starts in a new table (Enterprise)

**Before:** a projection was rebuilt from the console (`ecotone:projection:rebuild`) or `ProjectionOperations`, and nothing recorded that a rebuild had been requested or how far it had got.

**Now:** a service with `PulseConfiguration` answers `ecotone.pulse.projections.rebuild` (payload `{"projection": "orders"}`, with the `tracingId` header Pulse sends on every action) and `ecotone.pulse.trackings.list` (payload `{"since": "<ISO 8601 time>", "category": "ecotone-pulse", "type": "projection-rebuild", "reference": "orders", "limit": 100}`, `since` required, `limit` at most 100). In the transaction of the request, the service inserts one row into the new `ecotone_pulse_tracking` table: the tracing id, the category `ecotone-pulse`, the type `projection-rebuild`, the projection name as the reference, the active tenant (`NULL` without multi-tenancy) and the start time. The row is written once and never updated. The rebuild then runs under the request's tracing id: through the projection's `#[ProjectionRebuild(asyncChannelName: ...)]` channel when it has one, otherwise inside the request, and a failure of that synchronous rebuild fails the request and leaves no row.

Every rebuild batch message carries `ecotone_projecting_rebuild_batch_number`, `ecotone_projecting_rebuild_batch_count` and `ecotone_projecting_rebuild_partition_count`, which tracing records as span attributes even when `TracingConfiguration::withSpanAttributesFromHeaders()` names other headers, and each batch is traced as a `process partition batch <projection>` span. The trackings query derives each rebuild's state from those spans and the dead letters: `running` (batches done out of the batch count, or the elapsed time), `done` (with its duration) or `failed` (the dead letter carrying the tracking's trace id), newest first and capped at 100. The projections of the Pulse snapshot report `rebuildAsyncChannel`.

The table is created by the migration setup feature `pulse_tracking` only, in the database flow tracking stores to (with in-memory flow tracking, the tracking is in memory too). A service whose table is missing fails the rebuild request with a `ConfigurationException` naming the command.

**How to adapt:** create the table with `ecotone:migration:database:setup --initialize` (or `--feature=pulse_tracking`), or `$messagingSystem->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager::class)->initialize('pulse_tracking')`, or generate the SQL for your own migrations with `getCreateSqlStatementsForFeatures(['pulse_tracking'])`. Expect one more span, `process partition batch <projection>`, in the trace of a backfill or a rebuild. Pulse reads rebuilds back from the service for the last `PULSE_TRACKING_DAYS` days (default 7), so a restart of Pulse loses nothing.

### A channel can refuse to create its destination on send: `withRequiredExistingDestination()`

**Before:** an AMQP channel declared its queue on every first send, an SQS channel created its queue on send, and a Kafka
topic was created by the broker when it allowed it. A destination whose name was overridden (the `queueName` of
`AmqpBackedMessageChannelBuilder::create()`, the `topicName` of `KafkaMessageChannelBuilder::create()`) and mistyped on the
sending side was created quietly and the messages went into a queue nobody consumes.

**Now:** `AmqpBackedMessageChannelBuilder`, `SqsBackedMessageChannelBuilder` and `KafkaMessageChannelBuilder` have
`withRequiredExistingDestination()`, for a service that only sends to a channel another service consumes. It governs
sending only: a consumer of the same channel still declares its own queue. The channel never declares or creates the
destination, and checks that it exists before every send: a passive queue declare on AMQP (on its own short-lived context,
because RabbitMQ closes the channel that asks about a missing queue), a queue URL lookup on SQS, and on Kafka a read of the
topic list of the broker metadata (an unfiltered list, because asking the broker about one topic can make it create the
topic, so the check would not be independent of `auto.create.topics.enable`; it is one metadata round trip per send, which
is the price of detecting a topic deleted after the first send without keeping state in the channel). A missing
destination throws `Ecotone\Api\Messaging\DestinationDoesNotExistException` with the channel name, the actual destination
after the overrides, the connection, and the ways out: run the consumer of the channel, create the destination yourself,
fix the name, or remove the option. It is a runtime failure, not a `ConfigurationException`, so the consumer's instant
retries and error handling treat it like any failure of the handler; nothing is sent and the destination is not created,
so a later send succeeds once it exists. A Kafka broker that cannot be reached for the check raises a
`MessagingException` naming the channel instead of the raw `RdKafka\Exception`. The default of every builder is unchanged.

```php
AmqpBackedMessageChannelBuilder::create('orders', queueName: 'orders_v2')->withRequiredExistingDestination();
```

Redis and DBAL channel builders have no such method, on purpose: a Redis list does not exist while it is empty, and a DBAL queue is a
value in the shared `enqueue` table, so neither has a reliable "this destination exists" signal and Ecotone does not fake one.
Name those channels explicitly and check them yourself.

Pulse uses it for every channel it sends to on a service's broker (AMQP, SQS, Kafka) and for the reply channel a service
derives for it. A service whose channel does not exist is shown as "Channel missing" with the destination, distinct from
Unreachable, and is asked again once the 60 seconds after which a snapshot stops counting as fresh have passed, or when Refresh is pressed. Pulse's own reply channel is still declared by Pulse, when it consumes it.

**How to adapt:** nothing is required. A service that replies to Pulse over AMQP or SQS needs Pulse to have started
consuming its reply channel first; until then the reply fails with the exception above, goes through the service's instant retries and error handling like any failure of a handler, and the request is processed again once Pulse's queue exists.

## 15. Testing and developer-experience changes

These changes make tests and error messages say what happens, so that failures point at the real cause. Most of them
rename test-support methods without aliases; the renamed methods are listed in each entry.

- **`EcotoneLite::bootstrapFlowTestingWithEventStore()` is removed, and the connection a flow test is given decides
  where its data is kept.**
  **Before:** a flow test chose its storage by its bootstrap. `bootstrapFlowTestingWithEventStore()` enabled the
  event sourcing, DBAL and JMS converter packages, kept events in memory unless `runForProductionEventStore: true`
  was passed, and added `DbalConfiguration::createForTesting()`, whose document store was in memory. Any flow test
  holding a `TestConfiguration` kept dead letter and deduplication in memory even when it was given a connection.
  **Now:** `EcotoneLite::bootstrapFlowTesting()` is the only flow-testing bootstrap, and in it the connections you
  provide decide the implementation. Each of the event store, document store, deduplication and dead letter uses
  DBAL when the connection it is configured on is provided — a `containerOrAvailableServices` entry under that
  reference, a `MultiTenantConfiguration`, or a framework `ConnectionReference` — and keeps its data in memory when
  it is not. With no DBAL connection, DBAL transactions and object-manager flushing are off, since there is nothing
  to wrap. An `EventSourcingConfiguration` or a `DbalBackedMessageChannelBuilder` on a connection the flow test was
  not given is refused at bootstrap with a `ConfigurationException` naming the services entry to add, or the
  in-memory alternative. `DbalConfiguration::createDefaultFor()` is removed, and
  `DbalConfiguration::createForTesting()` no longer forces the document store into memory. An application outside a
  flow test behaves as before. **How to adapt:** rename the call to `bootstrapFlowTesting()` and drop
  `runForProductionEventStore:` — giving the test a connection is what keeps it on the database.
  A test that passed a connection for one feature but wanted another in memory keeps that one in memory explicitly,
  with `EventSourcingConfiguration::createInMemory()`, `withDocumentStore(inMemoryDocumentStore: true)`,
  `withDeduplication(inMemory: true)` or `withDeadLetter(true, inMemory: true)`.
- **A flow test loads every installed module package.** **Before:** `EcotoneLite::bootstrapFlowTesting()` given no
  `withEnabledModulePackageNames()` loaded only the core package, so a test using event sourcing, DBAL or any other
  module had to name it. **Now:** it loads every installed package, as `EcotoneLite::bootstrap()` and the framework
  integrations do, and each one runs in memory until the test is given what it needs to run for real: a DBAL
  connection for the event store, document store, deduplication and dead letter, a `TracerProviderInterface` for
  tracing, which otherwise traces through OpenTelemetry's no-op provider. "Installed" means the package's module
  classes exist, so a test enables exactly what Composer installed. **How to adapt:** nothing, unless a test
  depended on a module *not* being loaded; such a test names the packages it wants with
  `EcotoneLite::bootstrapFlowTesting(..., enabledModules: [...])` (§12).
- **A flow test given no connection keeps events in memory as the objects they were recorded as.** **Before:**
  `bootstrapFlowTestingWithEventStore()` kept events in a serializing in-memory store, so an event that could not be
  converted failed in the test, as it would in production. **Now:** with no connection and no
  `EventSourcingConfiguration`, appends and loads keep the event objects and nothing is converted - the behaviour plain
  `bootstrapFlowTesting()` always had - so an event with no converter can pass a flow test and fail against a database.
  **How to adapt:** register `EventSourcingConfiguration::createInMemory()` to keep the serializing in-memory store,
  or give the test a connection to run against the database.
- **A flow test's queue channels serialize to JSON when the JMS converter is installed.** **Before:** a flow test
  that named no packages loaded only the core, so a pollable channel serialized its payloads with the default
  `application/x-php-serialized`, which needs no converter. **Now:** the flow test loads every installed package; with
  `ecotone/jms-converter` installed, its module makes `application/json` the default serialization unless the `ServiceConfiguration` chooses one,
  so a payload crossing a queue channel is serialized to JSON and rebuilt by JMS on the way back. A type JMS cannot
  rebuild - an interface such as `Ramsey\Uuid\UuidInterface` - then fails with a `ConversionException`, although
  the application declares a converter the test never registered. **How to adapt:** register the application's
  `#[Converter]` for that type in the flow test (its class in `classesToResolve` and an instance in
  `containerOrAvailableServices`), or keep the channel PHP-serialized explicitly:
  `SimpleMessageChannelBuilder::createQueueChannel('orders', conversionMediaType: MediaType::APPLICATION_X_PHP_SERIALIZED)`.
- **The Kafka licence is required when Kafka is used, not when it is installed.** **Before:** an installed Kafka
  module refused to boot without an Enterprise licence even when nothing used it. **Now:** it refuses only when a
  `#[KafkaConsumer]`, a `KafkaMessageChannelBuilder`, a `KafkaConsumerConfiguration`, a `TopicConfiguration` or a
  `KafkaPublisherConfiguration` is present, and the message names each one. **How to adapt:** nothing.
- **Dead letter and deduplication have an in-memory implementation.**
  **Before:** both existed only on DBAL, so a flow test using either needed a database connection and its tables.
  **Now:** each has an in-memory implementation beside the DBAL one, selected on the feature's own
  `DbalConfiguration` method — `withDeadLetter(true, inMemory: true)`, `withDeduplication(inMemory: true)` — and a
  flow test that is not given their connection gets both in memory (see the first entry of this section). Every
  application outside a flow test keeps DBAL. Each `DeadLetterGateway` and `CustomDeadLetterGateway` gets its own in-memory store,
  and a custom gateway's `store()` now writes to its own dead letter — on DBAL, its own connection — instead of the
  default gateway's. **How to adapt:** a flow test that asserts on the dead-letter or deduplication *table* rather
  than through `DeadLetterGateway` and its handlers registers `DbalConfiguration::createWithDefaults()` explicitly.
  `DbalConfiguration::createForTesting()` is unchanged and still turns both off.
- **A `TestConfiguration` given as an extension object is refused.** **Before:**
  `EcotoneLite::bootstrapFlowTesting()` and the since-removed `bootstrapFlowTestingWithEventStore()` removed every `TestConfiguration`
  found in the extension objects and used the `testConfiguration:` parameter (or the defaults)
  instead, so settings passed through the extension objects were ignored without a word.
  **Now:** bootstrap fails with `ConfigurationException`: `EcotoneLite::bootstrapFlowTesting() was given a
  TestConfiguration in extensionObjects. … pass it as
  EcotoneLite::bootstrapFlowTesting(..., testConfiguration: $yourTestConfiguration) instead.` **How to adapt:** move the
  `TestConfiguration` from `extensionObjects:` to the `testConfiguration:` argument.
- **`DeadLetterGateway::list()` has a stated order.** Most recently failed first, by the failed message's timestamp;
  messages that failed in the same second by message id, ascending. Before, DBAL ordered by the timestamp alone, so
  messages that failed in the same second came back in an engine-chosen order and consecutive pages could repeat one
  and skip another. **How to adapt:** nothing.
- **Retries may use a zero back-off.** In 1.x `RetryTemplateBuilder::fixedBackOff(0)` and an exponential back-off
  starting at `0` threw `Initial delay must be greater than 0`, and `#[DelayedRetry(initialDelayMs: 0)]` threw
  `DelayedRetry initialDelayMs must be greater than 0` (the parameter is now `initialDelayInMilliseconds`, see below). A
  zero delay is now accepted: the failed message is sent back to its channel without a delivery delay, so a single
  `run('async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false))` walks it through every retry and
  into the dead letter. Negative delays still throw, naming the value
  (`Retry initial delay must be 0 or greater, got -1 ms`; on the attribute,
  `DelayedRetry initialDelayInMilliseconds must be 0 or greater, got -1`).
  **How to adapt:** nothing. Tests that advanced the clock only to get past a 1 ms back-off can use `0` instead.
- **Retry exhaustion counts deliveries, not "retries".** With `maxRetryAttempts(3)` the handler is delivered 4 times
  (1 initial + 3 retries), but the log said `retried maximum number of \`4\` times` and the exception said
  `Message handling failed after 4 retry attempts`. They now read
  `Sending message \`…\` to dead letter channel after 4 failed deliveries (1 initial + 3 retries). Due to: …`,
  `No dead letter channel defined. Message failed after 4 failed deliveries (1 initial + 3 retries). …` and
  `Message handling failed on channel \`async\` after 4 failed deliveries (1 initial + 3 retries). RuntimeException: …`
  (the exception now also names the channel and the original exception class). The number of deliveries is unchanged.
  **How to adapt:** update log-based alerts or tests that match the old texts.
- **Every message is stamped by the clock Ecotone was given, and a message without a timestamp is refused.**
  **Before:** `MessageHeaders::create()` filled a missing `MessageHeaders::TIMESTAMP` from the global `Clock::get()`
  in 1.x, and from a fresh `NativeClock` in the first 2.0 betas, so a message built outside a gateway, such as a
  `#[Scheduled]` method's result or `sendDirectToChannel()` in a flow test, carried real time while the rest of the
  test ran on the pinned clock. `MessageBuilder::withPayload()`, `fromMessage()` and `fromParentMessage()` were
  static.
  **Now:** `MessageBuilder` is a container service holding `EcotoneClockInterface`; `withPayload()`, `fromMessage()`
  and `fromParentMessage()` are instance methods on it, and `build()` stamps a missing timestamp from that clock.
  `MessageHeaders::create()` throws `InvalidMessageHeaderException` reading `Message headers have no timestamp. Build
  messages with MessageBuilder, the container service that stamps them from EcotoneClockInterface; in tests use
  Ecotone\Test\StaticMessageBuilder.` when no timestamp is given, and `MessageHeaders::createEmpty()` and
  `GenericMessage::createWithEmptyHeaders()` are gone.
  **How to adapt:** application code never built messages. A test that did, for a channel interceptor, replaces
  `MessageBuilder::withPayload(...)` with `Ecotone\Test\StaticMessageBuilder::withPayload(...)`, same chain, same
  `build()`; a custom channel or interceptor in your codebase that built messages takes `MessageBuilder` as a
  constructor dependency instead.
- **A flow test pins its clock at bootstrap, reads it with `now()`, and seeds history at a time.**
  **Before:** the test clock was frozen only once `changeTimeTo()` or `advanceTimeBy()` was called, so the first
  message a test sent carried real time; the current time was reachable only by fetching `EcotoneClockInterface` from
  the container; `withEventsFor()` and `withEventStream()` recorded every seeded event at the clock's current time.
  **Now:** `TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2024-03-01 10:00:00'))` starts
  the clock there, so the first message is stamped with it and `changeTimeTo()` to an earlier instant is refused with
  `Cannot move time backwards`. `FlowTestSupport::now()` returns the clock's `DatePoint`. `withEventsFor()`,
  `withEventStream()` and `withEvents()` take `at: DateTimeImmutable`, record the events as of that time, and put the
  clock back afterwards. A `StaticPsrClock` constructed with an explicit time counts as pinned the same way.
  **How to adapt:** nothing is required. Replace a `changeTimeTo()` placed right after bootstrap with `withTimeAt()`,
  and a chain of `advanceTimeBy()` calls around seeding with `at:`.
- **`FlowTestSupport` no longer hands out channels.** `getMessageChannel()`, `getMessageChannelByName()`,
  `receiveMessageFrom()`, `sendMessageDirectToChannel()` and `sendMessageDirectToChannelWithMessageReply()` are
  removed.
  **Before:** a test could fetch a `PollableChannel` and call `send()` or `receive()` on it, which proved the channel
  rather than the application and skipped every interceptor on the application's path.
  **Now:** the application reaches a channel through an `#[Asynchronous]` handler, so a test sends the command or
  event, calls `run('channel')` and asserts on what the handler observed. `sendDirectToChannel()` with a payload and
  metadata stays for an `#[InternalHandler]` test. Ecotone's own channel suites use the internal
  `Ecotone\Test\ChannelTestSupport::of($ecotone)`, which carries the removed methods and is not part of the public
  surface.
  **How to adapt:** replace `$ecotone->getMessageChannel('orders')->send(...)` with the command or event that reaches
  that channel, and `receiveMessageFrom('orders')` with `run('orders')` followed by a query or state assertion.
- **An event appended without a timestamp is stamped by the same clock, in every event store.**
  **Before:** `EventStore::appendTo()` with metadata lacking `MessageHeaders::TIMESTAMP` wrote `created_at` from real
  time on DBAL and recorded no timestamp at all in memory, so a `created_at` metadata match behaved differently on the
  two stores and a flow test on a pinned clock loaded the event back without the time it was pinned to.
  **Now:** both `DbalEventStore` and `InMemoryEventStore` take `EcotoneClockInterface` and add
  `MessageHeaders::TIMESTAMP` from it to the event's metadata before recording, so the event loads back with the
  timestamp that `created_at` holds, on both stores, and `changeTimeTo()` decides it in a test. A timestamp given in
  the metadata is kept as it is. `DbalReconnectableConnectionFactory` takes the clock as a required constructor argument
  and a `DbalContext` created straight from a connection factory refuses `getClock()` with a `LogicException` naming
  the wrapper, since only the wrapper hands the clock over.
  **How to adapt:** nothing for an application that appends through the `EventStore` gateway. Code that constructed
  `DbalReconnectableConnectionFactory` itself passes the container's `EcotoneClockInterface` as the second argument.
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
- **A dead letter replayed to a target channel keeps its routing slip and goes through an asynchronous channel.** **Before:**
  `DeadLetterGateway::replay($messageId, $targetChannel)`, `replayAll()` and `ecotone:deadletter:replay` removed the routing
  slip of the message and accepted any existing channel, so a message replayed to a pollable channel was consumed there without
  reaching a handler and was lost. **Now:** the target is the asynchronous (pollable) channel the message is sent through. The
  message keeps its stored routing slip (only the error context is removed), so once that channel is consumed the original
  handler runs; the channel can be the one of the handler or another asynchronous channel. A queue registered in your
  `#[ServiceContext]` that no handler is bound to also keeps the message with its slip for a later consumer. A target that is
  not asynchronous (a handler input channel, a direct channel) is refused before anything is replayed or deleted, on the
  gateway, `replayAll()`, the console commands and the Pulse action, and the dead letters are kept. **How to adapt:** pass
  an asynchronous channel instead of the input channel of a handler, or replay without a target to send each message back to
  the channel it was polled from.
- **A command or query sent to a handler that is not registered in a flow test names the class to register.** An
  Ecotone Lite bootstrap registers only the classes it lists, but the error suggested a missing attribute:
  `No Command Handler defined for it. Have you forgot to add #[CommandHandler] to method?`. In
  `bootstrapFlowTesting()` Ecotone now looks up the handler in your autoloaded (non-vendor) namespaces and says
  `Can't send command to App\Shipping\ReserveShippingSlot. It is handled by App\Shipping\ShippingSlotReservationHandler::reserve(),
  which is not registered in this Ecotone Lite bootstrap. Add App\Shipping\ShippingSlotReservationHandler to the classesToResolve of
  EcotoneLite::bootstrapFlowTesting(), or load its namespace with EcotoneLite::bootstrapFlowTesting(..., namespaces: ['App\Shipping']).`
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
- **The Enterprise-only `#[Apply]` metadata parameter names the open-source alternative.** Without a
  licence, a second parameter such as `#[Header(MessageHeaders::TIMESTAMP)] int $recordedAt` fails with `… is part of
  Enterprise features. Without Enterprise, keep a single event parameter and carry the value you need (for example the
  time of the change) in the event itself. To read metadata here, obtain Enterprise: https://docs.ecotone.tech/enterprise`.
  **How to adapt:** nothing.
- **Testing polling parameters use the names of the matching setters.** `createWithTestingSetup()` took
  `amountOfMessagesToHandle` (a maximum, not an exact count), `maxExecutionTimeInMilliseconds` and `failAtError`, while the
  same settings are `withHandledMessageLimit()`, `withExecutionTimeLimitInMilliseconds()` and `withStopOnError()` (and
  `--handledMessageLimit` / `--stopOnFailure` on `ecotone:run`).

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
| Simpler EcotoneLite testing | Flow tests load every installed package with in-memory test profiles, instead of Core only. (The other half — an in-memory queue channel for every unconfigured `#[Asynchronous]` channel — has shipped; see §1) | `docs/superpowers/research/ecotone-lite-testing-simplification/report.md` (section "Implementation sketch"; no final design yet) |
| Service cache directory | Replace the cache-directory setting on `ServiceConfiguration` with an explicit bootstrap parameter; shared cache-clear command | `docs/superpowers/research/service-cache-directory/report.md` (section "Implementation plan"; overlaps with §12) |
| `ecotone:describe` introspection | A read-only API and console command that prints how messaging is configured: channels (type, delayable, consumer command), asynchronous endpoints (channel, delay, retry and dead letter), the error channel policy, converters, projections with their commands, and how each handler resolves its aggregate or saga identifier. Answers the question coding agents ask in almost every session without reading configuration files | Not designed yet — to be discussed. Input: agent benchmark findings (catalogue item A6, "How is messaging configured here?") |
| Flow completion by depth | Flow tracking (§14) answers whether a whole flow or one destination completed. `#[OnTraceDepthCompleted]`, notified when every handler at one depth of the flow ended (the command handler is depth 1, the event handlers it triggered depth 2, and so on), is not implemented; it needs the depth carried in `tracestate` across the asynchronous boundary | Design recorded in the conversation that produced §14's tracing sections; no spec file yet |
| Stateless `#[Apply]` | A second, opt-in fold style: the handler **returns** the changed state instead of mutating `$this`, for both event-sourced aggregates and DCB decision models. A command handler then runs against the last state the fold returned. The existing mutating style stays and needs no change. Detected from the declared return type at configuration time — `void` is stateful, the model's own type is stateless — which is unambiguous because a non-void `#[Apply]` is already rejected at bootstrap today. A stateless state object may carry its own `#[Version]`; **userland increments it in each apply** and Ecotone verifies it increased on every applied event, which also makes a fully `readonly` state class possible on PHP 8.2. Returning the wrong type is rejected rather than ignored | `docs/superpowers/specs/2026-09-30-stateless-event-sourcing-handler-design.md` — researched against Axon 5, Marten, Akka/Pekko, Emmett/Decider and Eventuous, with the fold cost measured. Maintainer decisions on all four open questions are recorded in it. Note the measured hazard: a state object that copies a growing collection makes the fold O(n²) (19.9 µs/event by 10k events against 6.3–6.8 µs today), so the documentation must lead with the shape that avoids it |

---

## Upgrade checklist

1. Upgrade to the latest 1.x first and fix every deprecation notice.
2. Bring the platform up to the new minimums: PHP 8.2, Laravel 11+, DBAL 4, ORM 3 / DoctrineBundle 2.12+ (see the top of this guide).
3. `composer require ecotone/ecotone:^2.0` together with every `ecotone/*` package you use.
4. Replace imports with the namespace map (§13), which also renames `#[EventSourcingHandler]` to `#[Apply]` (§7c). Fetch `ConfiguredMessagingSystem`, `MessagingTestSupport`, `ConsoleWriter` and `InMemoryConsoleWriter` by their new ids, and clear Ecotone's cache on deploy (§13e, §13f).
5. Replace `withSkippedModulePackageNames` with `EcotoneLite::bootstrap(..., enabledModules: [...])`, and the `skippedModulePackageNames` framework key with `enabledModulePackageNames`, inverting the list into the packages to load; remove `enableAsynchronousProcessing` and add `->run('<channel>')` in tests (§1). Move `serviceName`, `defaultSerializationMediaType`, `defaultErrorChannel`, `defaultMemoryLimit`, `defaultConnectionExceptionRetry` and `headersInLogContext` from the framework configuration to a `#[ServiceContext]` method returning `ServiceConfiguration`, drop `failFast` and `cacheConfiguration`, and replace `EcotoneLite`'s `configuration:` with `extensionObjects:` / `enabledModules:` / `namespaces:` / `licenceKey:` (§12).
6. Replace `Enqueue\Dbal\DbalConnectionFactory` references (§5).
7. Replace `AmqpDistributedBusConfiguration` with `DistributedServiceMap` (§6).
8. Move v1 projections to `#[Projection]` + `#[FromAggregateStream]`/`#[FromStream]`, give `#[ProjectionState]` parameters a default, and run `ecotone:projection:rebuild` for former v1 projections (§3).
9. Drop the persistence-strategy calls, and decide per aggregate whether it moves to `ecotone_event_stream` or stays on its 1.x table via `#[Stream(legacyStreamName: ...)]`; replace `#[FromStream(Aggregate::class)]` with `#[FromAggregateStream(Aggregate::class)]` (§4).
10. Rename `#[ServiceActivator]` to `#[InternalHandler]`, checking positional arguments (§7a). Add an explicit `endpointId` to every `#[Asynchronous]` `#[InternalHandler]` (§14).
11. Review changed defaults (§9) and set explicit values where the old behaviour is required.
12. Provide an Enterprise licence key if you use multi-tenancy (§2), `EventStreamEmitter::emit()` into a projection's own `#[Stream]` (§3), `changingHeaders: true` on internal handlers, `#[ChannelInterceptor]` or `#[Fetch]` (§14).
13. Adopting DCB decision models: create the `event_tags` tables and, only for a 1.x table a model writes into,
    relax its aggregate `NOT NULL` constraints — while still on 1.x; deploy 2.0 to every node; release `#[EventTag]`;
    run `ecotone:event-store:backfill-tags` and wait for it to finish; only then release `#[DecisionModel]` (§4).
14. Add the `version` column to `ecotone_document_store`, pass an expected version (or `DocumentStore::LAST_WRITE_WINS`) to `updateDocument()` / `upsertDocument()`, and add `#[Version]` to document-store aggregates that are changed concurrently (§14).
15. Add `ecotone:projection:init --all` to your deployment, after the database setup (§8). Add the `version` column to `ecotone_projection_state` (`ALTER TABLE ecotone_projection_state ADD COLUMN version INTEGER NOT NULL DEFAULT 0`), and check which commands and asynchronous endpoints now retry by default (§9, §14).
16. Add `ecotone:migration:database:setup --initialize` (or its `--sql` output, fed to your migration tool) to your deployment (§8).
17. Once it ships: move framework YAML/PHP config options into `#[ServiceContext]` (§12).
