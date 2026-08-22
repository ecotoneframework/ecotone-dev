# Upgrading to Ecotone 2.0

This guide lists every behaviour change between Ecotone 1.x and 2.0. Each entry describes how it worked before,
how it works now, and what to change in your application. Entries are grouped the way the work is grouped in
the release; within a group the most impactful changes come first.

Minimum requirements: PHP 8.2, Symfony 6.4+/7, Laravel 11+, Doctrine DBAL 4.

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
  `modulePackages` and now lists packages to load (Core and Asynchronous are implicit).
- A handler referencing an unregistered channel fails at bootstrap with
  `ConfigurationException: Message Channel "orders" used by #[Asynchronous] … is not registered. Register it with
  SimpleMessageChannelBuilder::createQueueChannel('orders') (or a broker-backed builder) as an extension object or from #[ServiceContext]`.
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

## 3. Projections: v1 removed, `ProjectionV2` renamed to `Projection`

**Before:** Two projection systems coexisted: the Prooph-based v1 (`Ecotone\EventSourcing\Attribute\Projection`,
`ProjectionManager`, `ProjectionRunningConfiguration`, `ecotone:es:*` console commands, `FlowTestSupport::initializeProjection()`,
`resetProjection()`, `stopProjection()`, `deleteProjection()`, `triggerProjection()`), and the new v2
(`Ecotone\Projecting\Attribute\ProjectionV2`).

**Now:** Only the new system exists and it is called `#[Projection]` (`Ecotone\Api\Attribute\Projection`). v1
classes, configuration and console commands are gone. Projection state lives in the v2 state table
(`ecotone_projection_state`), not the Prooph `projections` table.

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

- Rename `#[ProjectionV2]` → `#[Projection]` (namespace `Ecotone\Api\Attribute`).
- Replace `ProjectionRunningConfiguration` / `ProjectionSetupConfiguration` / `ProjectionLifeCycleConfiguration`
  with `#[Polling]`, `#[Streaming]`, `#[Partitioned]`, `#[Asynchronous]` on the projection class.
- Console: `ecotone:es:initialize-projection|reset-projection|delete-projection|run-projection` →
  `ecotone:projection:init|rebuild|backfill|delete`.
- Tests: `$ecotone->initializeProjection('x')` / `triggerProjection('x')` → projections are event-driven; for async/polling
  projections call `$ecotone->run('x')` (the channel name is the projection name). `ProjectingManager` is the programmatic API.
- Existing v1 projections need a one-time rebuild after upgrading (`ecotone:projection:rebuild <name>`), since position
  tracking moved to the new state table. Drop the legacy `projections` table afterwards.
- `EventSourcingConfiguration::withProjectionsTable()` and the projection-manager reference argument are removed.

## 4. Event Store: single global event log, DCB-ready, no Prooph

**Before:** `ecotone/pdo-event-sourcing` wrapped `prooph/pdo-event-store`. Four persistence strategies existed
(`simple`, `single`, `aggregate`, `partition`), with `single` deprecated. Aggregate concurrency was enforced through
`_aggregate_version` metadata.

**Now:** Ecotone ships its own DBAL event store. There is one global, totally ordered event log (partition strategy
semantics) with tags per event (aggregate id/type plus domain tags) and conditional append. Event ids are UUID v7.
Prooph classes (`Prooph\EventStore\*`, `MetadataMatcher`, `FieldType`, `Operator`) are no longer available.

**How to adapt:**
- Delete `withSingleStreamPersistenceStrategy()`, `withAggregateStreamPersistenceStrategy()`, `withSimpleStreamPersistenceStrategy()`
  and `withCustomPersistenceStrategy()` calls; only the partition/global-log layout remains.
- Existing `event_streams` data in `partition` or `single` layout is read as-is; `aggregate` (stream-per-aggregate) layout
  needs a migration that merges tables into the global log (a CLI command is provided: `ecotone:event-store:migrate-aggregate-streams`).
- `EventStore::load()` calls using `MetadataMatcher` become tag/type queries: `$eventStore->load(Query::forTags(['order' => '1'])->ofTypes([OrderPlaced::class]))`.
- Custom implementations of `Ecotone\EventSourcing\EventStore` must implement the new interface (`append()` with `AppendCondition`, `load()` with `Query`).
- `#[FromStream]` on aggregates is optional; the aggregate class name is the default stream/tag.

## 5. DBAL connections: Ecotone classes replace the Enqueue ones

**Before:** Connections were referenced by `Enqueue\Dbal\DbalConnectionFactory::class` (a vendored copy of
`enqueue/dbal`, exposed through composer `replace`). `DbalConnection::fromDsn()` returned an Enqueue factory.

**Now:** The queue transport classes live in `Ecotone\Dbal\Connection` (`DbalConnectionFactory`, `DbalContext`, `DbalProducer`,
`DbalConsumer`, `ManagerRegistryConnectionFactory`, …) and still implement the `queue-interop` interfaces. The default connection
reference name is `Ecotone\Dbal\DbalConnectionReference::DEFAULT` (which equals `Ecotone\Dbal\Connection\DbalConnectionFactory::class`);
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
- Custom code relying on `Interop\Queue\Context` from the DBAL connection should use `Ecotone\Dbal\DbalContext`.
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
    ->withEventMapping('ticket_service', 'ticket_service.events'),
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

Kept (still deprecated, scheduled for a later minor): `ServiceActivatorBuilder` (use `MessageProcessorActivatorBuilder` in new modules),
`ServiceConfiguration::withCacheDirectoryPath()`, `MessagingSystemConfiguration::buildMessagingSystemFromConfiguration()`.

If you implemented a custom `Module` / `AnnotationModule`, delete the `canHandle()` method and filter extension objects
with `ExtensionObjectResolver::resolve(MyConfig::class, $extensionObjects)` inside `prepare()`.

## 8. Database tables are no longer created on the fly

**Before:** `DbalTransactionInterceptor` and `DeduplicationInterceptor` created `ecotone_deduplication`, `ecotone_error_messages`,
`ecotone_enqueue` etc. during the first message and on MySQL committed the surrounding transaction implicitly
(`@TODO Ecotone 2.0 remove implicit commit`). PostgreSQL received a special-case branch.

**Now:** Tables are created only through the CLI (or your own migrations). Interceptors never commit implicitly; one
transaction wraps the whole message on every driver. Deduplication cleanup runs outside the handler transaction
(scheduled job), so it no longer holds row locks while your handler runs.

**How to adapt:**
- Run `ecotone:database:setup` on deploy, or `ecotone:database:dump-sql` to produce SQL for Doctrine Migrations / Laravel migrations.
- `EcotoneLite::bootstrapFlowTesting*()` still prepares tables automatically for in-memory/test connections. For integration tests
  against a real database call `$ecotone->getGateway(DatabaseSetupManager::class)->setup()` once in `setUp()`.
- Remove any code that relied on the implicit commit (e.g. MySQL DDL during a handler).

## 9. Changed defaults

| Setting | 1.x default | 2.0 default | Where to override |
|---|---|---|---|
| JMS: serialize `null` properties | off | on | `JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)` |
| JMS: native enum support | off | on | `->withDefaultEnumSupport(false)` |
| `SimpleMessageChannelBuilder::createQueueChannel()` delayable | `false` | `true` | `createQueueChannel('x', delayable: false)` |
| `ExecutionPollingMetadata::createWithTestingSetup()` messages handled | 1 | 100 | `->withHandledMessageLimit(1)` |
| Instant retries on asynchronous endpoints | disabled | enabled (3 attempts) | `InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)` |
| Module packages | all except explicitly skipped | Core + Asynchronous + what `withModulePackages()` lists; with no call, all installed packages load | `ServiceConfiguration::withModulePackages([...])` |

Delayable channels change in-memory behaviour: a message sent with `delay` is not visible to `run()` until the
clock passes the delay. Use `$ecotone->run('x', ExecutionPollingMetadata::createWithTestingSetup(), releaseAwaitingFor: Duration::seconds(5))`
or `TestConfiguration::createWithDefaults()->withSpyOnChannel()` to assert delayed messages.

## 10. Aggregate identifier resolution for queued messages

**Before:** When a message reached an aggregate handler without the `aggregate.id` header (messages queued by Ecotone < 1.60),
the identifier was resolved again from the payload at consumption time.

**Now:** The header is required on the consumer side; aggregate identifiers are resolved once, when the message is sent.

**How to adapt:** Drain queues produced by Ecotone versions older than 1.60 before upgrading, or re-publish them.

## 11. Routing keys and channel names

**Before:** An asynchronous channel name could not be equal to a routing key of a handler.

**Now:** Routing keys and message channels are separate namespaces; the same string may be used for both.

**How to adapt:** Nothing; remove workarounds that renamed channels to avoid the clash.

## 12. Framework configuration: `ServiceContext` only

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

**Now:** Everything you are meant to reference from application code is under an `Api` namespace:
- attributes → `Ecotone\Api\Attribute\*` (core) / `Ecotone\<Package>\Api\Attribute\*`
- extension objects returned from `#[ServiceContext]` → `Ecotone\Api\ExtensionObject\*` / `Ecotone\<Package>\Api\ExtensionObject\*`
- gateways/buses → `Ecotone\Api\Gateway\*` (`CommandBus`, `QueryBus`, `EventBus`, `DistributedBus`, `MessagePublisher`, ...)

Classes outside `Api` are `@internal` and may change in minor versions.

**How to adapt:** Run the provided Rector set (`vendor/ecotone/ecotone/upgrade/rector-2.0.php`) or apply the mapping table in
`upgrade/namespace-map-2.0.csv` with `sed`. Examples:

| 1.x | 2.0 |
|---|---|
| `Ecotone\Modelling\Attribute\CommandHandler` | `Ecotone\Api\Attribute\CommandHandler` |
| `Ecotone\Messaging\Attribute\Asynchronous` | `Ecotone\Api\Attribute\Asynchronous` |
| `Ecotone\Projecting\Attribute\ProjectionV2` | `Ecotone\Api\Attribute\Projection` |
| `Ecotone\Messaging\Config\ServiceConfiguration` | `Ecotone\Api\ExtensionObject\ServiceConfiguration` |
| `Ecotone\Dbal\Configuration\DbalConfiguration` | `Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration` |
| `Ecotone\Amqp\AmqpBackedMessageChannelBuilder` | `Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder` |
| `Ecotone\Modelling\CommandBus` | `Ecotone\Api\Gateway\CommandBus` |

## 14. Smaller behaviour changes

- `ServiceConfiguration::withSkippedModulePackageNames()` → `withModulePackages()` (see §1/§9).
- `EcotoneLite::bootstrapFlowTesting()` no longer registers a missing-handler interceptor for unknown routing keys; sending to an
  unknown routing key throws `DestinationResolutionException` as in production.
- `MessageHeaders::STREAM_BASED_SOURCED` header is replaced by the `#[StreamBasedSource]` attribute check.
- `ecotone/pdo-event-sourcing` composer package is renamed to `ecotone/event-sourcing`; `composer require ecotone/event-sourcing` and
  remove the old package. Class namespace `Ecotone\EventSourcing` is kept.
- `ecotone/lite-application` package is discontinued; use `ecotone/ecotone` `EcotoneLite::bootstrap()`.
- Laravel: `LaravelConnectionReference::defaultConnection()` resolves to the Ecotone DBAL factory (§5); `SHELL_VERBOSITY` handling in tests unchanged.
- OpenTelemetry: spans now carry `polledChannelName` and `routingSlip` attributes.

---

## Upgrade checklist

1. Upgrade to the latest 1.x first and fix every deprecation notice (`@deprecated` calls log via `trigger_error` in 1.330+).
2. `composer require ecotone/ecotone:^2.0` (and `ecotone/event-sourcing` instead of `ecotone/pdo-event-sourcing`).
3. Run the Rector set / namespace map (§13).
4. Replace `withSkippedModulePackageNames` with `withModulePackages`; remove `enableAsynchronousProcessing` and add `->run('<channel>')` in tests (§1).
5. Move framework YAML/PHP config options into `#[ServiceContext]` (§12).
6. Replace `Enqueue\Dbal\DbalConnectionFactory` references (§5).
7. Replace `AmqpDistributedBusConfiguration` with `DistributedServiceMap` (§6).
8. Rename projections, run `ecotone:projection:rebuild` for former v1 projections (§3).
9. Add `ecotone:database:setup` (or dumped SQL) to your deployment (§8).
10. Review changed defaults (§9) and set explicit values where the old behaviour is required.
11. Provide an Enterprise licence key if you use multi-tenancy (§2).
