# Conventions audit — outstanding findings

What four audits of `docs/coding-conventions.md` found and has **not** been actioned yet, with each item's
disposition. Actioned findings are not repeated here: they are in the git history (`4e7b8d830` merged the mechanical
set — rules 1, 2 partial, 6/6a, 10 partial, 14, 17).

Sources, all committed:

- `docs/superpowers/research/conventions-audit-a/report.md` — rules 2, 3, 5, 12, 17
- `docs/superpowers/research/conventions-audit-b/report.md` — rules 1, 4, 8, 9, 16
- `docs/superpowers/research/conventions-audit-c/report.md` — rules 6/6a, 7, 10/10a, 11, 14
- `docs/superpowers/research/guideline-conformance-audit.md` — a fourth, independent sweep over all 21 rules

**Read the audits as claims to verify, not as a work queue.** Every row below is one they, or this document, got
wrong, and each error would have cost real work:

| Audit claim | Reality |
|---|---|
| `FetchAggregateConverter.php` is one of two rule-7 violations: it carries `licence Apache-2.0` with an `if hasEnterpriseLicence()` inside | It carries `licence Enterprise` (`:21`) and the check at `:37` is the negative guard, an Enterprise file refusing to run unlicensed. Not rule 7; a rule 1c finding, under *Decided, sequenced* |
| None of rule 17's ten files are extended, so `final` is safe on all | `MariaDbEventStreamSchema extends MySqlEventStreamSchema`; `final` there does not compile |
| `MessageHeadersPropagatorInterceptor` has an unbalanced push/pop | Both pops are in a `finally`; the real gap is the missing leak test |
| `DbalTagTables` and `DbalEventStore` both never invalidate their memo | This document first "corrected" it to an asymmetry on `DbalEventStore::delete()`, and that was wrong too. `delete()` never drops the tag tables — they are shared, and it only deletes the stream's index rows — so delete-then-append was never affected. The table that goes stale is dropped by `ecotone:migration:database:delete`, and that left **both** memos stale. See the rule-3 rows under *Shipped since the audits* |
| `TracingChannelInterceptor` desynchronises every later span once a `preSend` misses its `afterSendCompletion` | Not reachable, and backwards: an unpopped push sits at the bottom of the stack and later pairs stay balanced. The proposed per-message keying breaks an existing test. See the rule-3 rows under *Shipped since the audits* |
| Rule 7's mixed-licence counts | Derived from a grep approximation; `bin/check-licence.php` was never executed (no PHP on host, no containers in that worktree) |
| A gateway named-argument bug: `EventStore::loadAggregateEvents()` fails through the gateway when a named argument skips defaulted parameters | A fully positional call failed identically — PHP resolves named arguments against the generated proxy's own signature before Ecotone sees them. The defect was `GenericType::accepts()`, and it broke every parameter documented as a list, not one gateway (see the row under *Open, no owner*) |
| Rule 5: "31 raw candidates, 24 zero-implementation, leaving ~7" | Audit a's 31 (exactly one implementor) and 24 (none) are **disjoint** counts, and this document read them as one set and a subset of it. Re-derived: 150 interfaces, 22 with no production implementation, 24 with exactly one — so the triage was 24 interfaces, not ~7. See *Rules 5 and 2 — triaged* |
| Rule 2: 9 nullable service dependencies | The detector only matched `?Service $x = null`. Eleven more constructors take a nullable service with **no default**, where every caller passes the `null` explicitly. Listed, not triaged, under *Rules 5 and 2 — triaged* |
| Rule 11: 100 of 141 test files added since 1.x declare a named fixture, so drift is growing | The count is real — 105 of the 150 such files on `a57882174` — but **no named class in any of them is referenced from another file**, resolved by fully-qualified name. Each is owned by exactly one test, which is what the rule's rationale asks for. A later brief cut the queue to 17 files with an indentation heuristic; that missed every handler whose attributes sit on its methods, and 5 of its 17 were 1.x-era files. See *Rule 11 — triaged* |

| `ConfiguredMessagingSystem::getNonProxyGatewayByName()` has no application or test caller, so `Gateway` and `GatewayProxyMethodReference` stay internal | The conclusion holds, the premise does not: `MessagingSystemConfigurationTest` calls it six times. That test builds gateways from internal `GatewayProxyBuilder`s to test the configuration itself, so it is a framework caller, and rule 12a still lets both types stay. `getGatewayList()`, whose element type is `GatewayProxyReference`, has the same shape |
| `FlowTestSupport::receiveMessageFrom()` is the one method through which `Message` leaks, and `InMemoryConsoleWriter` is an implementation-only import | **Five** methods name `Message`, and `getInMemoryConsoleWriter()` **returns** `InMemoryConsoleWriter`, which `ConsoleWriterInjectionTest` calls. The brief's signature list also missed `MessagingTestSupport`, returned by `getMessagingTestSupport()`, and `MessagePoller`, the parent of `PollableChannel`; both moved. See the rule-12a rows under *Open, no owner* |
| `ConfiguredMessagingSystem` is 92 files | 71 PHP files by exact FQCN or from its own namespace, plus the two `ProxyGeneratorTest` snapshots that record the generated proxy's constructor. The brief's `Message` 292 is right by FQCN alone; this document counts 299, adding the files that use it from `Ecotone\Messaging` itself |
| Expect pre-existing phpstan errors once `Api/` is analysed | None: level 1 over all twelve `Api/` directories reports 0 errors on base `41cdcb889` and after the move. A file with a missing class, dropped into `Api/` as a control, is reported, so the analysis does run |
| The console family is `ConsoleWriter` 24 files, `InMemoryConsoleWriter` 4, `ConsoleProgressBar` 3, `InMemoryConsoleProgressBar` 0 | By exact FQCN, or by short name from `Ecotone\Messaging\Console`, the defining file excluded: 15 (plus Tempest's generated-code heredoc), 3, 11 and 1. The brief's `ConsoleProgressBar` 3 and `InMemoryConsoleProgressBar` 0 are import counts that miss the eight writers and progress bars naming the interface from its own namespace, and `InMemoryConsoleWriter` itself; no exact measure gives 24 — a plain substring `ConsoleWriter` gives 26 files, `PlainConsoleWriter` and the like included |
| `Message` has three implementations, `DbalMessage`, `ErrorMessage` and `GenericMessage` | Two. `DbalMessage` implements `Interop\Queue\Message` (`packages/Dbal/src/Connection/DbalMessage.php:7`, `:13`), the enqueue transport's message, not Ecotone's |
| `Message` is 292 files | 298 PHP files by exact FQCN or from its own namespace on `71a512349`: 290 imports, 5 fully qualified, 3 from `Ecotone\Messaging`. By FQCN text alone, 295 |
| `Handler\Type` was proved implementation-only in wave 3, reached only inside `withEventsFor()`'s body | True of `FlowTestSupport` only, which is what wave 3 measured. Across `Api`, `ConversionService::convert()` and `canConvert()` take it and `MediaType::getTypeParameter()` returns it, and `ConversionServiceTest` calls `convert()` with `Type::create()` on the gateway it fetches from `EcotoneLite` — the held leak recorded under *Open, no owner* |
The two rule-3 audits also **contradict each other** on `PendingDeliveryRegistry`, and each missed a class the other
found. Where this document and an audit disagree, this document was verified against the code.

## Deferred by the maintainer

### Rule 3 — services carrying mutable state

Out of scope for now, recorded here in full so the verification is not repeated. Four items; 3b and 3c are
resolved, under *Shipped since the audits*:

| # | Site | Finding | Proposal |
|---|---|---|---|
| 3a | `packages/Ecotone/src/Messaging/Scheduling/Clock.php:16`, `:20` | `private static ?EcotoneClockInterface $globalClock`, set in the constructor, **never read anywhere** — a static global duplicating the container's singleton | Delete the property and the assignment. Two lines, no behaviour change. Separately: this file imports `Ecotone\Test\StaticPsrClock` into production code |
| 3d | `packages/Ecotone/src/Modelling/MessageHandling/MetadataPropagator/MessageHeadersPropagatorInterceptor.php:24`, `:26` | `currentlyPropagatedHeaders` and `causationChain` stacks on a container singleton. The balance is **correct** (both pops in a `finally`, `:64-65`); what is missing is a test gating the leak | Ship the leak test: two messages through one instance, asserting the second is unaffected, in the style of `DecisionModelStatelessExecutionTest`. The header-carried rewrite (the `DecisionModelLoadedState` pattern, precedent `6b42c7a9b`) is a **separate unit**: `getLastHeaders()` is exposed as an `#[InternalHandler]` on `GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL`, so components read the propagated headers *without holding the message*, and that out-of-band read is what forces state onto the service. `isPollingConsumer` is deliberately cross-message |
| 3e | `packages/Ecotone/src/Messaging/Channel/DeliveryConfirmation/PendingDeliveryRegistry.php` | **The audits contradict each other.** Audit a: fails rule 3 on every axis (accumulating array, static `WeakMap`, three mutable counters). Fourth audit: "by-design collector" | Leave the behaviour. Its `openScope()`/`closeScope()` semantics mean the state *is* the feature — the exemption rule 3 already grants `InMemory*` stores. Resolve by **documenting the exemption in rule 3's text**. It is `licence Enterprise`, so a wrong call costs licensed users |
| 3f | `ScalarToEnumConverter`, `EventSourcedRepositoryAdapter.php:180` | Filed under rule 3 by the fourth audit; they are **rule 13a** — a `ReflectionEnum` per conversion and a `ClassDefinition` per load instead of the memoizing registry | Real, and on the message path, but a different rule and a performance concern. Measure before changing |

Excluded from rule 3 by every audit, and correctly: the Tempest static caches (architecture-constrained by the
shared-container setup) and the `Type`/`MediaType` caches (value objects, never container-resolved).

## Open, no owner

| Rule | Finding | Disposition |
|---|---|---|
| 12a | **What the nine-type move deliberately left.** `EcotoneLite`, `FlowTestSupport` and `ConfiguredMessagingSystem` have since moved together (see *Shipped*); `ErrorMessage` is still internal although applications write error-channel handlers that receive it. `MessageHeaders` is in `Api` but still carries about ten framework-only statics (`unsetFrameworkKeys`, `unsetAsyncKeys`, `unsetTransportMessageKeys` and the like), and `ModulePackageList::getModuleClassesForPackage()` and `allPackages()` are module-registration plumbing now living in `Api` | Each is its own unit. **`ErrorMessage` no longer waits on anything.** `ErrorContext` and then `Message` moved into `Api` on their own (see *Shipped*), which leaves `ErrorMessage` in `src` implementing an `Api` interface and returning an `Api` type — the permitted direction, since no `Api` signature names `ErrorMessage`. What is left is the decision itself: an error-channel handler types its parameter `ErrorMessage` (`quickstart-examples/RefactorToReactiveSystem/src/Stage_3/Infrastructure/Messaging/ErrorHandler.php`), so an application names it, and nothing it names (`Message`, `MessageHeaders`, `ErrorContext`) would have to move with it. The `MessageHeaders` statics and the `ModulePackageList` match arm move to internal collaborators — untidiness, not a leak, since only the framework calls them |
| 1 | **Unlicensed `EventStreamEmitter::emit()` fails with a raw missing-header exception.** `upgrade-2.0.md` §3 documents `emit()` (not `linkTo()`) as Enterprise. Without a licence, a projection handler calling it gets `MessageHeaderDoesNotExistsException: Header with name projection.name does not exists` from `StreamNameMapper::process()` (`packages/PdoEventSourcing/src/Config/StreamNameMapper.php:27`): the mapper reads `projection.name` unconditionally, and only `EnterpriseProjectionNameHeader` sets it. Reproduced with a throwaway copy of `EventStreamEmitterStreamTest::test_emitting_with_stream_attribute_writes_to_its_table` without `licenceKey:`, not committed. Rule 7 left this behaviour unchanged, so it predates that unit. **No test pins the unlicensed case**: every `EventStreamEmitter` test passes `VALID_LICENCE`. The interaction between `StreamNameMapper` and the open-core emit path is therefore proved, and it is a refusal that names neither the licence nor `linkTo()` | Rule 1 wants a `LicensingException` naming `emit()`, the licence and `linkTo($streamName, …)` as the open-core way out, asserted in a test. Where the refusal sits is the maintainer's call — at the emit, or at bootstrap for a projection whose handler takes `EventStreamEmitter` (rule 1c), which would catch only that path, since `emit()` can be called from any handler |
| — | **The AMQP stream suite fails in `AMQP_IMPLEMENTATION=lib` mode, on base `1d4d0e196`.** `AmqpStreamChannelTest` reports `Tests: 28, Assertions: 63, Failures: 6, Skipped: 1` (`test_resend_moves_failed_message_to_end`, `test_commit_interval_with_prefetch_count`, `…_lower_than_commit_interval`, `test_commit_interval_working_correctly_with_execution_time_limit`, `…_with_message_limit`, `test_two_consumers_track_positions_independently`), and `AmqpStreamPositionTrackingTest` 1 failure. Identical before and after `0cf11224b`. The root `Amqp tests` suite never shows it: without the variable all 28 skip with "Stream tests require AMQP lib"; only `packages/Amqp`'s own `composer tests:ci` runs lib mode | Not chased. Whether it is this host's RabbitMQ or a regression needs a lib-mode run on CI's setup |
| 12a | **Two inward leaks held for the maintainer**, each an `Api` method the application calls that names an internal type — three until `Message` moved (see *Shipped*). `Handler\Type`: `ConversionService::convert()` and `canConvert()` take it and `MediaType::getTypeParameter()` returns it. `MessageChannelBuilder`: `DynamicMessageChannelBuilder::createWithSendOnlyStrategy()` and `withInternalChannels()` take it, and `createRoundRobin()` and `createRoundRobinWithDifferentChannels()` document their channel lists as it. Measured on `e72f9f202` as files under `packages/`, `Monorepo/` and `quickstart-examples/` naming the FQCN or using the short name from inside its own namespace, the defining file excluded: **`Handler\Type` 153, `MessageChannelBuilder` 31**. The brief carried 164 and 292; the 292 is a substring count that also matches `SimpleMessageChannelBuilder`, `DbalBackedMessageChannelBuilder` and every other `*MessageChannelBuilder` (289 files by plain substring, 33 by whole word) | Not implemented — each is a decision, not a move. `Type` is one of the framework's central abstractions and moving it rewrites a large part of the tree. **`MessageChannelBuilder` may be the wrong fix entirely: rule 12a says no module, builder, resolver or container plumbing moves into `Api`, and it is a builder, so moving it would break the rule it is meant to satisfy.** The alternatives are to change the `DynamicMessageChannelBuilder` signatures so they no longer take a builder, or to accept the leak and say so in rule 12a. The re-walk of every `Api` signature after `Message` moved found more; see the row below |
| 12a | **The `Api` inward surface is not closed after `Message` moved: four types remain in signatures an application calls.** Established by a walk of all 203 files under `packages/*/Api`, not by reading imports: each class-like is loaded and reflected, and for every public method — inherited ones included — the native parameter, return and default-value types, the `@param`/`@return`/`@throws` names resolved against the declaring file's imports, public property and constant types, and the parent class and interfaces are listed wherever they resolve to `Ecotone\*` outside `Ecotone\Api\*`. 279 such mentions, every one then sorted by who calls the method. Control: rerun with `Ecotone\Api\Messaging\Message` treated as internal, the walk lists exactly the five `FlowTestSupport` methods, `MessagingTestSupport`'s six `popRecorded…()`, `MessageChannel::send()`, `PollableChannel::receive()`, `MessagePoller::receiveWithTimeout()`, `DeadLetterGateway::store()`/`show()` and `getFailedMessage()` — what the unit set out to close, and one signature no record had. **Application-called, the internal type in the way:** (1) `Handler\Type`, taken by `ConversionService::convert()`/`canConvert()` and returned by `MediaType::getTypeParameter()`; `ConversionServiceTest` calls `convert(…, Type::create('object'), …)` on the gateway it fetches. Held, row above. (2) `MessageChannelBuilder`, taken by `DynamicMessageChannelBuilder::createWithSendOnlyStrategy()`/`withInternalChannels()` and documented as the element type of `createRoundRobin()`'s and `createRoundRobinWithDifferentChannels()`'s `$internalMessageChannels`. Held, row above. (3) **New:** `Ecotone\Enqueue\EnqueueMessageChannelBuilder`. `DbalBackedMessageChannelBuilder`, `AmqpBackedMessageChannelBuilder`, `RedisBackedMessageChannelBuilder` and `SqsBackedMessageChannelBuilder` inherit `withReceiveTimeout()`, `withAutoDeclare()`, `withFinalFailureStrategy()`, `withHeaderMapping()`, `withDefaultTimeToLive()`, `withDefaultDeliveryDelay()` and `withDefaultConversionMediaType()` from it, declared `: self`, so to static analysis each returns the internal parent. `quickstart-examples/MultiTenant/Symfony/AsynchronousEvents/src/Configuration/EcotoneConfiguration.php` declares `databaseChannel(): DbalBackedMessageChannelBuilder` and returns `DbalBackedMessageChannelBuilder::create('notifications')->withReceiveTimeout(100)`; tests chain these 46 times. It runs, since the object is the subclass, but phpstan in the application sees an `EnqueueMessageChannelBuilder` returned where a `DbalBackedMessageChannelBuilder` is declared. (4) **New:** `Ecotone\Projecting\ProjectionPartitionState`, returned by `ProjectingManager::loadState()`. `ProjectionRegistry::get()` returns the `ProjectingManager`, and an application fetches `ProjectionRegistry` with `getGateway()`, and `GapAwarePositionIntegrationTest` reads `loadState()->lastPosition` through it; the state's public `$status` names `ProjectionInitializationStatus`, also internal. **Reachable, but no application caller in the tree:** `EventSourcingConfiguration::withDefaults()`, inherited from `Ecotone\Modelling\BaseEventSourcingConfiguration` as `: self` with `new self()`, so it returns the internal base and not an `EventSourcingConfiguration`; `KafkaPublisherConfiguration`'s public constructor and `withHeaderMapper(string|HeaderMapper)`, where every caller goes through `createWithDefaults()` or passes a string; `ProjectingManager::getPartitionProvider(): PartitionProvider`, called only by `PartitionBatchExecutorHandler`. **Named, but every value an application passes is an `Api` type:** `#[Asynchronous]`'s `$asynchronousExecution`, documented as `AsynchronousEndpointAttribute[]`, implemented only by `DelayedRetry`, `ErrorChannel`, `WithoutDatabaseTransaction` and `WithoutMessageCollector`; `MultiTenantConfiguration::createWithDefaultConnection(string|ConnectionReference …)`, whose seven concrete references are all in `Api`. **Framework-called, which rule 12a permits:** `compile()`/`getDefinition()` and the `Definition`, `Reference`, `MessagingContainerBuilder`, `DefinedObject`, `CompilableBuilder` and channel-builder contracts; `getHeaderMapper()`; `getInboundChannelAdapter()`/`getOutboundChannelAdapter()`; `ConfiguredMessagingSystem::getNonProxyGatewayByName()`/`getGatewayList()`; the constructors of `FlowTestSupport`, `ProjectingManager` and `DatabaseSetupManager`; `DbalDeadLetterBuilder`'s inherited `with…()` and `getInterceptedInterface()` (applications use only its constants and `getChannelName()`); `ErrorHandlerConfiguration::getDelayedRetryTemplate()`; `RetryTemplateBuilder::build(): RetryTemplate`, called only by `RetryTemplateTest` and `DelayedRetryBackOffTest`, unit tests of the retry policy itself; `OutboxForwardingMessageChannel::getEmbeddedSourceChannelBuilder()`; and the static factories `DecisionModelConcurrencyException` inherits from `MessagingException`. **Not a type in the way:** scalar defaults read from an internal constant (`GatewayProxyBuilder::DEFAULT_REPLY_TIMEOUT` on `#[MessageGateway]`/`#[BusinessMethod]`, `NullableMessageChannel::CHANNEL_NAME` on `#[Scheduled]`, `DeduplicationModule`'s on `DbalConfiguration::withDeduplication()`), `@throws` tags (`MessagingException`, `InvalidArgumentException`, `ConfigurationException`), and the internal parents of attributes and references — `WithExpression`, `InputOutputEndpointAnnotation`, `EndpointAnnotation`, `IdentifiedAnnotation`, `MessageConsumer`, `StreamBasedSource`, `ChannelAdapter`, `Logger`, `ConnectionReference` — none of which has an inherited method an application calls with an internal type. **Outside the question, recorded because the walk passed them:** an application still constructs or catches three internal types no `Api` signature names — `MessageBuilder`/`GenericMessage`, the only way to build the `Message` that `sendMessageDirectToChannel()` takes (all 10 test files calling it do); `ErrorMessage` (row at the top of this table); and `Ecotone\Messaging\Support\ConcurrencyException`, the parent of `DecisionModelConcurrencyException`, caught in 8 test files; and Ecotone's own flow tests configure snapshots by passing the internal `BaseEventSourcingConfiguration` as an extension object | Recorded, nothing moved, as the brief required. (3) and the `withDefaults()` case are a return-type fix rather than a move — `static` in place of `self` — since rule 12a keeps builders out of `Api`. (4) is a move, of a type with one internal enum beside it, or a narrower return. The maintainer's call for each |
| 12a | **Rule 12a's text and the tree disagree on same-namespace imports.** The rule says to `use`-import a sibling `Api` class "even from the same namespace tree". Across `packages/*/Api` after this unit, 49 of 54 references to a sibling in the same namespace are bare, files from the six- and nine-type moves among them, and php-cs-fixer's `no_unused_imports` deletes such an import whenever it runs over the file. The `EcotoneLite` move followed the tree: `ConfiguredMessagingSystem` names `MessageChannel`, `PollableChannel` names `MessageChannel` and `MessagePoller`, and `FlowTestSupport` names `MessagingTestSupport`, none of them imported. The rule's own case — an attribute argument naming a sibling, which resolves only at reflection time — does not arise in any of the seven | Rule text not edited, as the brief required. The maintainer's call: narrow the rule to attribute arguments, or exclude `Api/` from `no_unused_imports` and restore the imports |
| 12a | **A type moves with the types its public signatures name, or the leak only moves one layer down.** Found during the six-type move: `DatePoint` returns and takes `Duration` and `SleepInterface::sleep()` takes one; `MetadataMatcher::withMetadataMatch()` takes `Operator` and `FieldType`, and `create()` refuses entries that are not those enums. Moving the parent alone would have left the tree looking resolved with a new `Api` → `src` reference one hop further out — the same failure as moving `DatePoint` and leaving `SleepInterface`, its parent, behind. `TimeSpan::toDuration()` had already been naming `Duration` since the nine-type move, unnoticed for that reason | **Applied to this unit, rule text not edited.** Before each move, the types in the moved type's public signatures were listed and either moved with it or shown not to leak. The walk stops at three boundaries: a held type (`Message`, `Handler\Type`, `MessageChannelBuilder`) is recorded, not moved; a large transitive type is asked about first; and a type named only in a method the framework calls stays internal, as rule 12a permits — which is why `MetadataMatcher::getDefinition()` keeps `Definition` internal, and why the abstract `Ecotone\Messaging\Handler\Logger\Logger` that `#[LogBefore]`, `#[LogAfter]` and `#[LogError]` extend stays in `src`: an application never names it, and only the framework reads `getLogLevel()` and `isLogFullMessage()`. `Duration`, `Operator` and `FieldType` have no imports of their own, so each walk ended one hop out |

## Decided, sequenced

| Rule | Decision |
|---|---|
| 1c | **Shipped: an unlicensed `#[Fetch]` is refused at bootstrap.** The maintainer chose bootstrap. `VerifyEnterpriseLicenceForFetchedAggregates`, run from `MessagingSystemConfiguration` beside `VerifyEnterpriseLicenceForClosureExpressions` and before it, refuses every `FetchAggregateConverter` definition in the container when unlicensed, so it holds on every path that compiles one; `FetchAggregateConverter` no longer takes `LicenceDecider`. The message is unchanged — attribute, parameter, class and method, and both ways out. `FetchAggregateTest` proves it on a class-routed and a routed command handler, an event, asynchronous event and query handler, an aggregate and a saga handler, a `#[Before]` and an `#[Around]` interceptor, and a `#[ConsoleCommand]`, none of them ever called. `#[DecisionBoundary]` is not a path: it already refuses `#[Fetch]` at bootstrap with its own `ConfigurationException`. `upgrade-2.0.md` §14 records the change |
| 9 | **Conformance suite shipped; its findings are the queue.** `EventStoreConformanceTest`, `TaggedEventStoreConformanceTest` (`packages/PdoEventSourcing/tests/Conformance/`) and `DocumentStoreConformanceTest` (`packages/Dbal/tests/Conformance/`) run every case against each in-memory wiring and DBAL, on PostgreSQL, MySQL, MariaDB and SQLite. Each suite's `KNOWN_DIVERGENCES` ratchet skips a recorded case and fails once it stops diverging. What the suite found, what each side does, and the two seams below are in `docs/superpowers/specs/2026-10-01-conformance-suite-report.md`. **T1 is closed** — `bootstrapFlowTestingWithEventStore()` indexes `#[EventTag]` again; the report says how. **D5 is closed** — a DBAL array document naming no class is read back as a plain array, so mixed value types survive; the report says how. **D4 is decided as a documented engine limitation** — the id inherits the database's default collation, `upgrade-2.0.md` states it with the opt-in `ALTER`, and the entry stays with the engines named; the report weighs the options. **Next:** a maintainer decision per remaining divergence (E1–E9, E11, E13, D1, D3). **Deduplication and dead letter are not seams yet:** `DeduplicationInterceptor` and `DbalDeadLetterHandler` are concrete DBAL classes, so an in-memory implementation first needs an interface extracted and the policy moved to core — a design change sketched in the report, not built |
| 8 | **Shipped where a version can be observed; four limits of the rule and one misnamed opt-out found.** See *Rule 8* below |

### Rule 8 — what shipped, and four limits of the rule as written

**The finding: "always use optimistic locking" is achievable today on two of five storage paths** — the DBAL event
store and the document store. On the other three it describes an aspiration, not a convention the tree follows:
projection state is a work lease, not a record; three of the four state-stored backends have no version to compare
for an aggregate without `#[Version]`; Eloquent never persists `#[Version]`; and Tempest cannot observe whether a
guarded update matched. Each limit below carries its evidence, so none of them is re-filed as a violation.

**Shipped** (`0f47c62f6`, `b403ebfb8`, `4036d4c20`, `8c2d96d8b`):

- **E10 closed.** `DbalEventStore` reads the aggregate's current version before appending under
  `AppendCondition::forAggregate()`, on the plain path and on the tagged (DCB) path — the tagged path had the same
  hole and was recorded nowhere; `TaggedEventStoreConformanceTest::test_appending_a_tagged_event_under_a_stale_aggregate_version_is_rejected`
  proves it. `dbal` is removed from E10, which is now gone from `KNOWN_DIVERGENCES`; the case runs on PostgreSQL,
  MySQL, MariaDB and SQLite. Both stores raise `ConcurrencyException::forStaleAggregate()`
- **`DocumentStore` break.** `updateDocument()` / `upsertDocument()` take a required `int $expectedVersion`;
  `DocumentStore::LAST_WRITE_WINS` is the named opt-out, `getDocumentVersion()` the reload read, a `version` column the
  DBAL storage (a 1.x table is refused with the `ALTER TABLE`). Nine cases in `DocumentStoreConformanceTest`
- **Document-store and in-memory aggregate repositories** check `$versionBeforeHandling`, proved by
  `StateStoredRepositoryConformanceTest` (new; in-memory, document store over in-memory and over DBAL). It exposed a
  fake version: `StateStoredRepositoryAdapter::save()` reported `0` after a creation and for every aggregate without
  `#[Version]`, which `outputChannelName` chains read back as `TARGET_VERSION` — fixed. It also records **R1**: a
  refused save leaves the handler's change in the stored instance on both in-memory rows (D3's root cause)

**Limit 1 — projection state is a work lease, not a record write. `DbalProjectionStateStorage` keeps `FOR UPDATE`.**
The second run of a partition must *wait and continue from where the first got to*, not fail; a version predicate
turns that benign catch-up into a failed command for a synchronous projection, whose transaction is the command's.
On MySQL and MariaDB the lock also makes the read current: under REPEATABLE READ a plain `SELECT` returns the
transaction's old snapshot. Proved by a prototype (not committed): `FOR UPDATE` removed and the save guarded on the
position read, `ProjectingConcurrencyTest::test_interleaved_commands_sees_gaps` went **red on MySQL and MariaDB** —
TX1's `PlaceOrder` failed with the prototype's `ConcurrencyException` where it succeeds today — and stayed green on
PostgreSQL (READ COMMITTED re-reads). The brief's "pessimistic locking was tried here and removed" did not hold:
`c064ca8b5` and `ca8a1b0fc` are event-store and tag locking, not projection state.

**Limit 2 — an aggregate without `#[Version]` has no version to check, on every backend.** Its
`$versionBeforeHandling` is `null` after a load. The document-store repository passes `LAST_WRITE_WINS` for it, named
at the call site rather than laundered through a fetched version; in-memory skips the check; Eloquent and Tempest
have no column. Refusing such aggregates at bootstrap was weighed and rejected: all 11 aggregates and sagas the 7
quickstarts store in the document store lack `#[Version]`, and refusing in one backend while the in-memory one every
flow test uses does not would pass tests and fail at boot. Creation is still checked where the repository runs
before the row exists (document store, in-memory).

**Limit 3 — `#[Version]` on an Eloquent aggregate is a silent no-op.** Measured with a throwaway test (not
committed, it would pin the no-op): a model hydrated by `EloquentRepository::findBy()` reads its `#[Version]` as
**`0` on every load** — the declared property's default, never hydrated from the column — and
`EloquentRepository::save()` with the property at `7` leaves the `version` column at **`0`**: Ecotone's enrichment
writes the declared PHP property, which Eloquent never persists. So `$versionBeforeHandling` is `0` on every load
and a check has nothing to compare. **Decided by the storage-defects unit: a documented limitation**, after the two
shapes ahead of it were measured and ruled out:

- *Making it work* needs a persistence convention an application has to know. Ecotone reads and writes `#[Version]`
  through `getVersion()` / `setVersion()` or else the declared property (`PropertyReaderAccessor`,
  `PropertyEditorAccessor`), and the attribute only targets a property, so the repository would have to copy a column
  named after that property into it on `findBy()` — a declared property shadowing an Eloquent attribute of the same
  name. And the guarded write cannot go through `Model::save()`: `performUpdate()` discards the affected-row count, so
  `UPDATE … AND version = ?` matching nothing is indistinguishable from success, and bypassing `save()` drops the
  model's `saving` / `updating` / `saved` events and its timestamps
- *A bootstrap refusal* cannot tell when it applies. The repository serving an aggregate is chosen at runtime, by the
  first `canHandle()` in `AllAggregateRepository` — an application's own `#[Repository]` first — so compile time cannot
  know whether Eloquent will store a given model, and the refusal would fire on the very configuration it should point
  to. Blast radius, measured anyway: the quickstarts hold 2 Eloquent aggregates
  (`Laravel/Projection/EloquentReadModel` `UserReadModel`, `MultiTenant/Laravel/Aggregate` `Customer`) and `#[Version]`
  appears nowhere in `quickstart-examples`, so it would have broken none of them

`upgrade-2.0.md` states the no-op and the two ways out: a plain class in the document-store repository, or the
application's own `#[Repository]` for the model, running the guarded update itself.
`EloquentRepositoryTest::test_an_application_repository_for_an_eloquent_model_is_chosen_over_eloquent_and_given_the_version_it_loaded`
pins what that second way out relies on — the application's repository wins over Eloquent's, and `save()` receives the
version `findBy()` loaded with the property moved past it; with the application's `canHandle()` returning `false`, the
same test reaches `EloquentRepository` and fails on the table.

**Limit 4 — Tempest cannot observe a guarded update.** `UpdateQueryBuilder::execute()` returns `?PrimaryKey`,
`Database::execute()` returns `void`, `GenericDatabase` keeps the `PDOStatement` in a private property, and
`grep -rn "rowCount\|affected" vendor/tempest/framework/packages/database/src` has **0 hits** — so
`UPDATE … WHERE version = ?` matching nothing is indistinguishable from success, and a stale write is lost silently.
`RETURNING` works on PostgreSQL, SQLite and MariaDB but not MySQL; driving Tempest's raw `Connection::prepare()`
means re-implementing its private, serializer-aware `resolveBindings()` and losing `onDatabase()` tags; read-then-write
leaves the race rule 8 forbids. `ecotone/dbal` is only a composer *suggest* of `ecotone/tempest`, so DBAL is no
fallback. **Ready to send upstream** (the maintainer's call, not filed):

> **Expose the affected-row count of an update.** `UpdateQueryBuilder::execute()` returns `?PrimaryKey`,
> `Database::execute()` returns `void`, and `GenericDatabase` keeps the executed `PDOStatement` private, so a caller
> cannot learn how many rows an `UPDATE` matched. Optimistic locking needs exactly that: `UPDATE … WHERE id = ? AND
> version = ?` matching zero rows is the conflict signal. Ecotone wants to version-check aggregates stored as Tempest
> models and cannot without it. A `Database::execute()` returning the affected-row count, or an
> `UpdateQueryBuilder::executeAndCount(): int`, would be enough.

**Defect 5 — `withoutOptimisticLockFor()` is misnamed. Found by the full-suite gate, not by reading the code** — the
other four were visible to inspection, this one needed the suite. It opts an aggregate out of the DCB **counter tag**,
not out of optimistic locking: its own refusals say so (`AggregateCounterTagGuard`: "only a state-stored aggregate
has a counter tag to opt out of"), while its name and
`StateStoredAggregateCounterDbalTest::test_an_aggregate_excluded_from_the_optimistic_lock_keeps_last_write_wins_across_two_connections`
promised last-write-wins. Once the document-store repository checked `#[Version]`, that test failed: the excluded
aggregate declares `#[Version]`, and the repository refused the stale save. Decided: `#[Version]`, declared on the
class, wins over an exclusion listed on a distant Enterprise configuration — the alternative would make open-core
`StateStoredRepositoryAdapter` read an Enterprise setting (rule 7). The test is renamed to assert the refusal
(`test_a_versioned_aggregate_excluded_from_the_counter_tag_is_still_version_checked_across_two_connections`) and
`test_an_aggregate_without_a_version_excluded_from_the_counter_tag_keeps_last_write_wins_across_two_connections` pins
the other half. **Recommended rename** (breaking Enterprise API, the maintainer's call, not done):
`DynamicConsistencyBoundaryConfiguration::withoutCounterTagFor()`, with the guard messages and `upgrade-2.0.md`
following it.

**Draft sentences for rule 8** (for the maintainer; the rule is not edited here):

> Optimistic locking governs **writes to a record that two writers can decide on**. It does not govern a **work
> lease** — a row that serialises one worker over a queue, such as a projection partition's position — where the
> second worker must wait and continue rather than fail; there, `SELECT … FOR UPDATE` is the correct tool, and on
> MySQL/MariaDB also what makes the read current.

> A version check needs a version to check. An aggregate without `#[Version]` is last-write-wins on every backend, and
> a call that deliberately writes unchecked says so by name (`DocumentStore::LAST_WRITE_WINS`) rather than by
> fetching the current version and passing it back. A backend that cannot report whether a guarded write matched —
> Tempest today — cannot hold the rule, and a check it cannot observe is worse than none.

**Noticed on the way, left alone:**

- The Laravel test application (`packages/Laravel/tests/Application`) saved the measurement's Eloquent aggregate
  through the **in-memory** repository, not `EloquentRepository` — with `'test' => true` and still with it switched to
  `false` — and the renamed title never reached the table. `EloquentIntegrationTest::test_executing_command_action`
  runs in the same application, so its cancel round trip most likely proves `InMemoryStateStoredRepository` rather
  than Eloquent persistence. Not verified on that test, and why the switch did not change the repository was not
  chased
- `upsertDocument()` under `expectedVersion: 0` that loses a race to a concurrent insert reports "at version 1 now";
  the duplicate key aborts a PostgreSQL transaction, so the current version cannot be re-read there

## Deliberately last — judgement-heavy

- **Rule 11** — triaged; see *Rule 11 — triaged* below. **There is no 100-file unit here**: none of the named
  classes in the test files added since 1.x is used outside the file that declares it.

Rules 5 and 2 were triaged together after rule 7's first split, since splitting Enterprise behaviour behind an
interface *creates* the two-implementation seam rule 5 permits. What they found is the next section.

## Rules 5 and 2 — triaged

### Rule 5 — the count, re-derived

**Method.** Every `interface` declared under `packages/*/src` and `packages/*/Api`, matched against every class that
implements it **transitively** — through a parent class, through a sub-interface, and including anonymous classes —
with production implementations (`src`, `Api`) counted apart from test ones. Names resolved through each file's
namespace and imports. Result: **150 interfaces, 22 with no production implementation, 24 with exactly one.** The
earlier "31 / 24 / ~7" read two disjoint audit counts as a set and its subset; audit a's own scan also walked direct
`implements` clauses only, which is why its zero-implementation list held `MessageChannel`, `Module` and the like.

**The 22 with no production implementation:**

| Shape | Interfaces | Outcome |
|---|---|---|
| Gateway — the implementation is generated at runtime by `GatewayProxyBuilder` | `QueryBus`, `DistributedBus`, `MessagePublisher`, `SerializerGateway`, `DeadLetterGateway`, `ConsoleCommandRunner` (`Messaging\Gateway`), `EnrichGateway`, `DistributionEntrypoint`, `EventStreamEmitter`, `MessagingTestSupport` | Outside rule 5 |
| Constant holder | `AmqpHeader`, `EnqueueHeader`, `KafkaHeader`, `DbalHeader`, `DistributedBusHeader`, `Precedence`, `PrecedenceChannelInterceptor`, `AggregateMessage` | Outside rule 5 |
| **Neither** — referenced nowhere | `Modelling\MessageBus`, `BeforeSendGateway` | **Deleted** (`1cd6093b5`) |
| **Neither** — an extension point nothing uses | `LazyRepositoryBuilder` (only a test fixture, `AppointmentRepositoryBuilder`, implements it; nothing in production reads it) and `MessageConverter` (reachable only through `Configuration::registerMessageConverter()`, which nothing calls; `FakeMessageConverter` in `GatewayProxyBuilderTest` is its one implementation) | **Recorded, not deleted.** An unused registration API still works; removing it is the maintainer's call |

**The 24 with exactly one:**

| Interface | Its one implementation | Outcome |
|---|---|---|
| `CancellableAmqpStreamConsumer` | `AmqpStreamInboundChannelAdapter` | **Collapsed** (`0cf11224b`) — it existed so the acknowledge callback could call back into its adapter, rule 5's own example |
| `ConversionServiceDecorator` | `DataProtectionConversionServiceDecorator` | **Collapsed** (`6d3da194f`). Core calls `decorate()` by method name through a `Definition` and never named the interface |
| `ProxyBuilder` | `GatewayProxyBuilder` | **Collapsed** (`15932c35e`) — nothing typed against it |
| `TerminationListener` | `PcntlTerminationListener` | **Collapsed** (`85ca52eda`), with its container alias; half the framework already took `PcntlTerminationListener` directly |
| `FieldFactoryInterface` | `FieldFactory` | **Collapsed** (`ceb852ee8`), with the `?FieldFactoryInterface = null` parameter no caller passed |
| `TaskScheduler` | `SyncTaskScheduler` | **Collapsed** (`f5db099f8`) |
| `TriggerContext` | `SimpleTriggerContext` | **Collapsed** (`7ca7fb942`) |
| `OutboxForwardingChannel` | `OutboxForwardingMessageChannel` (Dbal `Api`) | **Kept: a package-boundary seam.** `MessagingSystemConfiguration` in core asks `instanceof OutboxForwardingChannel` so that `packages/Ecotone` never names a class from the optional Dbal package. Rule 5 names two seams and this is a third — dependency inversion across a package — which audit a's "the rule may need a third category" anticipated. For the maintainer: name it in rule 5, or move the guard |
| `TerminationListener` | `PcntlTerminationListener` | **Kept: a layer-boundary seam.** Collapsing it changed `Api/Projecting/ProjectingManager`'s constructor to name `PcntlTerminationListener`, so the public surface declared it needs the pcntl implementation rather than something that can answer `shouldTerminate()`. Collapsed in `85ca52eda`, reverted in `2866f6794` once that reached review. The second instance of the boundary category above — core must not name a Dbal class, `Api` must not name a platform-specific `src` class. For the maintainer: the same decision as `OutboxForwardingChannel`, name the category in rule 5 or move the dependency |
| `TerminationListener` licence header | — | **Fixed: the interface is `licence Apache-2.0`.** It carried `licence Enterprise` while its only implementation, `PcntlTerminationListener`, carries `licence Apache-2.0`, so `Api` pointed at an Apache file through an Enterprise-labelled type. `bin/check-licence.php` walks `src` only and validates rather than counts, so nothing ever failed on it. The maintainer chose Apache-2.0; only the header moved |
| `TaskExecutor` | `PollToGatewayTaskExecutor` | **Kept for now.** Its second implementation is `StubTaskExecutor`, which drives `SyncTaskSchedulerTest`, a unit test of an internal class. Collapsing the interface means rewriting that test at the userland level (rule 10), outside this unit |
| `ContainerImplementation` | `SymfonyContainerImplementation` | **Kept.** One implementation by design since PR #675: every integration boots one shared, dumped side-car Symfony container. It sits in the container compiler, the widest-reaching code in the repository |
| `CommandBus`, `EventBus`, `InboundGatewayEntrypoint` | `StorageCommandBus`, `StorageEventBus`, `NullInboundGatewayEntrypoint` | **The interfaces are gateways and stay; the three classes were dead stubs nothing constructed, deleted** (`1cd6093b5`) |
| `InboundChannelAdapterEntrypoint` | `NullEntrypointGateway` | **Kept: two implementations.** A runtime gateway when the adapter has a request channel, the null object when it has none (`KafkaInboundChannelAdapterBuilder`, `EnqueueInboundChannelAdapterBuilder`) |
| `MessageStore`, `MessageGroupStore`, `MessageGroup` | `SimpleMessageStore`, `InMemoryMessageGroup` | **Recorded.** `Messaging\Store` is used only by its own two unit tests. Delete the package or give it a caller — the maintainer's call |
| `Transaction`, `TransactionFactory`, `WithRequiredReferenceNameList` | `NullTransaction`, `NullTransactionFactory`, `Transactional` | **Removed: `#[WithoutDatabaseTransaction]` and `DbalConfiguration` superseded it.** The maintainer chose removal over moving it into `Api`. All seven files of `Messaging\Transaction` are gone; nothing under any `src/` or `Api/` referenced them from outside the directory, and nothing registered `TransactionInterceptor`, so `#[Transactional]` alone was already a no-op. `GatewayProxyBuilderTest` and `InboundChannelAdapterBuilderTest` used it as a sample attribute for the around-interceptor mechanism; they now use a test-owned `RecordOutcomeIn` with the same endpoint, method and class precedence. `WithRequiredReferenceNameList` (`Messaging\Attribute`) was left with no implementation and no reader anywhere in the tree — `Transactional` was its last — and is now removed too: a grep of `packages/`, `Monorepo/`, `quickstart-examples/`, `bin/` and the docs found it in its own file only. It was internal, so it has no namespace-map row and no upgrade note. `upgrade-2.0.md` §7b records what an application sees |
| `Configuration`, `ProjectionRegistry`, `MultiTenantConnectionFactory`, `HeaderMapper` | `MessagingSystemConfiguration`, `InMemoryProjectionRegistry`, `HeaderBasedMultiTenantConnectionFactory`, `DefaultHeaderMapper` | **Recorded.** Module- and application-facing types; reshaping them is not a cleanup |

`ProjectionNameHeader` (`OpenCoreProjectionNameHeader`, `EnterpriseProjectionNameHeader`) is a two-implementation
open-core/Enterprise seam, as rule 5 permits. All seven collapses are pure refactors; each rides the suites that
cover its caller, and the full root gate below.

### Rule 2 — the six, each with a way out

All six still had the nullable parameter on base `1d4d0e196`.

| Site | The null meant | Outcome |
|---|---|---|
| `MessagingContainerBuilder` — `?InterfaceToCallRegistry`, `?ServiceConfiguration` | Nothing: its one construction site, `MessagingSystemConfiguration::process()`, always passes both | **Made required** (`8a4d3e1f4`). One file, no signature change outside `packages/Ecotone` |
| `DbalParameterConfig` — `?AttributeExpressionContextExecutor` | "This `#[DbalParameter]` has no expression" | **Null-object factory** (`e194fe02c`): `AttributeExpressionContextExecutor::withoutExpression()` and `hasExpression()`, the `7f7d24bc0` precedent. An empty-string expression still fails with "Attribute … has no expression to execute" |
| `ManagerRegistryEmulator` — `?EntityManagerInterface` | Two behaviours: use the manager handed in, or build one from the mapping paths | **Split the class** (`f3679be13`), and the split fixed a defect the two modes had drifted into: `ObjectManagerInterceptor` resets a closed manager before the next command, and `resetManager()` rebuilt a handed-in one from the paths — an empty list for `createEntityManager()` — with a fresh `EventManager`, so the application's Doctrine listeners silently stopped running. `EntityManagerRegistryEmulator` now requires the manager and resets it from its own connection, configuration and event manager. `ORMTest::test_an_entity_manager_handed_to_the_registry_keeps_its_event_listeners_after_it_is_reset` was red on the base commit (the listener recorded `[]`) |
| `DirectChannel` — `?MessageHandler` | **Not a rule 2 item.** The field is the subscription slot of a `SubscribableChannel`: `subscribe()` fills it, `unsubscribe()` empties it at runtime, and a send with no subscriber has its own `MessageDispatchingException`. The container subscribes through `addMethodCall('subscribe')` (`EventDrivenConsumerBuilder`); the constructor argument is a shortcut one caller, `InterceptedPollingConsumerBuilder`, uses | Left |
| `BusRoutingMapBuilder` — `?Configuration` | **Not a rule 2 item.** A compile-time builder that compiles to a `BusRoutingMap` definition; `Configuration` is module-time configuration, never a container service, never on the message path. It is needed only by the routing-event handlers, and only the three builders in `MessageHandlerRoutingModule` have handlers — and pass it. The other four construction sites cannot: `EcotoneProjectionExecutorBuilder` builds one inside `compile()`, with no `Configuration` in reach | Left. The residual smell: `ServiceHandlerModule` and `AggregateModule` reach it through `$event->getBusRoutingMapBuilder()->getMessagingConfiguration()` and dereference a `?Configuration` unguarded. Carrying `Configuration` on `RoutingEvent` would make that honest |

Also rule 2, found while verifying rule 7's handover: **`AggregateModule` registered only the licence-selected
`AggregateMethodInvoker`, behind `if (isRunningForEnterpriseLicence())`.** Both are now registered unconditionally
and `LicenceDecider::prepareDefinition()` chooses at runtime, as `DynamicConsistencyBoundary` does (`74f7bb6cc`). It
was neither rule 1c nor a configuration condition: a compile-time licence check doing what the runtime decider
already did.

**Not triaged — the next queue.** Constructors taking a nullable service with no default, which the audit's detector
(it matched `= null`) could not see: `InternalEnrichingService` (`?EnrichGateway`), `RequestReplyProducer` and
`SplitterHandler` (`?MessageChannel $outputChannel`), `MessageFilter` (`?MessageChannel $discardChannel`),
`GatewayInternalProcessor` (`?PollableChannel $replyChannel`), `InMemoryReferenceSearchService` and
`ValidateRequiredReferencesPass` (`?ContainerInterface`), `InterfaceToCallRegistry` (`?AnnotationResolver`),
`ClosureParameterResolver` (`?ParameterConverter`), `NullTransactionFactory` (`?Transaction`, removed
with `Messaging\Transaction` above) and `ConnectionExceptionRetryInterceptor` (`?RetryTemplateBuilder`, likely a
value). The `?MessageChannel` ones look like rule 2's "no value here" shape — "no output channel" — and may want a
null channel rather than a branch.

**Licence note, no action.** `AttributeExpressionContextExecutor` and `AttributeExpressionExecutor` are
`licence Enterprise`, yet carry open-core string expressions: `EndpointHeadersInterceptor` (`licence Apache-2.0`)
evaluates them, and so does every `#[DbalParameter(expression: '…')]`. The rule 2 null object above extends that to
`#[DbalParameter]` without an expression. Whether those two files are Enterprise is for the maintainer; only closure
expressions are gated (`VerifyEnterpriseLicenceForClosureExpressions`).

## Rule 11 — triaged

### The headline: no named class in a test file can drift

**Method.** The population is rule 11's own: every `packages/*/tests/*Test.php` added since 1.x
(`git diff --diff-filter=A --name-only c440b6d4c...HEAD`) — **150 files** on `a57882174`, up from 141 when audit c
counted them. Each file was tokenized, not grepped, and every top-level named class that does not extend a
`TestCase` was listed with the attributes on it **and on its members**. Every one of them was then looked up in
every other PHP file under `packages/` **by fully-qualified name**: a `use` import, the FQCN string, or a short-name
use from the same namespace. Tools and output: `docs/superpowers/research/rule-11-named-fixtures/`, with the
per-class triage in `triage-a57882174.txt`.

**Result: 0 of 729 named classes are referenced from any other file** — not the 228 free ones, not the forced ones,
not the message types. The three hits the lookup reported were false, and are the reason short names cannot be
trusted here: the string `'Wallet'` in `#[AggregateType('Wallet')]`, and two message classes in
`StatefulEventSourcedWorkflowWithMultipleAggregatesTest` that share a short name with classes in
`LoadBatchSizeBoundaryDbalTest` but live in another namespace.

So every named fixture in these files is already owned by exactly one test file, and rule 11's rationale — "an
anonymous fixture cannot drift out of the test that owns it" — is already met for all of them. Converting them
would be churn against a risk that does not exist. **They are recorded and deliberately not converted.**

| | Files | Named classes |
|---|---|---|
| Added since 1.x, on `a57882174` | 150 | |
| Declare a named non-test class | 105 (106 after this unit — `DirectChannelPayloadForNonPollableChannel` moved in from a `Fixture/` directory) | 729 |
| Of those, declare no named class with a handler-type attribute — message types and plain helpers only | 12 | |
| Carry a named class with a handler, aggregate, saga, projection, gateway or interceptor attribute, on the class **or on a method** | 93 | 385 |
| Of those 385, forced by a hard limit — typed somewhere in the file (exception 1), named in an attribute argument or class constant (exception 2), an interface, or the parent of another named class | | 157 |
| Free — no hard limit found | 78 | **228** |
| Free **and** referenced from another file | | **0** |

**What a previous brief got wrong, so nobody re-plans from it.** It cut the audit's 100 to "83 message-type-only
files, 17 candidates" by reading an unindented attribute as one on a top-level class. Attributes on a named
handler's *methods* are indented, so every named service handler read as a message type —
`DeduplicationExpressionFailureTest::BrokenDeduplicationExpressionHandler`, all of `Messaging/Unit/Config/*`, most
`DecisionModel/*` handlers. The real split is 12 and 93. Five of its 17 — `MetadataEnricherTest`,
`AggregateIdResolverTest`, `DeletedEventClassInStreamTest`, `BlueGreenDeploymentProjectionTest`,
`RebuildProjectionTest` — are 1.x-era files, outside the population.

### What was converted: fixtures in `Fixture/` directories added since 1.x

A named class in a test file cannot drift; one in a shared `Fixture/` directory is where the next test reaches when
it needs one, which is how a single-owner fixture becomes a shared one. 36 PHP files were added to `Fixture/`
directories since 1.x, not counting the Laravel and Symfony `DcbSmoke` applications, which the frameworks must
discover from disk. Every behavioural class among them that no hard limit forces was converted into its only test,
one commit per directory, each riding that test unchanged:

| Commit | Directory | Became anonymous | Stayed named, and why |
|---|---|---|---|
| `d40862060` | Dbal `Transaction/ClassRouted` (removed) | `ClassRoutedOrderService` | `PrepareOrdersByClassCommand` — a handler parameter type (exception 1); now `PrepareOrdersByClassCommandForTransaction` below `TransactionTest` |
| `9502427ea` | Ecotone `Messaging/Fixture/NonPollableChannel` (removed) | `DirectChannelService` | `DirectChannelPayload` — the test asserts `get_debug_type()` of the payload against its class name, and `get_debug_type()` reports any anonymous object as `class@anonymous`, so an anonymous payload changes the assertion. Now `DirectChannelPayloadForNonPollableChannel` below the test |
| `d5836bfee` | JmsConverter `InterfacePayload` | `Basket` | `BasketContentChanged` is an interface; `ProductAddedToBasket`, `ProductRemovedFromBasket` are parameter types (exception 1); `EventSourcedBasket` is named in `#[FromAggregateStream(EventSourcedBasket::class)]` (exception 2) |
| `7dde0dbb0` | JmsConverter `ServiceParameter` (removed) | `Basket` | — ; the expected message interpolates `$basket::class` |
| `c2f21e754` | OpenTelemetry `DecisionModelFlow` | `Course`, `Enrolments`, `EnrolmentsWithoutBoundary` | `CourseCapacity` — a decision model typed in the handlers (exception 1), and the commands and events. The span-name assertion interpolates `$enrolments::class`. `Course` is event-sourced and carries `#[AggregateType('Course')]`, which is what lets it be anonymous — see the third exception below |

**Deliberately not converted:**

- `PdoEventSourcing/tests/Fixture/LegacyStream` — `LegacyOrder` declares `#[Stream(legacyStreamName: self::LEGACY_STREAM_NAME)]`
  whose value is its own FQCN: the test proves a 1.x stream, named after the aggregate's class, is still read.
  An anonymous class could carry the string, but it would then name a class that does not exist, and the test
  would stop demonstrating what it is for. `LegacyOrderConverter` stays beside it: the directory stays for
  `LegacyOrder`, so moving the converter alone removes no invitation
- `PdoEventSourcing/tests/Fixture/SecondaryConnectionStream` — the only fixture two test files genuinely share
  (`SecondaryConnectionStreamTest`, `EventStreamMigrationMultiConnectionTest`), and an event-sourced aggregate with
  an explicit `#[Stream]` on a second connection. Duplicating `SecondaryOrder` and `SecondaryOrderConverter` into
  both files would make two classes write and read what the migration test expects to be one identity
- `Lite/Fixtures/UnregisteredHandler/ShippingSlotReservationHandler` — the test is about a handler that exists in
  an autoloaded namespace but was not passed to the bootstrap, and asserts its FQCN and that namespace in the
  message. An anonymous class has no namespace to be found in
- `Messaging/Fixture/Handler/ClosureInAttribute/FailingClosureExpressionService` — the fourth exception below
- Laravel `Fixture/AsynchronousMessageHandler/AsyncChannelConfiguration` — loaded by the Laravel test
  application's namespace scan, not by a bootstrap call that could take `$fixture::class`

### Two hard limits the rule does not name — proposed, rule text not edited

Rule 11 names two exceptions. Two more are as hard as those, and the rule text should name them; revising it is
out of this unit's scope.

**Third: an event-sourced aggregate on DBAL that relies on its default aggregate type.** An anonymous class's name
carries a NUL byte (`class@anonymous\0/path/File.php:LINE$0`), and an aggregate's type defaults to its class name.
On DBAL PostgreSQL the creating command fails with `SQLSTATE[22P05]: Untranslatable character … unsupported Unicode
escape sequence`: the type is written into the event `metadata` column, `JSONB` in `PostgresEventStreamSchema`, as
`\u0000`, which `jsonb` refuses. On SQLite the aggregate is not found again: `AggregateNotFoundException: Aggregate
class@anonymous\u{0000}/… for calling … was not found`. MySQL and MariaDB pass. **It is the aggregate type, not
the stream name**: with `#[AggregateType]` the same anonymous aggregate passes on all four engines, with the default
stream and with an explicit `#[Stream]`; without it, it fails with either. Evidence: `nul-byte-experiment.php.txt` in
`docs/superpowers/research/rule-11-named-fixtures/`, four variants run on PostgreSQL, MySQL, MariaDB and SQLite, not
committed as a test.

So the limit is narrower than "event-sourced aggregates cannot be anonymous". One that carries `#[AggregateType]` can
be — rule 11 already says that attribute forces nothing, and `Course` above is converted on that basis. One that does
not can be anonymous only by gaining the attribute, which changes the fixture's configuration rather than moving it;
where the test is about the default type, it cannot be anonymous at all. The in-tree case, run: `DynamicConsistencyBoundaryDisabledDbalTest::TaggedEntityForDisabledBoundaryDbalTest` is
event-sourced on DBAL, has no `#[AggregateType]`, and is free by every other check. Made anonymous in a throwaway
edit, `test_aggregate_emitting_tagged_events_is_saved_and_reloaded_without_any_tag_tables` fails on PostgreSQL with
the `22P05` above and on SQLite with the `AggregateNotFoundException` above, and passes on MySQL. It stays named,
recorded with the rest of the 228.

**Fourth: syntax newer than the PHP floor.** `FailingClosureExpressionService` puts a closure in an attribute
argument, PHP 8.5 syntax, and its test is `#[RequiresPhp('>= 8.5.0')]`. PHPUnit parses a test file whether or not it
then skips the test, so an inline fixture would be a parse error on the 8.2 floor. The class has to live in a file
of its own that only the 8.5 test loads.

Two softer findings beside them: a `#[MessageGateway]` gateway is an interface, and PHP has no anonymous interface;
and `get_debug_type()` makes a class name observable data, unlike an exception message, where interpolating
`::class` is enough.

## Shipped since the audits

Recorded here because a finding that stays under *Open* after it ships sends the next reader chasing closed
work, and invites a second fix.

| Rule | Finding | Outcome |
|---|---|---|
| 7 | `EcotoneProjectorExecutor` (`licence Apache-2.0`) branched on `hasEnterpriseLicence()` at `:42` and `:129` to decide whether projection handlers receive the projection name | **Shipped.** The decision is a `ProjectionNameHeader` collaborator: `OpenCoreProjectionNameHeader` (`licence Apache-2.0`) leaves the headers as they are, `EnterpriseProjectionNameHeader` (`licence Enterprise`) adds `projection.name`. `EcotoneProjectionExecutorBuilder` picks one with `LicenceDecider::prepareDefinition()`, the call shape of `DynamicConsistencyBoundary.php:108`, and `ProjectingModule` registers both unconditionally (rule 2). The executor itself stays one class: splitting it whole would have copied 130 lines to vary two. Open core is unchanged on every path that sends a message — event handler, flush, initialization, delete, and reset during a rebuild — proved by `ProjectionNameHeaderTest` without and with a licence; it passes on the base commit, and forcing the old branch either way fails exactly the five cases of the other mode. `bin/check-licence.php` exits 0 before and after; across `src` and `Api` the tally moves from Apache-2.0 1012 / Enterprise 227 to 1014 / 228, the three new files and nothing else. `ProjectionNameHeader` is a two-implementation seam that rule 5 permits — **for the rule 5 unit to note, not to triage away.** Adjacent, **not** changed: the canonical example rule 7 cites, `EventSourcingHandlerExecutorBuilder.php:72`, passes `Reference` objects to `prepareDefinition(string, string, string)`, which works only because that file lacks `declare(strict_types=1)`; and `AggregrateModule.php:344` registers only the implementation the licence selects, behind an `if`, where `DynamicConsistencyBoundary` registers both |
| 7 | The rule 7 unit's two adjacent findings: the canonical example `EventSourcingHandlerExecutorBuilder.php:72` passed `Reference` objects to `prepareDefinition(string, string, string)`, and `AggregrateModule.php:344` registered only the licence-selected invoker behind an `if` | **Shipped.** The example now passes class names (`ffc319381`). The claim held: the old call copied into a `declare(strict_types=1)` file throws `TypeError: … Argument #2 ($openCoreServiceReference) must be of type string, Ecotone\Messaging\Config\Container\Reference given`; `Reference::to(X)` stringifies to `X`, so the compiled definition is unchanged. Both invokers are now registered unconditionally (`74f7bb6cc`; see *Rule 2 — the six*). `AggregrateModule` itself is renamed `AggregateModule` (`66f2ef586`): 3 files, 7 lines, internal only |
| 12a | The 2.0 `Api/` sweep missed two public types, `EventStore` and `ConversionService` | **Shipped** in `eb870e30f`: `Ecotone\Api\EventSourcing\EventStore` and `Ecotone\Api\Conversion\ConversionService`, with the namespace-map rows and `upgrade-2.0.md` §13b. `EventStore::RAW_REFERENCE` and `EventStoreReference::EVENT_STORE_INSTANCE` are unchanged; `ConversionService::REFERENCE_NAME` is `self::class`, so its container id moved with it, which §13b documents. The wider gap was closed for nine more types afterwards — see the next row |
| 12a | Eleven more application-facing types were still in `src` | **Shipped** for nine: `WithEvents` and `WithAggregateVersioning` → `Ecotone\Api\Modelling`, `Event` → `Ecotone\Api\EventSourcing`, `MethodInvocation` and `Precedence` → `Ecotone\Api\Interceptor`, `TimeSpan` → `Ecotone\Api\Scheduling`, `MessageHeaders` → `Ecotone\Api\Messaging`, `ModulePackageList` and `DynamicMessageChannelBuilder` → `Ecotone\Api\ExtensionObject`, with namespace-map rows and `upgrade-2.0.md` §13c. `Event`, `Precedence`, `TimeSpan` and `MessageHeaders` were already named by `Api` signatures, so the move closed inward leaks too. None of the nine declares a container id or is registered under its class name, so no service id moved. All nine are now outside phpstan (`src` only) and `bin/check-licence.php` (`packages/*/src` only); `DynamicMessageChannelBuilder` is `licence Enterprise` and is the 38th Enterprise file under `Api/`, so the licence gate's blind spot predates it. **Deliberately not moved:** `EcotoneLite` and `ErrorMessage` — see the rule-12a rows under *Open, no owner* |
| 12a | Six recorded inward and outward leaks: `MetadataMatcher`, `DatePoint`, `Future`, `ErrorContext`, `LoggingLevel`, `FetchMode` | **Shipped** with four types their signatures name, ten in all: `MetadataMatcher`, `Operator` and `FieldType` → `Ecotone\Api\EventSourcing` (`bdddf84b4`); `DatePoint`, `SleepInterface` and `Duration` → `Ecotone\Api\Scheduling` (`e72f9f202`); `Future` (`233b92c32`) and `ErrorContext` (`61d1db832`) → `Ecotone\Api\Messaging`; `LoggingLevel` → `Ecotone\Api\Logging` (`1d9f5ebdf`); `FetchMode` → `Ecotone\Api\Dbal` (`6fd100348`). Namespace-map rows ship in each commit; `upgrade-2.0.md` §13d. **`SleepInterface` moved rather than being folded into `EcotoneClockInterface`**: `Clock` also checks a plain PSR clock for it, and `StaticPsrClock` implements it without being an `EcotoneClockInterface`, so it is a seam of its own. **`ErrorContext` moved without `ErrorMessage`** — see the first rule-12a row under *Open, no owner*. None of the ten is a container service or registered under its class name, and every constant keeps its value. `Api/` grows from 181 to 191 files, 46 of them importing `Assert`/`Definition`/`DefinedObject`; licence headers across `packages/*/src` and `packages/*/Api` stay Apache-2.0 1001 / Enterprise 226 / MIT 25 |
| 6 | **`Ecotone\Dbal\DbaBusinessMethod` — "Dba" for "Dbal"**, held until `FetchMode`, the one type in it that applications import, left for `Api` | **Shipped** in `e5feb12fd`, right after the `FetchMode` move: the directory, the namespace and `DbaBusinessMethodModule` are `DbalBusinessMethod`/`DbalBusinessMethodModule`. What was left in the namespace is internal — the module, `DbalBusinessMethodHandler`, `DbalParameterConfig` — so only `ModuleClassList` and the three files changed; the namespace map gains a row for each |
| — | **Documented list parameters did not resolve** — filed as a "gateway named-argument bug", which it was not. `GenericType::accepts()` read every generic as `[key, value]`, so a single-generic collection such as `string[]` compared each key against the element type and then called `accepts()` on a missing value type. Any non-empty array reaching a parameter documented `@param string[]` — payload or `#[Header]`, through a bus, a `#[BusinessMethod]`, an asynchronous channel or a gateway — failed with `Call to a member function accepts() on null`, and a header documented `@param Foo[]` already holding `Foo`s was refused with `Can't convert … from array<Foo> to array<Foo>`. Present since `deecf49f7` (#523). `EventStore::loadAggregateEvents()` documents `@param string[] $eventNames`, which is how the audit met it; its `fromVersion:` sibling passed only because `eventNames` stayed `[]` and never entered the loop. The named-argument framing was wrong: a fully positional gateway call failed identically. Reproducer: `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:59` | **Fixed.** Proved on each path in `packages/Ecotone/tests/Messaging/Unit/Handler/DocumentedListParameterTest.php`, and the reproducer now runs through `getGateway(EventStore::class)`. Adjacent and **not** fixed: a class named by its short name in an anonymous class's docblock resolves against the global namespace, because an anonymous class reports none |
| 10 | Two raw-SQL assertions in tests | **Shipped.** `DeduplicationCleanupMultiTenantTest` now asserts whether a resent message is handled again per tenant (`e87212d14`); the snapshot-envelope assertion was deleted (`e8d8dabce`) after a mutation proved its sibling covers the same guarantee. The 26 internal-container references closed in `4e7b8d830` and `2e27015a6`. **Zero raw-SQL assertions remain** |
| 3b | `TracingChannelInterceptor` pushes a span in `preSend()` and pops it in `afterSendCompletion()`, with no `try/finally` between them | **Closed, no production change — not reachable.** The only caller of either method is `SendingInterceptorAdapter::send()`, and it calls `afterSendCompletion()` for every interceptor whose `preSend()` returned, on all five outcomes: success, a failing channel send, a failing later `preSend()`, a dropped message, and a failing cleanup or `postSend()` of another interceptor. `TracingScopeCleanupTest`'s eight tests cover the failure outcomes, each asserting no OpenTelemetry scope-detach notice. Tracing's own `preSend()` cannot throw after the push. And the finding was backwards: an unpopped push sits at the bottom of the stack, so later pairs stay balanced; desynchronising them needs more pops than pushes. **The proposed fix is wrong**: keying the open span on the message — by its `TRACING_CARRIER_HEADER` or a `WeakMap` — was built and turned `test_no_scope_detach_notices_when_interceptor_replaces_message_dropping_framework_headers` red with two scope-detach notices and a leaked scope, because `afterSendCompletion()` receives the *final* message, not the one tracing returned, and a later interceptor may have rebuilt it without the header. The experiment was reverted. Still in the file and untouched: the `// @TODO test` on `afterReceiveCompletion()` |
| 3c | `DbalTagTables` memoizes that the tag tables exist and never invalidates it | **Shipped**, with the premise corrected. `DbalEventStore::delete()` never drops the tag tables, and `test_deleting_the_stream_in_the_same_process_keeps_the_tag_index_working_for_the_next_append` records that. The real drop is `ecotone:migration:database:delete --feature=event_tags`, after which a tagged append or a tag backfill in the same process failed with a raw `TableNotFoundException`. `DbalEventStore`'s stream-table memo had the same hole under `--feature=event_stream`, for an append, a tagged append and an event-sourced aggregate creation. **No in-process invalidation can cover a drop made by another process or by an external migration tool, so a memo plus invalidation cannot hold on every path that drops the table; a memo plus conversion does.** The memos stay, and the first statement against each table converts DBAL's typed `TableNotFoundException` — PostgreSQL 42P01, MySQL/MariaDB 1146, SQLite "no such table", mapped by DBAL, not matched on message — into the missing-table instruction the load paths already raised: `d2dc6d8fb` for the tag tables, `bc129766b` for the stream table. No query added per append. A state-stored aggregate save reaches the tag tables through `loadByCriteria()` before it bumps them, so it already raised the instruction; a test records that path. Not changed, and a choice: even under automatic initialization on PostgreSQL or SQLite, a table dropped this way is named, not recreated at runtime, until the setup command runs |
| 16 | The audit reported 0 unguarded DDL violations | **The audit was wrong, and the gap is now closed.** `DbalEventStore::delete()` dropped a stream table with no engine or transaction check; on MySQL/MariaDB that committed the message's writes and then failed at commit with `DriverException: There is no active transaction`, reachable through a `#[ProjectionReset]` handler. Guarded in `ccfd69384` on the intersection of an open transaction and an engine where DDL implicitly commits; rule 16 itself restated in `0843cf77f` and §8 aligned in `a6d3b29d5` |
| 12a | `EcotoneLite`, `FlowTestSupport` and `ConfiguredMessagingSystem` were still internal: the entry point of every flow test and the two types it returns | **Shipped** together, with the types their signatures name, seven in all: `EcotoneLite` → `Ecotone\Api\Lite` (`df4e0f085`); `FlowTestSupport` and `MessagingTestSupport`, which `getMessagingTestSupport()` returns, → `Ecotone\Api\Lite\Test` (`67161065f`); `ConfiguredMessagingSystem` → `Ecotone\Api\Messaging` (`f24c1c211`); `MessageChannel`, `PollableChannel` and `MessagePoller`, which `PollableChannel` extends, → `Ecotone\Api\Messaging` (`09ad57779`). Namespace-map rows ship in each commit; `upgrade-2.0.md` §13e. **Two container ids change**, the first `Api` move where any does: `ConfiguredMessagingSystem` and the `MessagingTestSupport` gateway are registered and bridged by `::class`, and nothing in the tree spells either old id as a string, except the PHP that Tempest's `ConsoleCommandProxyGenerator` writes out, which changed with it; §13e has the old and new ids and the cache to clear. Measured on base `41cdcb889` by exact FQCN: `EcotoneLite` 321 files, `FlowTestSupport` 166, `MessagingTestSupport` 4, `ConfiguredMessagingSystem` 71 (69 PHP files and the two `ProxyGeneratorTest` snapshots), `MessageChannel` 58, `PollableChannel` 40, `MessagePoller` 7. Each is 0 after, with the same pattern matching all of them on base. phpstan now analyses every package's `Api/` at level 1 (`dae54dae3`), with 0 errors before and after. `Api/` grows from 191 to 198 files, 48 of them importing `Assert`/`Definition`/`DefinedObject`; licence headers stay Apache-2.0 1001 / Enterprise 226 / MIT 25. **The four commits revert newest-first only**, a property of moving co-imported types rather than of this order: see `dev-workflow.md` § *A move of several co-imported types reverts newest-first*. What it left was two rule-12a rows: `Message` through five `FlowTestSupport` methods, and the console writer types. Both have since shipped (the next two rows) |
| 12a | The console writer types were application-facing and still in `src`: `packages/Laravel/tests/Fixture/ConsoleWriter/ConsoleWriterCommand.php` is a `#[ConsoleCommand]` that takes `ConsoleWriter $writer` and calls `$writer->progressBar(2)`, the Symfony and Tempest fixtures of the same name do the same, and `FlowTestSupport::getInMemoryConsoleWriter()` returned the internal `InMemoryConsoleWriter`. The leak predates the `Api` work | **Shipped**, four types in two commits: `ConsoleWriter` and the `ConsoleProgressBar` its `progressBar()` returns → `Ecotone\Api\Console` (`97325e62d`); `InMemoryConsoleWriter` and the `InMemoryConsoleProgressBar`s its `getProgressBars()` returns → `Ecotone\Api\Console` (`f5eefcf77`). The walk ended there: none of the four has an import, and every non-scalar type their signatures name is one of the four. Namespace-map rows ship in each commit; `upgrade-2.0.md` §13f. **Two more container ids change**: `RegisterSingletonMessagingServices` registers `ConsoleWriter` by `::class` with `DelegatingConsoleWriter` over `PlainConsoleWriter`, and `EcotoneTestSupportModule` registers `InMemoryConsoleWriter` by `::class` and puts it behind the `ConsoleWriter` id. No framework integration aliases either: Symfony's `EcotoneExtension` bridges every class-named id through `getServiceIds()` and passes `Reference(ConsoleWriter::class)` to each `MessagingEntrypointCommand`, Laravel's `EcotoneProvider` and Tempest's generated console commands call `getServiceFromContainer(ConsoleWriter::class)`. The one place the old FQCN was text is the PHP `ConsoleCommandProxyGenerator` writes out, which changed with it — and Tempest regenerates those files only when its config hash changes, so §13f tells Tempest users to run `ecotone:cache:clear`. Measured on base `71a512349` by exact FQCN, or by short name from `Ecotone\Messaging\Console`, the defining file excluded: **`ConsoleWriter` 15 PHP files plus the generator's heredoc, `ConsoleProgressBar` 11, `InMemoryConsoleWriter` 3, `InMemoryConsoleProgressBar` 1** — the counts this document recorded on `41cdcb889`, and not the brief's 24, 3, 4 and 0 (see the claims table). Each is 0 after, with the same pattern finding all four on base. `DelegatingConsoleWriter`, `PlainConsoleWriter`, `SymfonyConsoleWriter`, `TempestConsoleWriter` and their progress bars stay in `src`, implementing the `Api` interfaces — the permitted direction. A pure move: `ConsoleWriterInjectionTest`, `PlainConsoleWriterTest`, `SymfonyConsoleWriterTest`, the three `ConsoleWriterCommandTest`s, `ConsoleCommandProxyTest` and `ConsoleProxyArgumentMappingTest` cover it. The two commits revert newest-first: `EcotoneTestSupportModule` imports `ConsoleWriter` and `InMemoryConsoleWriter` on adjacent lines |
| 12a | `Message` was internal although an application meets it below the buses: five `FlowTestSupport` methods, `MessagingTestSupport`'s `popRecorded…Messages…()`, `MessageChannel::send()`, `PollableChannel::receive()`, `MessagePoller::receiveWithTimeout()` and Dbal's `DeadLetterGateway::store()`/`show()` name it, and a handler takes the whole message by typing it (`Monorepo/ExampleApp/Common/Infrastructure/ErrorChannelService.php`) | **Shipped** in `4b79af4b1`: `Ecotone\Api\Messaging\Message`, beside `MessageHeaders` and the channel interfaces. It dragged nothing: it imported only `MessageHeaders`, already in `Api`, its two methods are `getHeaders(): MessageHeaders` and `getPayload(): mixed`, and no interface extends it. Its implementations, `GenericMessage` and `ErrorMessage`, stay in `src`; `DbalMessage` is not one (see the claims table). **No container id changes**: `Message::class` appears in `src` only as a type to compare against, in `InterfaceParameter`, `InterfaceToCall`, `Type`, `AroundInterceptorBuilder`, `Gateway` and `MessageHandlerRoutingModule`, and no string spells the old FQCN. A compiled container does record it by name (`new ObjectType('Ecotone\\Api\\Messaging\\Message')` in a cached container), so `upgrade-2.0.md` §13g still says to clear the cache. A parameter left on the old FQCN is refused at bootstrap with `Unknown type or class 'Ecotone\Messaging\Message'`, checked with a throwaway handler, not committed. Measured on base `71a512349` by exact FQCN, or by short name from `Ecotone\Messaging`, the defining file excluded: **298 PHP files** — 290 by import, 5 fully qualified, 3 from its own namespace (`MessageHandler`, `MessagingException`, `NullableMessageChannel`, which now import it). 0 after, with the same pattern finding 295 files by text on base. Beyond the five `FlowTestSupport` methods, the walk found one signature no record named: `getFailedMessage()`, which `DecisionModelConcurrencyException` inherits from `MessagingException`. A pure move, riding every suite |

## Closed — no action

Rule 4 (0 confirmed; 11 mechanically flagged, all false positives or exempt on triage), rule 12's structural checks
(0 violations, full walk), rule 14 in production code (`#[ConsoleParameterOption]` has no name override, so the rule
is structurally unviolable — the only violations were in `upgrade-2.0.md`, fixed), and rule 6's 271 legacy inline
comments plus 789 legacy prose docblocks (grandfathered by the rule's own text; only the fresh ones were actioned).
