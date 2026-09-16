# `Ecotone\Api` namespace restructuring: Attribute / ExtensionObject / Gateway / sub-modules

Branch `dgafka/ecotone-2-0-api-namespace-layout`, rebased onto `dgafka/ecotone-2-0-work` @ `14f55b61` (which merges
`dgafka/ecotone-2-0-drop-service-activator`, removing `#[ServiceActivator]` in favour of `#[InternalHandler]`).
Restructures the flat 116-class dump in `packages/Ecotone/Api` — 115 after the rebase drops `ServiceActivator`,
which no longer exists — and the 17-class mixed dump in `packages/Dbal/Api` into kind-scoped (`Attribute\`,
`ExtensionObject\`, `Gateway\`) and module-scoped (`Projecting\`) sub-namespaces, per the target layout the
maintainer specified in the task. All other packages' `Api` dirs are already flat per-module dumps and, per the
sizing rule below, are left as-is.

## 1. Rules applied

- **Kind rule**: `#[Attribute]`-marked classes that are cross-cutting messaging/modelling vocabulary go to
  `Attribute\*`; configuration/value objects go to `ExtensionObject\*`; gateway interfaces go to `Gateway\*`.
- **Sub-module rule wins over the kind rule**: a class that only makes sense once a feature is opted into is
  flat inside its module namespace, never nested one level deeper as `Module\Attribute\X`.
- Bias toward `Attribute\*` for everyday messaging/aggregate vocabulary; toward a sub-module when a user would
  only meet the class after opting into that feature (this is where the judgement calls below live).
- A package's own `Api` dir only gets kind sub-namespaces when it both mixes kinds **and** is large enough that
  the split reduces noise rather than adding it (see §4).

## 2. Judgement calls (flagged per the task)

These are the placements that were not a mechanical kind lookup and required a call:

| Class(es) | Placement | Reasoning |
|---|---|---|
| `EventSourcingAggregate`, `EventSourcingHandler`, `EventSourcingSaga` | `Attribute\*` | Used throughout core `Modelling` tests independent of the `PdoEventSourcing` package — in-memory event-sourced aggregates exist without it. This is cross-cutting aggregate-modelling vocabulary (parallel to `Aggregate`), not module-scoped, even though the name says "EventSourcing". |
| `FromStream`, `FromAggregateStream`, `StreamSource`, `Streaming` | `Projecting\*` | Usage-scope grep confirms these are used exclusively on `#[Projection]` classes to declare a stream source or opt into streaming projections. A user only meets them after building a projection. |
| `PartitionAggregateId`, `PartitionAggregateType`, `Partitioned`, `PartitionProvider` | `Projecting\*` | Usage-scope grep confirms all four are used exclusively inside partitioned-projection fixtures/tests. |
| `StateStorage` | `Projecting\*` | The name alone doesn't say "Projection", but usage-scope grep shows it is used exclusively for custom projection state storage. Called out explicitly because it's easy to misclassify as a generic `Attribute`. |
| `Distributed` | `Attribute\*` | Applied directly to ordinary `#[CommandHandler]`/`#[EventHandler]` methods to mark them distributable — it's a cross-cutting messaging attribute, not confined to a distribution sub-module (unlike `DistributedBus`/`DistributedBusHeader`/`DistributedServiceMap`, which the task's own table already places under `Gateway\`/`ExtensionObject\`). |
| `DistributedBus`, `DistributedBusHeader` | `Gateway\*` | Explicitly named as `Gateway\*` examples in the task's target-layout table. |
| `DistributedServiceMap` | `ExtensionObject\*` | Explicitly named as an `ExtensionObject\*` example in the task's target-layout table. |
| `DocumentStore` | `Gateway\*` | Explicitly named as a `Gateway\*` example in the task's target-layout table. |
| `ConsoleCommand`, `ConsoleParameterOption` | `Attribute\*` | No dedicated `Console` sub-module exists anywhere in `packages/Ecotone/src`; defining a custom console command is an everyday feature available to any application, not something gated behind opting into a bigger subsystem. |
| `ProjectingManager` | `Projecting\*` (not `ExtensionObject\*`) | It's a `CLASS`, but it's Projecting's own runtime service (`get`/`getPartitionProvider`/…), not a configuration value object — the sub-module rule wins. |
| `ProjectionRegistry` | `Projecting\*` (not `Gateway\*`) | It's an `interface`, but it's Projecting's own service locator (`get(): ProjectingManager`), not a general-purpose messaging gateway — the sub-module rule wins. |
| `ProjectionStateGateway` | `Projecting\*` (not `Attribute\Gateway`) | Name contains "Gateway", but it is a plain attribute referencing `ProjectingManager::class` as a default. The sub-module rule wins over the kind rule exactly as the task's own `ProjectionDelete` example illustrates — it does **not** become `Projecting\Gateway\ProjectionStateGateway`. |
| `DbalConnectionReference`, `DatabaseSetupManager`, `DbalDeadLetterBuilder` | `Dbal\ExtensionObject\*` | Same family as `ServiceConfiguration`/`SimpleMessageChannelBuilder` in core: `DefinedObject`/config value objects, not attributes or gateway interfaces. |

## 3. Full mapping: core (`packages/Ecotone/Api`, 115 classes) and Dbal (`packages/Dbal/Api`, 17 classes)

<!-- prettier-ignore -->
### `Ecotone\Api\Attribute\*` (74 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\AddHeader` | `Ecotone\Api\Attribute\AddHeader` | |
| `Ecotone\Api\After` | `Ecotone\Api\Attribute\After` | |
| `Ecotone\Api\Aggregate` | `Ecotone\Api\Attribute\Aggregate` | |
| `Ecotone\Api\AggregateEvents` | `Ecotone\Api\Attribute\AggregateEvents` | |
| `Ecotone\Api\AggregateType` | `Ecotone\Api\Attribute\AggregateType` | |
| `Ecotone\Api\Around` | `Ecotone\Api\Attribute\Around` | |
| `Ecotone\Api\Asynchronous` | `Ecotone\Api\Attribute\Asynchronous` | |
| `Ecotone\Api\Before` | `Ecotone\Api\Attribute\Before` | |
| `Ecotone\Api\BusinessMethod` | `Ecotone\Api\Attribute\BusinessMethod` | |
| `Ecotone\Api\ChangingHeaders` | `Ecotone\Api\Attribute\ChangingHeaders` | |
| `Ecotone\Api\ChannelInterceptor` | `Ecotone\Api\Attribute\ChannelInterceptor` | |
| `Ecotone\Api\ClassReference` | `Ecotone\Api\Attribute\ClassReference` | |
| `Ecotone\Api\CommandHandler` | `Ecotone\Api\Attribute\CommandHandler` | |
| `Ecotone\Api\ConfigurationVariable` | `Ecotone\Api\Attribute\ConfigurationVariable` | |
| `Ecotone\Api\ConsoleCommand` | `Ecotone\Api\Attribute\ConsoleCommand` | judgement call, see §2 |
| `Ecotone\Api\ConsoleParameterOption` | `Ecotone\Api\Attribute\ConsoleParameterOption` | judgement call, see §2 |
| `Ecotone\Api\ContentType` | `Ecotone\Api\Attribute\ContentType` | |
| `Ecotone\Api\Converter` | `Ecotone\Api\Attribute\Converter` | |
| `Ecotone\Api\Deduplicated` | `Ecotone\Api\Attribute\Deduplicated` | |
| `Ecotone\Api\Delayed` | `Ecotone\Api\Attribute\Delayed` | |
| `Ecotone\Api\DelayedRetry` | `Ecotone\Api\Attribute\DelayedRetry` | |
| `Ecotone\Api\Distributed` | `Ecotone\Api\Attribute\Distributed` | judgement call, see §2 |
| `Ecotone\Api\Enterprise` | `Ecotone\Api\Attribute\Enterprise` | |
| `Ecotone\Api\Environment` | `Ecotone\Api\Attribute\Environment` | |
| `Ecotone\Api\ErrorChannel` | `Ecotone\Api\Attribute\ErrorChannel` | |
| `Ecotone\Api\EventHandler` | `Ecotone\Api\Attribute\EventHandler` | |
| `Ecotone\Api\EventSourcingAggregate` | `Ecotone\Api\Attribute\EventSourcingAggregate` | judgement call, see §2 |
| `Ecotone\Api\EventSourcingHandler` | `Ecotone\Api\Attribute\EventSourcingHandler` | judgement call, see §2 |
| `Ecotone\Api\EventSourcingSaga` | `Ecotone\Api\Attribute\EventSourcingSaga` | judgement call, see §2 |
| `Ecotone\Api\Fetch` | `Ecotone\Api\Attribute\Fetch` | |
| `Ecotone\Api\Header` | `Ecotone\Api\Attribute\Header` | |
| `Ecotone\Api\Headers` | `Ecotone\Api\Attribute\Headers` | |
| `Ecotone\Api\Identifier` | `Ecotone\Api\Attribute\Identifier` | |
| `Ecotone\Api\IdentifierMethod` | `Ecotone\Api\Attribute\IdentifierMethod` | |
| `Ecotone\Api\IgnoreDocblockTypeHint` | `Ecotone\Api\Attribute\IgnoreDocblockTypeHint` | |
| `Ecotone\Api\IgnorePayload` | `Ecotone\Api\Attribute\IgnorePayload` | |
| `Ecotone\Api\InstantRetry` | `Ecotone\Api\Attribute\InstantRetry` | |
| `Ecotone\Api\InternalHandler` | `Ecotone\Api\Attribute\InternalHandler` | |
| `Ecotone\Api\IsAbstract` | `Ecotone\Api\Attribute\IsAbstract` | |
| `Ecotone\Api\LogAfter` | `Ecotone\Api\Attribute\LogAfter` | |
| `Ecotone\Api\LogBefore` | `Ecotone\Api\Attribute\LogBefore` | |
| `Ecotone\Api\LogError` | `Ecotone\Api\Attribute\LogError` | |
| `Ecotone\Api\MediaTypeConverter` | `Ecotone\Api\Attribute\MediaTypeConverter` | |
| `Ecotone\Api\MessageGateway` | `Ecotone\Api\Attribute\MessageGateway` | |
| `Ecotone\Api\ModuleAnnotation` | `Ecotone\Api\Attribute\ModuleAnnotation` | |
| `Ecotone\Api\NamedEvent` | `Ecotone\Api\Attribute\NamedEvent` | |
| `Ecotone\Api\NotUniqueHandler` | `Ecotone\Api\Attribute\NotUniqueHandler` | |
| `Ecotone\Api\OnConsumerStop` | `Ecotone\Api\Attribute\OnConsumerStop` | |
| `Ecotone\Api\Orchestrator` | `Ecotone\Api\Attribute\Orchestrator` | |
| `Ecotone\Api\OrchestratorGateway` | `Ecotone\Api\Attribute\OrchestratorGateway` | |
| `Ecotone\Api\Payload` | `Ecotone\Api\Attribute\Payload` | |
| `Ecotone\Api\Poller` | `Ecotone\Api\Attribute\Poller` | |
| `Ecotone\Api\Polling` | `Ecotone\Api\Attribute\Polling` | |
| `Ecotone\Api\Presend` | `Ecotone\Api\Attribute\Presend` | |
| `Ecotone\Api\Priority` | `Ecotone\Api\Attribute\Priority` | |
| `Ecotone\Api\PropagateHeaders` | `Ecotone\Api\Attribute\PropagateHeaders` | |
| `Ecotone\Api\QueryHandler` | `Ecotone\Api\Attribute\QueryHandler` | |
| `Ecotone\Api\Reference` | `Ecotone\Api\Attribute\Reference` | |
| `Ecotone\Api\RelatedAggregate` | `Ecotone\Api\Attribute\RelatedAggregate` | |
| `Ecotone\Api\RemoveHeader` | `Ecotone\Api\Attribute\RemoveHeader` | |
| `Ecotone\Api\Repository` | `Ecotone\Api\Attribute\Repository` | |
| `Ecotone\Api\Revision` | `Ecotone\Api\Attribute\Revision` | |
| `Ecotone\Api\Router` | `Ecotone\Api\Attribute\Router` | |
| `Ecotone\Api\Saga` | `Ecotone\Api\Attribute\Saga` | |
| `Ecotone\Api\Scheduled` | `Ecotone\Api\Attribute\Scheduled` | |
| `Ecotone\Api\ServiceContext` | `Ecotone\Api\Attribute\ServiceContext` | |
| `Ecotone\Api\Splitter` | `Ecotone\Api\Attribute\Splitter` | |
| `Ecotone\Api\TargetIdentifier` | `Ecotone\Api\Attribute\TargetIdentifier` | |
| `Ecotone\Api\TargetVersion` | `Ecotone\Api\Attribute\TargetVersion` | |
| `Ecotone\Api\TimeToLive` | `Ecotone\Api\Attribute\TimeToLive` | |
| `Ecotone\Api\Transformer` | `Ecotone\Api\Attribute\Transformer` | |
| `Ecotone\Api\Version` | `Ecotone\Api\Attribute\Version` | |
| `Ecotone\Api\WithoutDatabaseTransaction` | `Ecotone\Api\Attribute\WithoutDatabaseTransaction` | |
| `Ecotone\Api\WithoutMessageCollector` | `Ecotone\Api\Attribute\WithoutMessageCollector` | |

### `Ecotone\Api\ExtensionObject\*` (9 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\CombinedMessageChannel` | `Ecotone\Api\ExtensionObject\CombinedMessageChannel` | |
| `Ecotone\Api\DistributedServiceMap` | `Ecotone\Api\ExtensionObject\DistributedServiceMap` | judgement call, see §2 |
| `Ecotone\Api\ErrorHandlerConfiguration` | `Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration` | |
| `Ecotone\Api\ExecutionPollingMetadata` | `Ecotone\Api\ExtensionObject\ExecutionPollingMetadata` | |
| `Ecotone\Api\InstantRetryConfiguration` | `Ecotone\Api\ExtensionObject\InstantRetryConfiguration` | |
| `Ecotone\Api\PollingMetadata` | `Ecotone\Api\ExtensionObject\PollingMetadata` | |
| `Ecotone\Api\ServiceConfiguration` | `Ecotone\Api\ExtensionObject\ServiceConfiguration` | |
| `Ecotone\Api\SimpleMessageChannelBuilder` | `Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder` | |
| `Ecotone\Api\TestConfiguration` | `Ecotone\Api\ExtensionObject\TestConfiguration` | |

### `Ecotone\Api\Gateway\*` (9 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\CommandBus` | `Ecotone\Api\Gateway\CommandBus` | |
| `Ecotone\Api\DistributedBus` | `Ecotone\Api\Gateway\DistributedBus` | judgement call, see §2 |
| `Ecotone\Api\DistributedBusHeader` | `Ecotone\Api\Gateway\DistributedBusHeader` | judgement call, see §2 |
| `Ecotone\Api\DocumentStore` | `Ecotone\Api\Gateway\DocumentStore` | judgement call, see §2 |
| `Ecotone\Api\EcotoneClockInterface` | `Ecotone\Api\Gateway\EcotoneClockInterface` | |
| `Ecotone\Api\EventBus` | `Ecotone\Api\Gateway\EventBus` | |
| `Ecotone\Api\MessagePublisher` | `Ecotone\Api\Gateway\MessagePublisher` | |
| `Ecotone\Api\QueryBus` | `Ecotone\Api\Gateway\QueryBus` | |
| `Ecotone\Api\SerializerGateway` | `Ecotone\Api\Gateway\SerializerGateway` | |

### `Ecotone\Api\Projecting\*` (23 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\FromAggregateStream` | `Ecotone\Api\Projecting\FromAggregateStream` | judgement call, see §2 |
| `Ecotone\Api\FromStream` | `Ecotone\Api\Projecting\FromStream` | judgement call, see §2 |
| `Ecotone\Api\PartitionAggregateId` | `Ecotone\Api\Projecting\PartitionAggregateId` | judgement call, see §2 |
| `Ecotone\Api\PartitionAggregateType` | `Ecotone\Api\Projecting\PartitionAggregateType` | judgement call, see §2 |
| `Ecotone\Api\PartitionProvider` | `Ecotone\Api\Projecting\PartitionProvider` | judgement call, see §2 |
| `Ecotone\Api\Partitioned` | `Ecotone\Api\Projecting\Partitioned` | judgement call, see §2 |
| `Ecotone\Api\ProjectingManager` | `Ecotone\Api\Projecting\ProjectingManager` | judgement call, see §2 |
| `Ecotone\Api\Projection` | `Ecotone\Api\Projecting\Projection` | |
| `Ecotone\Api\ProjectionBackfill` | `Ecotone\Api\Projecting\ProjectionBackfill` | |
| `Ecotone\Api\ProjectionDelete` | `Ecotone\Api\Projecting\ProjectionDelete` | |
| `Ecotone\Api\ProjectionDeployment` | `Ecotone\Api\Projecting\ProjectionDeployment` | |
| `Ecotone\Api\ProjectionExecution` | `Ecotone\Api\Projecting\ProjectionExecution` | |
| `Ecotone\Api\ProjectionFlush` | `Ecotone\Api\Projecting\ProjectionFlush` | |
| `Ecotone\Api\ProjectionInitialization` | `Ecotone\Api\Projecting\ProjectionInitialization` | |
| `Ecotone\Api\ProjectionName` | `Ecotone\Api\Projecting\ProjectionName` | |
| `Ecotone\Api\ProjectionRebuild` | `Ecotone\Api\Projecting\ProjectionRebuild` | |
| `Ecotone\Api\ProjectionRegistry` | `Ecotone\Api\Projecting\ProjectionRegistry` | judgement call, see §2 |
| `Ecotone\Api\ProjectionReset` | `Ecotone\Api\Projecting\ProjectionReset` | |
| `Ecotone\Api\ProjectionState` | `Ecotone\Api\Projecting\ProjectionState` | |
| `Ecotone\Api\ProjectionStateGateway` | `Ecotone\Api\Projecting\ProjectionStateGateway` | judgement call, see §2 |
| `Ecotone\Api\StateStorage` | `Ecotone\Api\Projecting\StateStorage` | judgement call, see §2 |
| `Ecotone\Api\StreamSource` | `Ecotone\Api\Projecting\StreamSource` | judgement call, see §2 |
| `Ecotone\Api\Streaming` | `Ecotone\Api\Projecting\Streaming` | judgement call, see §2 |

### `Ecotone\Api\Dbal\Attribute\*` (8 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\Dbal\DbalParameter` | `Ecotone\Api\Dbal\Attribute\DbalParameter` | |
| `Ecotone\Api\Dbal\DbalQuery` | `Ecotone\Api\Dbal\Attribute\DbalQuery` | |
| `Ecotone\Api\Dbal\DbalWrite` | `Ecotone\Api\Dbal\Attribute\DbalWrite` | |
| `Ecotone\Api\Dbal\MultiTenantConnection` | `Ecotone\Api\Dbal\Attribute\MultiTenantConnection` | |
| `Ecotone\Api\Dbal\MultiTenantObjectManager` | `Ecotone\Api\Dbal\Attribute\MultiTenantObjectManager` | |
| `Ecotone\Api\Dbal\OnTenantActivation` | `Ecotone\Api\Dbal\Attribute\OnTenantActivation` | |
| `Ecotone\Api\Dbal\OnTenantDeactivation` | `Ecotone\Api\Dbal\Attribute\OnTenantDeactivation` | |
| `Ecotone\Api\Dbal\WithTenantResolver` | `Ecotone\Api\Dbal\Attribute\WithTenantResolver` | |

### `Ecotone\Api\Dbal\ExtensionObject\*` (8 classes)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\Dbal\DatabaseSetupManager` | `Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager` | judgement call, see §2 |
| `Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder` | `Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder` | |
| `Ecotone\Api\Dbal\DbalConfiguration` | `Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration` | |
| `Ecotone\Api\Dbal\DbalConnectionReference` | `Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference` | judgement call, see §2 |
| `Ecotone\Api\Dbal\DbalDeadLetterBuilder` | `Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder` | judgement call, see §2 |
| `Ecotone\Api\Dbal\DbalMessagePublisherConfiguration` | `Ecotone\Api\Dbal\ExtensionObject\DbalMessagePublisherConfiguration` | |
| `Ecotone\Api\Dbal\MultiTenantConfiguration` | `Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration` | |
| `Ecotone\Api\Dbal\OutboxForwardingMessageChannel` | `Ecotone\Api\Dbal\ExtensionObject\OutboxForwardingMessageChannel` | |

### `Ecotone\Api\Dbal\Gateway\*` (1 class)

| Old FQCN | New FQCN | Notes |
|---|---|---|
| `Ecotone\Api\Dbal\DeadLetterGateway` | `Ecotone\Api\Dbal\Gateway\DeadLetterGateway` | |

## 4. Other packages' `Api` dirs: left flat

Every other package's `Api` dir is already its own module namespace (e.g. `Ecotone\Api\Kafka\*`), not a flat
dump of the core namespace, so the only question was whether to add kind sub-namespaces inside it:

| Package | Files | Kinds present | Decision |
|---|---|---|---|
| `Amqp` | 3 | ExtensionObject ×2, Attribute ×1 | **Left flat.** This is the task's own worked example of what *not* to split: one lone attribute (`RabbitConsumer`) would get a single-class `Attribute\` folder in a 3-file package. |
| `Kafka` | 5 | ExtensionObject ×3, Attribute ×1, Gateway ×1 | **Left flat.** Judgement call: 5 files is still in the same "smaller package" bucket the task groups with Amqp; splitting would produce two more single-class folders (`Attribute\KafkaConsumer`, `Gateway\KafkaHeader`) for the same reason Amqp shouldn't split. |
| `Redis`, `Sqs` | 2 each | ExtensionObject only | Left flat — single kind, nothing to split. |
| `Symfony`, `Laravel` | 2 each | ExtensionObject only | Left flat — single kind. |
| `Tempest` | 1 | ExtensionObject only | Left flat — single file. |
| `DataProtection` | 2 | Attribute only | Left flat — single kind. |
| `JmsConverter` | 1 | ExtensionObject only | Left flat — single file. |
| `PdoEventSourcing` | 2 | ExtensionObject ×1, Attribute ×1 | **Explicitly untouched per the task**: keeps `Ecotone\Api\EventSourcing\Stream` / `EventSourcingConfiguration` as-is. |

No PSR-4 autoload changes were needed anywhere: `Ecotone\Api\` → `packages/Ecotone/Api` and `Ecotone\Api\Dbal\` →
`packages/Dbal/Api` already cover every new sub-namespace created inside those two packages.

## 5. A same-namespace resolution bug this restructuring exposed

A number of classes inside `packages/Ecotone/Api` and `packages/Dbal/Api` referenced sibling classes **without
a `use` import**, relying on PHP resolving the bare name against the current namespace — which worked only
because everything used to sit in one flat namespace. Splitting into `Attribute\`/`ExtensionObject\`/`Gateway\`/
`Projecting\` broke every one of these silently (attributes fail at reflection time, not at compile time, so
`vendor/bin/phpstan` at level 1 didn't catch it — only the full `phpunit` run did). Fixed by adding the missing
`use` statement in each of:

- `Gateway\CommandBus`, `Gateway\EventBus`, `Gateway\QueryBus` (needed `Attribute\Header`, `Attribute\Headers`,
  `Attribute\MessageGateway`, `Attribute\Payload`)
- `ExtensionObject\DistributedServiceMap` (needed `Gateway\DistributedBus`, `Attribute\Asynchronous`)
- `Projecting\ProjectionName`, `Projecting\ProjectionState`, `Projecting\PartitionAggregateId`,
  `Projecting\PartitionAggregateType` (all `extends Header`, needed `Attribute\Header`)
- `Attribute\Distributed` (needed `Gateway\DistributedBus`)
- `Attribute\Poller` (needed `ExtensionObject\PollingMetadata`)
- `Dbal\Attribute\DbalQuery`, `Dbal\Attribute\DbalWrite` (needed `Dbal\ExtensionObject\DbalConnectionReference`)

Worth carrying forward as a lesson for any future Ecotone namespace split: grep for bare (non-`use`-imported)
references to sibling class names before trusting a mechanical FQCN rename — `phpstan` won't catch it because
attribute arguments are compile-time syntax but resolve lazily via reflection.

## 6. Rebased onto the `#[ServiceActivator]` removal

This branch was rebased onto `dgafka/ecotone-2-0-work` @ `14f55b61`, which merges
`dgafka/ecotone-2-0-drop-service-activator` (removes `#[ServiceActivator]`; `#[InternalHandler]` is its sole
replacement). Conflict resolution:

- `packages/Ecotone/Api/Attribute/ServiceActivator.php` — deleted (class no longer exists).
- `packages/Ecotone/tests/Messaging/Fixture/InterceptedBridge/BridgeExampleIncomplete.php` — deleted (fixture
  removed upstream for the same reason).
- Every other conflicted file (65 files, mostly `#[ServiceActivator(...)]` call sites the other branch converted
  to `#[InternalHandler(...)]`, plus `upgrade/namespace-map-2.0.csv`) was resolved by taking the upstream
  (`--ours`) content and re-running this branch's namespace sweep over it, since upstream's content still had the
  pre-restructuring flat `Ecotone\Api\*` paths for every class this branch touches. The
  `namespace-map-2.0.csv` `ServiceActivator` row now reads
  `Ecotone\Messaging\Attribute\ServiceActivator,Ecotone\Api\Attribute\InternalHandler`, combining both branches'
  changes (renamed to `InternalHandler`, and `InternalHandler` itself lives under `Attribute\`).
- The core count dropped from 116 to 115 classes (74 `Attribute\*`, was 75) — see §3.

Full core (1364 tests) and Dbal (284 tests) suites, root `phpstan` and `php-cs-fixer` were re-verified green after
the rebase.
