# Guideline conformance audit — 2.0 consolidate branch @ `8828abc1e`

This audit checks the tree against `docs/coding-conventions.md` (rules 1 to 21 and the lettered sub-rules), `AGENTS.md`
and `docs/dev-workflow.md`. It is a list, not fixes. No PHP was changed, and no test, phpstan, php-cs-fixer or docker
command was run.

## 1. Method

**Reading order.** I read `docs/coding-conventions.md` in full, then `AGENTS.md`, then the process sections of
`docs/dev-workflow.md`, then `upgrade-2.0.md` §13/§13a and `upgrade/namespace-map-2.0.csv`. I needed the last two to
tell a deliberate placement from a leak.

**What counts as "new".** "Added since 1.x" means added between the merge-base with `main` (`c440b6d4c`, Release
1.326.2 line) and `HEAD`, using `git diff --diff-filter=A --name-only c440b6d4c HEAD -- 'packages/*.php'`.
- That gives 345 files: 133 in `src`, 17 in `Api` and 204 in `tests`.
- Rename detection is on, so a file moved into `Api/` during 2.0 counts as old unless it was rewritten.
- For lines added to old files, I used `git blame` / `git log -L` on the cited line.

**Legacy versus live.** Only rules 6, 11 (named fixtures) and 17 carry a "do not sweep" carve-out. For those rules, old
files go under Legacy and only new or 2.0-touched code is a finding. Rules 1a, 2, 3, 4, 10, 12a, 13a and 16 have no
carve-out, so a pre-2.0 instance is still reported when it is on a path the rule covers. Its age is marked **OLD**.

**How the search was run.** I ran the rule 2, 6a and 17 searches myself. Four read-only sub-reads collected candidates
in parallel:
- tests (rules 10 and 11)
- the `Api` boundary (rules 12 and 12a)
- state, reflection and DDL (rules 3, 4, 13a, 16 and 1c)
- interfaces, docblocks, licences and messages (rules 5, 6, 6a, 7, 1 and 14)

**Every `file:line` below was re-opened and read by me before it was written down.** Where a finding depends on runtime
behaviour I could not prove statically, it is marked **PLAUSIBLE** and says what test would settle it. Anything not
marked is **CONFIRMED** by reading.

**What was not covered.**
- Rules 8, 9, 15, 18 and 19 were not audited systematically. Rule 9 (in-memory parity) needs the suites to run.
- The broker packages (`Amqp`, `Kafka`, `Redis`, `Sqs`) were covered only by grep for rules 2, 3, 7, 12a and 17. I did
  not read their runtime paths.
- The `Laravel`, `Symfony` and `Tempest` `src` trees were covered by grep only.
- `Monorepo/` and `quickstart-examples/` were not read.
- In test files older than `c440b6d4c`, rule 10 was sampled (SQL, reflection and container lookups by grep) but not read
  file by file. Rule 11 was not audited in old test files at all, because of its drift carve-out.
- Rule 1 message quality was surveyed only in new `src`/`Api` files.

## 2. Violations requiring change

These are ranked by what it costs to leave them. Correctness and user-facing misdirection come first, then
architectural drift, then style. The **Size** field in each finding is one of: *line* (a one-line change), *class* (one
class) or *cross* (several packages or files).

### Tier A — wrong behaviour, or a user told something false

**A1. Rule 1a (and 10a) — the DBAL document store reads a missing table as an empty result.** OLD (`b63cb19b2`, 2022).
- **Where:**
  - `packages/Dbal/src/DocumentStore/DbalDocumentStore.php:124` (`getAllDocuments` returns `[]`)
  - `:152` (`findDocument` returns `null`)
  - `:172` (`countDocuments` returns `0`)
- **What happens:** when the table is missing, the store reports "no documents" instead of raising the setup
  instructions. This is exactly the shape `048f614d8` removed from the event store. In 2.0 the promise is "a missing
  table raises the setup instructions" (rule 16, `upgrade-2.0.md` §8). The event store path keeps that promise; this
  path, which backs document-store aggregates and snapshots, does not. A document-store aggregate therefore surfaces as
  "not found", which points the user at the wrong cause.
- **Change:** raise `ConfigurationException::create($this->tableManager->getMissingTableInstructions($connection))`, as
  `:204-205` already does on the write path. Add one test per read method that asserts the setup command in the message.
- **Size:** class.

**A2. Rule 14 — the upgrade guide tells users to run console options that do not exist.** NEW.
- **Where:** `upgrade-2.0.md:875-876` (`[--batch-size=500] [--from-no=] [--dry-run] [--skip-undeserializable]`), and the
  prose at `:881`, `:885`, `:886`, `:892` and `:896` (`--legacy-stream=`).
- **What is wrong:** the real options are the parameter names at
  `packages/PdoEventSourcing/src/Console/TagBackfillConsoleCommand.php:30-33` (`--batchSize`, `--fromNo`, `--dryRun`,
  `--skipUndeserializable`) and `TagVerifySchemaConsoleCommand.php:29` (`--legacyStream`). This is the exact bug
  `5fa0024b2` fixed, now sitting in the document users follow.
- **Change:** camelCase every option in §8. Optionally fix the historical spec
  `docs/superpowers/specs/2026-09-20-dcb-design.md:1146,1154,1155,1467,1469` too.
- **Size:** line.

**A3. Rules 2 + 3 — the clock a DBAL queue channel uses depends on which component opened the connection first.**
OLD. **PLAUSIBLE.**
- **Where:**
  - `packages/Enqueue/src/CachedConnectionFactory.php:17`, `:27-33`: a process-wide static `$instances`, keyed by
    `DbalReconnectableConnectionFactory::getConnectionInstanceId()` (class plus `spl_object_id` of the container's
    connection factory).
  - `packages/Dbal/src/DbalReconnectableConnectionFactory.php:26`: `?EcotoneClockInterface $clock = null`, which falls
    back to `NativeClock`. At `:40` it pushes that clock into the cached `DbalContext` (`DbalContext.php:67`,
    `withClock()` mutates and returns `$this`, which is also rule 4).
- **Why it can go wrong:**
  - The channel builders pass the configured clock (`DbalOutboundChannelAdapterBuilder.php:66`,
    `DbalInboundChannelAdapterBuilder.php:34`).
  - Four other callers pass none: `DbalTransactionInterceptor.php:63`, `DeduplicationInterceptor.php:61`,
    `DbalEventStore.php:627` and `TagVerifySchemaConsoleCommand.php:35`.
  - All six key the same static entry. So when a transaction interceptor opens the connection before the channel
    adapter is resolved, the channel receives the `NativeClock`-bound factory. `delayed_until` and `time_to_live`
    (`DbalProducer.php:175`, `:188`) are then computed from wall time rather than the application's
    `EcotoneClockInterface`.
  - `DbalBackedMessageChannelTest::test_delaying_the_message_with_custom_clock` (`:200`) sends without a surrounding
    transaction, so it does not exercise this ordering.
- **Settle with:** a RED flow test that combines `withTransactionOnCommandBus(true)`, a `StubUTCClock`, and a delayed
  send to a DBAL channel from inside the command handler.
- **Change:** make the clock a required argument at every `new DbalReconnectableConnectionFactory(...)`, and stop
  mutating a shared cached context. Removing the static registry is a larger rule 3 item.
- **Size:** cross (Dbal and PdoEventSourcing call sites, plus Enqueue).

**A4. Rule 4 — a read runs DDL: `DbalProjectionStateStorage::loadPartition()` calls `createSchema()`.** OLD
(`192308a62`, 2025-09).
- **Where:** `packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php:86`, which runs
  `createTable` at `:201-222`.
- **What is wrong:** this is the first shape rule 4 lists as fixed ("`loadByCriteria()` used to create the tag tables it
  was about to read"). Event-driven projections reach it inside the command's transaction.
- **Change:** `loadPartition()` should raise the missing-table instructions and never create the table.
- **Size:** class.
- **Incidental defect, same class, not a guideline item:** `initPartition()` reads `$projectionState->status` at `:133`,
  but `$projectionState` is not defined in that method (it is a parameter only of `savePartition()`, `:152`). PHP emits
  an undefined-variable warning and silently falls back to `UNINITIALIZED`.

### Tier B — the public surface and the message path

**B1. Rule 12a — application-facing values still live in `@internal` namespaces.** Same shape as the three just fixed.
None of these is in `upgrade/namespace-map-2.0.csv` or `upgrade-2.0.md` §13a.

| Internal type | Where an `Api` class makes the application name it | Application call site (evidence) |
|---|---|---|
| `Ecotone\Messaging\Scheduling\TimeSpan` | `packages/Ecotone/Api/Attribute/Delayed.php:24`, `TimeToLive.php:22` | `packages/Ecotone/tests/Lite/Test/AsynchronousChannelsInFlowTestsTest.php:42` `#[Delayed(new TimeSpan(hours: 1))]`; `upgrade-2.0.md:1595`, `:1692` |
| `Ecotone\Messaging\Precedence` | `Api/Attribute/Before.php:20`, `Around.php:20` (also `After`, `Presend`, `ChannelInterceptor`) | `packages/Ecotone/tests/Modelling/Fixture/Retry/InterceptorAfterRetryHandler.php:48` |
| `Ecotone\Messaging\Handler\Logger\LoggingLevel` | `Api/Attribute/LogError.php:19` (and `LogBefore`/`LogAfter` via their parent) | `packages/Ecotone/tests/Messaging/Unit/Handler/Logger/LoggingAttributesTest.php:121` |
| `Ecotone\Dbal\DbaBusinessMethod\FetchMode` | `packages/Dbal/Api/Attribute/DbalQuery.php:19` | `packages/Dbal/tests/Fixture/DbalBusinessInterface/PersonQueryApi.php:27` |
| `Ecotone\Messaging\Config\ModulePackageList` | `Api/ExtensionObject/ServiceConfiguration.php:238` `withModulePackages()` (`@link ModulePackageList` at `:235`) | `upgrade-2.0.md:54`; `packages/Ecotone/tests/Messaging/Unit/Handler/Orchestrator/OrchestratorTest.php:57` |

- **Change:** move each class into `Api`, add CSV rows and a §13a paragraph. For `ModulePackageList`, the constants are
  surface but `allPackages()` and `getModuleClassesForPackage()` are registration plumbing. That class needs a
  maintainer call: split it, or move it whole.
- **Size:** cross.

**B2. Rule 12a (inward) — an internal message-handler builder sits in `Api`.**
- **Where:** `packages/Dbal/Api/ExtensionObject/DbalDeadLetterBuilder.php:29` `extends InputOutputMessageHandlerBuilder`.
- **What is wrong:** every factory is called only from `packages/Dbal/src/Recoverability/DbalDeadLetterModule.php:126-136`.
  The only thing applications use is `STORE_CHANNEL` (`:41`), for example at
  `packages/Dbal/tests/Integration/DeadLetterTest.php:44`.
- **Change:** move the builder to `Ecotone\Dbal\Recoverability`, and give the channel-name constant an `Api` home (for
  example on `DeadLetterGateway`).
- **Size:** cross (about 12 test and fixture imports).

**B3. Rule 13a — reflection on the message path in the new DCB code.** All NEW.

| Where | Runs on | What it re-derives |
|---|---|---|
| `packages/Ecotone/src/Modelling/DecisionModel/DecisionModelStreamResolver.php:29`, `:34` | every decision-handler call, from `DecisionModelAppendInterceptor.php:63` | the `#[Stream]` of a fixed endpoint: `new ReflectionMethod`, `new ReflectionClass`, `newInstance()` each call |
| `packages/Ecotone/src/Modelling/DecisionModel/MessageTagValueResolver.php:34` | every message, from `DecisionModelParameterLoader.php:94` | the tag → property mapping of a message class |
| `packages/Ecotone/src/Modelling/DecisionModel/MessageAggregateIdentifierResolver.php:41` | every message, from `AggregateBackedDecisionModelLoader.php:105` | the identifier → property mapping |
| `packages/Ecotone/src/Modelling/MatchesEventSourcedAggregateTypes.php:26` and `EventStoreEventSourcedRepository.php:70` | every flow-test aggregate load and save | `ClassDefinition::createFor(...)`, outside the registry |
| `packages/Ecotone/Api/EventSourcing/EventCriteria.php:52` | each `EventCriteria::aggregate()` call | `#[AggregateType]` by fresh reflection (low: a userland factory) |

- **Change:** resolve these per endpoint or per message class at compile time, and hand the result in as a constructor
  argument. `EventTagRegistry` / `PropertyEventTagValueSource` already do exactly this for events, so they are the
  template.
- **Size:** class each.

**B4. Rule 1c — two shape checks run per message instead of at bootstrap.** NEW.
- **Case 1:** `packages/Ecotone/src/Modelling/DecisionModel/DecisionModelParameterLoader.php:190` rejects a tag value
  that is an array.
  - The case `6ef6e48df` added is a command property declared `public array $courseId`, a type visible at compile time.
  - Today that command boots and fails on its first `sendCommand`.
  - **Change:** refuse `array`/`iterable`-typed tag properties in `DecisionModelTagResolvabilityGuard`. Keep the runtime
    branch only for untyped or `mixed` properties.
- **Case 2:** `packages/Ecotone/src/Modelling/DecisionModel/DecisionModelLoadedState.php:107`, "Decision model parameter
  '%s' was not loaded for this message…".
  - This describes a decision-model parameter on a method that is not a command, event or query handler. That is a
    compile-time fact.
  - The message gives no way out (rule 1), and no test asserts it (`grep` over `packages/*/tests` finds none).
  - **Change:** add a guard in `DecisionModelModule::prepare()` and a message test.
- **Size:** class plus a test, each.

**B5. Rule 7 — licence branches on the runtime path, inside Apache-2.0 classes.** OLD.
- **Where:**
  - `packages/Ecotone/src/Projecting/EcotoneProjectorExecutor.php:42` and `:129`: `if ($this->licenceDecider->hasEnterpriseLicence())`,
    once per projected event. Line `:129` was added 2026-04-10 (`72f0dbf18`).
  - `packages/Ecotone/src/Messaging/Channel/QueueChannel.php:45` (and `DelayableQueueChannel.php:49`): a licence-derived
    flag branched on per send.
  - `packages/Ecotone/src/Messaging/Handler/Processor/MethodInvoker/Converter/FetchAggregateConverter.php:37`: the
    licence is checked per message. It could be refused at bootstrap (rule 1c).
- **Change:** use two classes chosen through `LicenceDecider::prepareDefinition()`, or a compile-time `LicensingException`
  in the module.
- **Size:** class each.

**B6. Rule 3 — new per-call state on singleton services.** NEW.
- **Case 1:** `packages/Ecotone/src/Modelling/MessageHandling/MetadataPropagator/MessageHeadersPropagatorInterceptor.php:26-27`,
  `:54`, `:81`.
  - The code adds a `$causationChain` stack and a `WeakMap $exceptionsWithCausationChain` to a container singleton
    (`6d97f40d7`).
  - The push and pop are balanced in `finally`, but it is the collector-on-a-service shape that `6b42c7a9b` removed from
    DCB, and no leak test gates it (rule 3: "ships its leak test as the RED commit").
  - **Change:** carry the chain on a header as an immutable value object (the `DecisionModelLoadedState` pattern), and
    mark "already described" on the exception itself.
- **Case 2:** `packages/PdoEventSourcing/src/Dbal/Tag/DbalTagTables.php:19` / `:34` and `packages/PdoEventSourcing/src/Dbal/DbalEventStore.php:66`
  / `:460`, `:473`.
  - Both hold `$ensuredTables` memos that are never invalidated. If a table is dropped in-process (`DatabaseSetupManager`,
    console), the next append skips the missing-table instructions and hits a raw driver error. This consequence is
    **PLAUSIBLE**, not tested.
  - **Change:** stop memoizing, or remove auto-create from these paths (see §5, rule 16 tension).
- **Size:** class each.

**B7. Rule 6a — one value, three names, across three packages.** NEW.
- **The value:** the tag's lock counter as read in the transaction. It travels as:
  - `expectedVersion`: the array key in `packages/PdoEventSourcing/src/Dbal/Tag/DbalTagVersionRegister.php:38`,
    `InMemoryTagVersionRegister.php:32`
  - `capturedVersion`: `DbalTagVersionRegister.php:103`, and `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php:44`
    and `:65`, which is then stored and exposed as `expectedVersion()` (`:103`, `:128`)
  - `tagVersion` / `capturedVersions`: `packages/OpenTelemetry/src/DynamicConsistencyBoundarySpanAttributes.php:54-57`
  - `fromCapturedVersions(array $expectedTagVersions)`: `packages/Ecotone/Api/EventSourcing/AppendCondition.php:41`
- **Second case:** `DecisionModelBatchLoader.php:499` compares a snapshot's `coveredPosition` with `expectedVersion`,
  and `:503` logs the same value as "sequence".
- **Change:** pick one qualifier and rename the whole path in one commit (rule 6a). The `Api` accessor makes this a
  public rename, so it also needs an `upgrade-2.0.md` line.
- **Size:** cross.

**B8. Rule 10 — new tests reaching internals.** NEW. Clear cases only; the weaker setup-through-internals cases are
counted after the table.

| Where | What it does | Change |
|---|---|---|
| `packages/PdoEventSourcing/tests/Integration/Tagging/AggregateBackedDecisionModelSnapshotDbalTest.php:174` (test at `:101`) | `SELECT document FROM ecotone_document_store` to assert the envelope layout | delete; the restart test in the same file proves the snapshot works |
| `packages/Dbal/tests/Integration/Deduplication/MySqlDeduplicationConcurrencyTest.php:54`; test at `:74` | counts rows in the dedup table; `:74` never boots Ecotone and inserts through `DeduplicationTableManager` | assert "a redelivered message is handled once" |
| `packages/PdoEventSourcing/tests/Integration/EventStreamAggregateQueryTest.php:80`, `:87` | `getServiceFromContainer(EventStoreReference::EVENT_STORE_INSTANCE)`; `new EventStreamTableManager(...)->createTable()` | `getGateway(EventStore::class)`; `DatabaseSetupManager` |
| `packages/Ecotone/tests/Modelling/DecisionModel/DecisionModelRetryTest.php:108` | fetches the internal `TransactionStatusTracker` and flips it | drive the retry from a real transaction |
| `packages/Ecotone/tests/Messaging/Unit/Config/ConsoleInvocationResolverTest.php:23` | unit-tests an internal class, named after it | assert the prefix inside a missing-table message |
| `packages/PdoEventSourcing/tests/Integration/Tagging/TagBackfillConsoleCommandTest.php:214` | `SELECT MAX(no)` on the stream table | take the position from the store or the command output |
| `packages/Ecotone/tests/Modelling/Unit/AggregateSnapshotFoldShapeTest.php:71`; `packages/Ecotone/tests/Modelling/DecisionModel/AggregateBackedDecisionModelSnapshotTest.php:204` | forge or read snapshot envelopes through internal key helpers (`SaveAggregateService::getSnapshotCollectionName`, `DecisionModelSnapshotStore::collectionFor`) | at least drop the read-back of `fold_shape` |

- **Weaker cases:** seven tests pre-create tables through `EventStreamSchemaFactory` / `TaggedEventSchemaFactory`, and
  four seed history with raw `INSERT`. Examples are `TagBackfillConsoleCommandTest.php:254` and `:194`.
- **Where the line is ambiguous:** `DbalTaggedContentionTest.php:113` holds a row lock with hand-written
  `UPDATE ecotone_tag_versions`. Rule 3 says contention bugs "are only provable under real contention", so this may be
  the accepted exception.
- **Size:** class per test.

**B9. Rule 2 — nullable service dependency.** NEW code in an old path.
- **Where:** `packages/Dbal/src/DbaBusinessMethod/DbalParameterConfig.php:24`
  (`?AttributeExpressionContextExecutor $expressionExecutor = null`).
- **How it is used:**
  - `DbaBusinessMethodModule.php:240` passes `null`.
  - `DbalBusinessMethodHandler.php:163` branches on `!== null`. This is rule 2's own "wrong" example, line for line.
- **Change:** the sibling `AttributeExpressionExecutor` already has `withoutExpression()` + `hasExpression()`
  (`ClosureExpression/AttributeExpressionExecutor.php:40`, `:51`). Give `AttributeExpressionContextExecutor` the same pair.
- **Size:** class.
- The two clock parameters in A3 are the other live rule 2 instances.

### Tier C — messages, licence placement, style

**C1. Rule 1 — messages that name the problem but give no way out.** NEW.
- `packages/Ecotone/src/EventSourcing/Tagging/Config/EventTaggingModule.php:76`: "…requires Ecotone Enterprise
  Licence." It gives neither the alternative (remove the configuration) nor the docs link that
  `ChannelInterceptorModule` gives.
- `packages/Ecotone/src/EventSourcing/Tagging/DynamicConsistencyBoundaryDisabled.php:14`: the message sends the user to
  register `DynamicConsistencyBoundaryConfiguration` but does not say that it needs Enterprise. An open-core user
  follows it straight into the message above.
- `packages/Ecotone/src/EventSourcing/Tagging/EventTagValueNormalizer.php:64` (and `:69`, `:73`, `:77`, `:81`): the
  messages name neither the event class nor the property. The runtime timing is fine per rule 1c; the content is not.
- `packages/Ecotone/src/Modelling/DecisionModel/DecisionModelDefinitionBuilder.php:287`, `:291`: "should not have any
  parameters" / "should be public", with no why and no alternative.
- **Size:** line each, plus a message assertion in a test.

**C2. Rule 7 — Apache-2.0 files that exist only for an Enterprise feature.** NEW.
- **Where:**
  - `packages/OpenTelemetry/src/TracedDecisionModelBatchLoader.php:18` decorates the Enterprise
    `DecisionModelBatchLoader.php:31`.
  - The same applies to `TraceDynamicConsistencyBoundaryCompilerPass` and `DynamicConsistencyBoundarySpanAttributes`.
- **Change:** confirm the licence with the maintainer ("when in doubt, Apache" applies, so this is flagged, not
  asserted).
- **Size:** line each.

**C3. Rule 17 — new classes without `final`.** NEW; none of these is extended anywhere in the tree.
- `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php:16`
- `packages/Ecotone/src/Messaging/Config/Annotation/ModuleConfiguration/ChannelInterceptor/ChannelInterceptorModule.php:29`
  (rule 13 also requires modules to be `final`)
- `packages/PdoEventSourcing/src/Dbal/Tag/MySqlTaggedEventSchema.php:12`
- **Size:** line each.

**C4. Rule 6 — prose in new files.** NEW. Samples, all read:
- `packages/Ecotone/Api/EventSourcing/EventCriteria.php:85-86`
- `packages/PdoEventSourcing/src/Dbal/DbalEventStore.php:267-268` (an inline `//` comment)
- `packages/Ecotone/Api/EventSourcing/DecisionModelConcurrencyException.php:41` (an `@param` with a trailing description)
- **Count:** about 37 lines across 10 new files, plus about 9 `@param`/`@return` tails.
- **Also:** six prose blocks that 2.0 commits *added* to old files, for example
  `packages/Ecotone/src/EventSourcing/EventStore.php:17` (`ade73e6c5`). The carve-out covers only pre-existing prose, so
  these count.
- **Change:** move each explanation to a name, `upgrade-2.0.md` or a spec.
- **Size:** line each.

**C5. Rule 11 — new test files.** NEW.
- **Static methods on a TestCase:**
  - `packages/PdoEventSourcing/tests/Integration/LegacyEventStreamTest.php:121`, `:126`, `:148`
  - `packages/Ecotone/tests/Modelling/DecisionModel/LiteralEventTagDecisionModelTest.php:84`
- **Comments:** for example `packages/PdoEventSourcing/tests/Integration/Tagging/CouponAggregateWalkthroughDbalTest.php:87`.
- **Assertion messages:** 11. One example is `packages/Ecotone/tests/Messaging/Unit/Config/ImplicitChannelCreationTest.php:58`.
- **New shared `Fixture/` directories:** 9, holding 35 files. The largest is
  `packages/OpenTelemetry/tests/Fixture/DecisionModelFlow`, with 9 files serving one test.
- **Named fixtures that neither exception covers:** about 9. One example is
  `packages/Ecotone/tests/Modelling/DecisionModel/DecisionModelStatelessExecutionTest.php:280` `PaymentForStatelessTest`,
  used only in `classesToResolve`.
- **Size:** line to class each.

## 3. Legacy, deliberately out of scope

| Rule | Count | Carve-out |
|---|---|---|
| 17 (`final` / strict types) | 2 moved `Api` files without `declare(strict_types=1)` and without `final` (`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php`, `Stream.php`, both from `b63cb19b2`); `MySqlEventStreamSchema` is non-final but extended by `MariaDbEventStreamSchema` | "Older files predate both — do not sweep them" |
| 17, vendored | 4 new MIT files copied from php-enqueue (`Amqp/src/Connection/Amqp{Ext,Lib}ConnectionFactory.php`, `Redis/.../RedisConnectionFactory.php`, `Sqs/.../SqsConnectionFactory.php`), 1 without strict types | Third-party code kept close to upstream. **Unsure** whether the rule means to cover it; flagged for the maintainer |
| 6 (prose docblocks) | not counted exhaustively; pervasive in 1.x files | "Leave them where you are not otherwise touching the file" |
| 7 (licence header form) | `/* … */` top-of-file headers in older files | "`bin/check-licence.php` accepts that form, so do not sweep them" |
| 11 (named fixtures) | 92 of 141 test files since 1.x, per the rule itself; old `Fixture/` trees throughout | "Drift to be reduced, not a convention to copy". Only NEW files are reported in C5 |
| 5 (single-implementation interfaces) | 32 old interfaces with exactly one production implementation, for example `TaskScheduler`, `MessageStore`, `HeaderMapper`, `Api/Projecting/ProjectionRegistry.php:12`; 21 constant-holder or proxied-gateway interfaces are fine | Rule 5 has **no** explicit carve-out. I list these as legacy only because removing one is a refactor with no behaviour change and no recorded maintainer intent. **Unsure.** If rule 5 applies to old code, these become findings |
| 10 in old tests | 6 files read: `Dbal/tests/Integration/CombinedChannelBatchForwardingTest.php:961` (SQL on `enqueue`), `MultiTenant/BatchForwardingMultiTenantTest.php:91`, `DeduplicationCleanupMultiTenantTest.php:77`, `JmsConverter/tests/Unit/JMSConverterTest.php:541`, `Tempest/tests/Hardening/DynamicDriverTransactionPinningTest.php:101` (`ReflectionProperty`), `ConsoleProxyArgumentMappingTest.php:91` | No carve-out. Listed here only because they predate the rule's enforcement worktree. **Unsure**; the maintainer may want them in §2 |
| 3 / 13a, old runtime code | Old runtime code outside the DCB/tagging path: `PendingDeliveryRegistry` (by-design collector), dead static `Clock::$globalClock` (`packages/Ecotone/src/Messaging/Scheduling/Clock.php:16`, `:20`), `ReflectionEnum` per conversion in `ScalarToEnumConverter`, `ClassDefinition` per load in `EventSourcedRepositoryAdapter.php:180` | No carve-out. Candidates from the sub-read. Only `Clock.php` was re-read by me |

## 4. Shapes I checked that turned out to be fine

- `packages/*/src/Api` does not exist. The layout from rule 12 holds in every package.
- There are no missing licence headers under `packages/*/src` or `packages/*/Api`.
- Nullable promoted properties in `src` (50 hits) are almost all lazily-initialised state, value fields or builders,
  not injected services. `DirectChannel::$messageHandler` is subscription state, not a mode switch. `CollectorSenderInterceptor::send(?WithoutMessageCollector)`
  is an optional attribute parameter, not a service.
- The 10 new interfaces all have two or more implementations along a real seam (open core vs Enterprise, in-memory vs
  DBAL, one per engine).
- About 40 of the 56 licence checks are compile-time `LicensingException` guards in `compile()`/`prepare()`, which is
  the accepted shape.
- `compile(): Definition`, `Assert`, `Definition` and `DefinedObject` inside `Api` are all framework-called, as the
  brief says. So are the channel builders' `getHeaderMapper()` and the attribute parent classes.
- There is no statement counting and no reflection in new test files, and there are no camelCase test methods.
- `getServiceFromContainer(EventStore::class)` in tests (about 30 sites) fetches the gateway interface, not an internal
  reference.
- `EventTagRegistry` / `PropertyEventTagValueSource` build `ReflectionProperty` once, from compiled definitions. That is
  the right shape, and the model for fixing B3.
- `DbalTagVersionRegister::capture`, `DbalTaggedEventReader` and `DbalEventStore::loadByCriteria` issue no DDL, which
  is rule 4 as fixed by `4bb23eb51`.
- No exception message in `src`/`Api` uses a kebab-case console option.

## 5. Rules I could not audit statically, and where the guide contradicts itself

**Needs a test run:**
- A3 (the clock). Proving it needs the RED test described there.
- B6 (the table memos). Proving it needs a drop-then-append test on PostgreSQL.
- Rule 9 in-memory parity (`InMemoryEventStore` vs `DbalEventStore`). Only a shared suite run can show a divergence.
- Rule 3's leak property for `MessageHeadersPropagatorInterceptor`. It needs a repeated-execution test in the style of
  `DecisionModelStatelessExecutionTest`.

**Needs a maintainer decision:**
- **Rule 12a scope.** `Ecotone\EventSourcing\EventStore` (`packages/Ecotone/src/EventSourcing/EventStore.php:14`) is the
  gateway that `AGENTS.md` and rule 10 tell users to fetch with `getGateway(EventStore::class)`, yet it lives in `src`.
  `Ecotone\Lite\EcotoneLite` / `FlowTestSupport`, the entry point of every test in the guides, is in the same position.
  Neither is in the namespace map. Both are either leaks by 12a's letter or deliberate exceptions nobody wrote down. The
  same question applies to `Ecotone\Messaging\Future` (`Api/Gateway/MessagePublisher.php:28`) and the `Projecting`
  interfaces an application implements (`ProjectionStateStorage`, `StreamSource`, `PartitionProvider`).
- **`ProjectingManager` in `Api`.** It is a runtime service with internal constructor arguments, placed in `Api` on
  purpose by the mapping spec. It fails 12a's inward test ("no services") unless that placement is written down as an
  exception.
- **Vendored MIT code and rule 17.** See §3.

**The guide contradicts the code it cites:**
- **Rule 16 vs `AutoCreateLevel::CreateOnly`.** Rule 16 says "Ecotone never issues DDL on the message path at all", but
  the flow-test default `CreateOnly` creates tables on the append path:
  - `packages/PdoEventSourcing/src/Dbal/Tag/DbalTagConditionalAppender.php:50-51` asserts an active transaction and then
    calls `ensureExist()`, which may `CREATE TABLE` inside it.
  - `DbalEventStore.php:469-470` does the same.
  - The auto-create family in `DbalOutboundChannelAdapter`, `DeduplicationInterceptor`, `DbalDocumentStore` and
    `DbalProjectionStateStorage` does the same.

  MySQL is excluded, so the commit hazard the rule exists for does not arise. Either the rule should say "except
  `CreateOnly` on engines with transactional DDL", or those call sites go. I did not list them as violations, because
  the rule's own text endorses `CreateOnly` two sentences later.
- **Rule 1c cites `MessageTagValueResolver::resolve()` as the model runtime entry point**, but it builds a
  `ReflectionClass` per message (B3), which rule 13a forbids.
- **Rule 1a cites `6ef6e48df` as a "booted silently" fix**, but the fix is a runtime throw (B4).
- **Rule 1a/1c cite `AggregateCounterTagGuard` as *the* pattern**, but it reads `#[EventSourcingAggregate]` by fresh
  reflection (`packages/Ecotone/src/EventSourcing/Tagging/AggregateCounterTagGuard.php:41`), which rule 13a says to take
  from the registry. It is compile-time, so the cost is low, but it is the example agents will copy.
- **Rule 5 cites `AppendableStore` as the wrong shape** ("exists only to let the strategy call back into its caller"),
  yet it is still in the tree (`packages/Ecotone/src/EventSourcing/EventStore/AppendStrategy/AppendableStore.php:12`),
  with two implementations.
- **Rule 6a says `a8f23212c` settled `EventsTags::sequencedBy(array $versionsByTagKey)`**, but
  `packages/Ecotone/src/EventSourcing/Tagging/EventsTags.php:67` still takes `$versionsAfterBumpByTagKey` and returns
  sequences. That is a deliberate conversion, so the rule's evidence overstates the fix.
