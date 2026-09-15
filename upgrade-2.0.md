# Upgrading to Ecotone 2.0

This guide lists every behaviour change between Ecotone 1.x and 2.0. Each entry describes how it worked before,
how it works now, and what to change in your application. Entries are grouped the way the work is grouped in
the release; within a group the most impactful changes come first.

Minimum requirements: PHP 8.2 (8.4 for the Tempest integration), Symfony 6.4+, Laravel 11+, Doctrine DBAL 4 and,
where used, Doctrine ORM 3 with DoctrineBundle 2.12+. Laravel 9/10, DBAL 3 and ORM 2 are no longer supported.

**Status of this guide.** Sections 1, 2, 3, 4, 5, 6, 7, 9, 11, 13, 14 and 15 describe behaviour that is already in the
codebase. Sections 8 and 12 are **planned for 2.0 and not implemented yet** — they are marked individually below.
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
`#[Asynchronous]` handler is never executed inline — not in production, not in tests. The channel named in the
attribute must exist, otherwise bootstrap fails with `ConfigurationException`.

**How to adapt:**

```php
// 1.x test
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderHandler::class]);
$ecotone->sendCommand(new PlaceOrder('1'));
self::assertCount(1, $ecotone->sendQueryWithRouting('orders.all')); // handler ran inline

// 2.0 test
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderHandler::class],
    [new OrderHandler()],
    ServiceConfiguration::createWithDefaults()
        ->withExtensionObjects([SimpleMessageChannelBuilder::createQueueChannel('orders')])
);
$ecotone->sendCommand(new PlaceOrder('1'));
$ecotone->run('orders');                                            // consume the channel explicitly
self::assertCount(1, $ecotone->sendQueryWithRouting('orders.all'));
```

- Remove `enableAsynchronousProcessing` arguments; register the channel instead (extension object or `#[ServiceContext]`).
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
  Previously test bootstrap silently created an in-memory channel.
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

**Now:** Only the new system exists and it is called `#[Projection]` (`Ecotone\Api\Projection`). v1's
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
  `Ecotone\Api\ProjectionRegistry` instead and works with `ProjectionRegistry::get('order_list')`, which returns a
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

Tags, `AppendCondition` and Dynamic Consistency Boundary querying are **planned** on top of this layout; they are not
part of 2.0 as shipped — see §16.

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
- **`EventStreamEmitter`.** `emit()` writes to the emitting class's `#[Stream]`, defaulting to `ecotone_event_stream`;
  it no longer invents a `projection_<name>` stream. `linkTo($streamName, ...)` still takes an explicit target, but the
  stream must be declared by a `#[Stream]` attribute somewhere — an unknown name is a configuration error instead of a
  table created behind your back.
- **Tables are declared, not discovered.** Every stream table on the default connection is registered with
  `ecotone:migration:database:setup` under the `event_stream` feature. In tests and dev (`DbalConfiguration` automatic
  table initialization) a missing table is still created on first write.
  **TODO:** `ecotone:migration:database:setup` drives only the default connection, so a stream declared with a
  non-default `connectionReferenceName` is not created by the command yet — it is created on first write when automatic
  table initialization is on, otherwise create it yourself.
- `EventStreamingChannelAdapter::create(fromStream: ...)` takes a stream name, not an aggregate class; pass
  `aggregateType:` to filter.
- Custom implementations of `Ecotone\EventSourcing\EventStore` are unaffected — the interface did not change.

**Schema of `ecotone_event_stream`** (PostgreSQL; MySQL/MariaDB use generated columns for the three aggregate fields):

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

## 5. DBAL connections: Ecotone classes replace the Enqueue ones

**Before:** Connections were referenced by `Enqueue\Dbal\DbalConnectionFactory::class` (a vendored copy of
`enqueue/dbal`, exposed through composer `replace`). `DbalConnection::fromDsn()` returned an Enqueue factory.

**Now:** The queue transport classes live in `Ecotone\Dbal\Connection` (`DbalConnectionFactory`, `DbalContext`, `DbalProducer`,
`DbalConsumer`, `ManagerRegistryConnectionFactory`, …) and still implement the `queue-interop` interfaces. The default connection
reference name is `Ecotone\Api\Dbal\DbalConnectionReference::DEFAULT` (which equals `Ecotone\Dbal\Connection\DbalConnectionFactory::class`);
`DbalConnectionReference::defaultConnection()` returns the reference object. Framework references keep their factories
(`SymfonyConnectionReference::createForManagerRegistry('default')`, `LaravelConnectionReference::defaultConnection()`,
`TempestConnectionReference::default()`) and resolve to the new default. The `enqueue/dbal` composer replacement/conflict and the
`enqueue/dsn` dependency of `ecotone/dbal` are removed.

**How to adapt:**
- Replace `use Enqueue\Dbal\DbalConnectionFactory;` with `use Ecotone\Dbal\Connection\DbalConnectionFactory;` in service definitions
  (Symfony `services.yaml`, Laravel providers, `EcotoneLite` service arrays), e.g.
  `[DbalConnectionReference::DEFAULT => DbalConnection::fromDsn(getenv('DATABASE_DSN'))]` or `DbalConnectionFactory::class => ...`.
- Container service ids / reference names that were the literal string `Enqueue\Dbal\DbalConnectionFactory` must be renamed to
  `DbalConnectionReference::DEFAULT`; Ecotone no longer looks up the old id.
- `DbalConnection::fromDsn()` / `fromConnectionFactory()` return the Ecotone classes; type-hints on `Enqueue\Dbal\*` must be updated.
- Custom code relying on `Interop\Queue\Context` from the DBAL connection should use `Ecotone\Dbal\Connection\DbalContext`.
- AMQP, SQS and Redis packages still use `queue-interop` / `enqueue/*`; nothing changes there.

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
| `Ecotone\Messaging\Gateway\Converter\Serializer` | `Ecotone\Api\SerializerGateway` |
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

Kept (still deprecated, scheduled for a later minor): `ServiceActivatorBuilder` (use `MessageProcessorActivatorBuilder` in new modules),
`MessagingSystemConfiguration::buildMessagingSystemFromConfiguration()`.

If you implemented a custom `Module` / `AnnotationModule`, delete the `canHandle()` method and filter extension objects
with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)` inside `prepare()`. The base and marker
classes that concrete attributes extend (`EndpointAnnotation`, `IdentifiedAnnotation`, `ChannelAdapter`,
`MessageConsumer`, `StreamBasedSource`, …) are now `@internal`: application code never uses them, and custom modules
that type against them should expect them to change in minor versions.

## 8. Database tables are no longer created on the fly

> **Planned — not implemented yet.** The behaviour below is not in the codebase; nothing to do at this point.
>
> **TODO** — design and implementation plan: `docs/superpowers/specs/2026-08-28-database-setup-cli-design.md`
> (section "Implementation plan", 15 steps; research: `docs/superpowers/research/database-setup-cli/report.md`).
> The plan's step for removing the implicit commit was blocked by the Prooph store creating a table per stream at
> runtime; §4 removed that blocker. Awaiting maintainer answers to the plan's open questions.

**Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`, `ecotone_error_messages`,
`ecotone_enqueue` etc. during the first message and on MySQL committed the surrounding transaction implicitly
(`@TODO Ecotone 2.0 remove implicit commit`). PostgreSQL received a special-case branch.

**Now:** Tables are created only through the CLI (or your own migrations). Interceptors never commit implicitly; one
transaction wraps the whole message on every driver. Deduplication cleanup runs outside the handler transaction
(scheduled job), so it no longer holds row locks while your handler runs.

**How to adapt:**
- Run `ecotone:migration:database:setup` on deploy, or `ecotone:migration:database:dump-sql` to produce SQL for Doctrine Migrations / Laravel migrations.
- `EcotoneLite::bootstrapFlowTesting*()` still prepares tables automatically for in-memory/test connections. For integration tests
  against a real database call `$ecotone->getGateway(DatabaseSetupManager::class)->setup()` once in `setUp()`.
- Remove any code that relied on the implicit commit (e.g. MySQL DDL during a handler).

## 9. Changed defaults

| Setting | 1.x default | 2.0 default | Where to override |
|---|---|---|---|
| JMS: serialize `null` properties | off | on | `JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)` |
| JMS: native enum support | off | on | `->withDefaultEnumSupport(false)` |
| `SimpleMessageChannelBuilder::createQueueChannel()` delayable | `false` | `true` | `createQueueChannel('x', delayable: false)` |
| `ExecutionPollingMetadata::createWithTestingSetup()` messages handled | 1 | 100 | `createWithTestingSetup(amountOfMessagesToHandle: 1)` |
| Instant retries on asynchronous endpoints | disabled | enabled (3 attempts) | `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)` |
| Module packages | all except explicitly skipped | Core + Asynchronous + what `withModulePackages()` lists; with no call, all installed packages load | `ServiceConfiguration::withModulePackages([...])` |

Test-suite impact of the new defaults:
- A test that asserted "exactly one message handled per `run()`" now sees up to 100; pass
  `ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1)` (or `--handledMessageLimit=1` on `ecotone:run`).
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

## 13. Public API moved to `Api` namespaces

**Before:** User-facing attributes and configuration objects lived in module namespaces
(`Ecotone\Messaging\Attribute\*`, `Ecotone\Modelling\Attribute\*`, `Ecotone\Projecting\Attribute\*`, `Ecotone\Dbal\Attribute\*`,
`Ecotone\Messaging\Config\ServiceConfiguration`, `Ecotone\Dbal\Configuration\DbalConfiguration`, ...).

**Now:** Everything you are meant to reference from application code — attributes, `#[ServiceContext]` extension
objects, and gateways/buses alike — is flattened directly under a single `Ecotone\Api` namespace:
- core classes → `Ecotone\Api\<ClassName>` (e.g. `Ecotone\Api\CommandHandler`, `Ecotone\Api\ServiceConfiguration`,
  `Ecotone\Api\CommandBus`, `Ecotone\Api\QueryBus`, `Ecotone\Api\EventBus`, `Ecotone\Api\DistributedBus`,
  `Ecotone\Api\MessagePublisher`, `Ecotone\Api\Projection`)
- package classes → `Ecotone\Api\<Package>\<ClassName>` (e.g. `Ecotone\Api\Dbal\DbalWrite`,
  `Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder`, `Ecotone\Api\Kafka\KafkaMessageChannelBuilder`,
  `Ecotone\Api\Laravel\LaravelConnectionReference`, `Ecotone\Api\Symfony\SymfonyConnectionReference`,
  `Ecotone\Api\Tempest\TempestConnectionReference`, `Ecotone\Api\EventSourcing\EventSourcingConfiguration`,
  `Ecotone\Api\JMSConverter\JMSConverterConfiguration`, `Ecotone\Api\Redis\*`, `Ecotone\Api\Sqs\*`,
  `Ecotone\Api\DataProtection\*`)

There is no `Attribute` / `ExtensionObject` / `Gateway` mid-level segment — the category a class falls into does not
appear in its namespace.

Classes outside `Api` are `@internal` and may change in minor versions. `DistributedServiceMap` and `DistributedBusHeader` (formerly
`Ecotone\Modelling\Api\Distribution\*`) fold into the flat core namespace as `Ecotone\Api\DistributedServiceMap` /
`Ecotone\Api\DistributedBusHeader`; `KafkaHeader` (formerly `Ecotone\Kafka\Api\KafkaHeader`) becomes
`Ecotone\Api\Kafka\KafkaHeader`, consistent with every other Kafka class.

**How to adapt:** Replace the imports using the full old → new mapping in
[`upgrade/namespace-map-2.0.csv`](https://github.com/ecotoneframework/ecotone-dev/blob/2.0/upgrade/namespace-map-2.0.csv)
(two columns, `old_fqcn,new_fqcn`) — a find-and-replace or `sed` over your `use` statements covers it. Examples:

| 1.x | 2.0 |
|---|---|
| `Ecotone\Modelling\Attribute\CommandHandler` | `Ecotone\Api\CommandHandler` |
| `Ecotone\Messaging\Attribute\Asynchronous` | `Ecotone\Api\Asynchronous` |
| `Ecotone\Projecting\Attribute\ProjectionV2` | `Ecotone\Api\Projection` |
| `Ecotone\Messaging\Config\ServiceConfiguration` | `Ecotone\Api\ServiceConfiguration` |
| `Ecotone\Dbal\Configuration\DbalConfiguration` | `Ecotone\Api\Dbal\DbalConfiguration` |
| `Ecotone\Amqp\AmqpBackedMessageChannelBuilder` | `Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder` |
| `Ecotone\Modelling\CommandBus` | `Ecotone\Api\CommandBus` |

The full mapping is in `upgrade/namespace-map-2.0.csv`.

## 14. Smaller behaviour changes

- `ServiceConfiguration::withSkippedModulePackageNames()` → `withModulePackages()` (see §1/§9).
- **TODO:** `MessageHeaders::STREAM_BASED_SOURCED` is planned to be replaced by the `#[StreamBasedSource]` attribute
  check. Not done yet — the header still exists; nothing to change.
- `ecotone/lite-application` package is discontinued; use `ecotone/ecotone` `EcotoneLite::bootstrap()`.
- Laravel: `LaravelConnectionReference::defaultConnection()` resolves to the Ecotone DBAL factory (§5); `SHELL_VERBOSITY` handling in tests unchanged.
- **`#[Asynchronous]` requires an explicit `endpointId` on `#[InternalHandler]` and `#[ServiceActivator]`**, as it
  already did for `#[CommandHandler]`/`#[EventHandler]`. Before, a generated endpoint id was accepted and failed later
  with an unrelated missing-channel error. Bootstrap now throws a `ConfigurationException` naming the class and method,
  ending with `should have endpointId defined for handling asynchronously`.
  **How to adapt:** add it — `#[InternalHandler('orders.process', endpointId: 'orders.process.endpoint')]`, and use
  that id with `run()` / consumer commands.
- **`changingHeaders: true` on `#[InternalHandler]` / `#[ServiceActivator]` requires an Enterprise licence.** In this
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
  `#[LogError]`, to `Ecotone\Api\LogBefore`, `Ecotone\Api\LogAfter` and `Ecotone\Api\LogError` (§13).
- **New, Enterprise: `#[ChannelInterceptor('channelName')]`.** A method with this attribute runs as a pre-send
  interceptor for that exact channel, with the same `changeHeaders` / `precedence` semantics as `#[Before]` /
  `#[Presend]`. In 1.x the attribute existed but did nothing. Nothing to change unless you had it in code expecting it
  to be ignored; without a licence bootstrap throws `LicensingException`.

## 15. Testing and developer-experience changes

These changes make tests and error messages say what happens, so that failures point at the real cause. Most of them
rename test-support methods without aliases; the renamed methods are listed in each entry.

- **Retries may use a zero back-off.** `RetryTemplateBuilder::fixedBackOff(0)`, an exponential back-off starting at `0`
  and `#[DelayedRetry(initialDelayMs: 0)]` used to throw `Initial delay must be greater than 0`. A zero delay is now
  accepted: the failed message is sent back to its channel without a delivery delay, so a single
  `run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))` walks it through every retry and
  into the dead letter. Negative delays still throw, naming the value
  (`Retry initial delay must be 0 or greater, got -1 ms`).
  **How to adapt:** nothing. Tests that advanced the clock only to get past a 1 ms back-off can use `0` instead.
- **Retry exhaustion counts deliveries, not "retries".** With `maxRetryAttempts(3)` the handler is delivered 4 times
  (1 initial + 3 retries), but the log said `retried maximum number of \`4\` times` and the exception said
  `Message handling failed after 4 retry attempts`. They now read
  `Sending message \`…\` to dead letter channel after 4 failed deliveries (1 initial + 3 retries). Due to: …`,
  `No dead letter channel defined. Message failed after 4 failed deliveries (1 initial + 3 retries). …` and
  `Message handling failed after 4 failed deliveries (1 initial + 3 retries). …`. The number of deliveries is unchanged.
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
  | `#[DelayedRetry(initialDelayMs: 100, maxAttempts: 3)]` | `#[DelayedRetry(initialDelayMs: 100, maxRetries: 3)]` |

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

## 16. Planned 2.0 work still to be done (TODO)

These changes are designed but not implemented. Nothing here affects an upgrade today; each entry will become a
normal section with "How to adapt" steps when it ships.

| Work | What it changes | Design and implementation plan |
|---|---|---|
| Database setup CLI, no implicit commit (§8) | Tables created only by `ecotone:migration:database:setup` or dumped SQL; one transaction per message on every driver; deduplication cleanup moved out of the handler transaction; `status` and `dump-sql` commands | `docs/superpowers/specs/2026-08-28-database-setup-cli-design.md` · research `docs/superpowers/research/database-setup-cli/report.md` |
| `#[ServiceContext]`-only configuration (§12) | `ServiceContext` values are actually merged; framework config files keep only bootstrap keys | `docs/superpowers/specs/2026-08-28-servicecontext-only-config-design.md` · research `docs/superpowers/research/servicecontext-only-config/report.md` |
| DCB event store (§4) | Tags per event, tag queries and `AppendCondition` with optimistic concurrency on top of `ecotone_event_stream`; SQL-side projection filtering | `docs/superpowers/specs/2026-08-22-dcb-event-store-design.md` (section "Implementation plan") · research `docs/superpowers/research/dcb-event-store/report.md`. Written before §4 shipped: its Prooph-removal, single-log and package parts are done or superseded, so refresh the plan against the current store before starting |
| Simpler EcotoneLite testing | Flow tests load every installed package with in-memory test profiles, instead of Core only; in-memory queue channels provided automatically for `#[Asynchronous]` handlers, still consumed with `run()` | `docs/superpowers/research/ecotone-lite-testing-simplification/report.md` (section "Implementation sketch"; no final design yet) |
| Service cache directory | Replace the cache-directory setting on `ServiceConfiguration` with an explicit bootstrap parameter; shared cache-clear command | `docs/superpowers/research/service-cache-directory/report.md` (section "Implementation plan"; overlaps with §12) |

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
10. Add an explicit `endpointId` to every `#[Asynchronous]` `#[InternalHandler]` / `#[ServiceActivator]` (§14).
11. Review changed defaults (§9) and set explicit values where the old behaviour is required.
12. Provide an Enterprise licence key if you use multi-tenancy (§2), `EventStreamEmitter::emit()` (§3), `changingHeaders: true` on internal handlers or `#[ChannelInterceptor]` (§14).
13. Once they ship: move framework YAML/PHP config options into `#[ServiceContext]` (§12) and add `ecotone:migration:database:setup` (or dumped SQL) to your deployment (§8).
