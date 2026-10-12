# Ecotone 2.0 — functionality broken or lost, not changed on purpose (wave 16 review)

Base `de38730bc` (the `origin/main` tip, merge base) → HEAD `c53b77ea9` (`dgafka/ecotone-2-0-work`).
`git diff --name-status -M de38730bc..HEAD`: 782 commits, 2306 M · 593 A · 235 R · 211 D · 1 T — the brief's numbers,
re-derived and confirmed. Read-only review: no production code or test was changed; this file is the only output.

Classification used for every candidate (from the brief): **A** documented change · **B** deliberate removal,
recorded · **C** moved or renamed · **D** lost, nothing records it · **E** still works, nothing asserts it. A **C
without a correct CSV row** is reported as a finding against `upgrade/namespace-map-2.0.csv`, and a place where
`upgrade-2.0.md` disagrees with the code is reported as a **GUIDE** finding — both kinds the brief asked for.
Finding numbers R1… are fixed and are never to be renumbered.

## Summary

### Counts per classification

| Stream | Examined | A | B | C | D | E | CSV / GUIDE / other |
|---|---|---|---|---|---|---|---|
| W1 — every 1.x class FQCN under `packages/*/src\|Api` that HEAD no longer has (307; the brief's 70 are a subset) | 307 FQCNs | — | 81 | 226 (207 with a correct CSV row, 19 without) | **0** | — | R1, R2 |
| W2 — deleted test classes with no same-named survivor (29), plus the 2 same-named ones | 31 files | — | capabilities of 6 files removed and documented | 2 moved / folded | 1 (R15, unsure) | **11** (R5, R6, R7, R8, R10, R11, R12, R13, R14, R16, R17) | R18 (GUIDE), R9, R19 (other) |
| W3 — attributes (all 125), `Api/` and CSV-pair public signatures (55 classes differ, all checked), framework config keys, gateways / test surface / configuration defaults / console commands | see W3 | everything not listed | — | — | **6** (R20, R21, R25, R26, R27, R31) | — | R3, R4, R22, R23, R24, R28, R29, R30 (GUIDE) |

Totals: **31 findings** — D 7, E 11, CSV 2, GUIDE 9, other 2. **No D in W1**: every deleted class traces to a recorded
decision. The three the brief could not explain — `MessageStore`, `MessageGroupStore`, `TaskScheduler` — are recorded
on the ledger's rule-5 table (`docs/conventions-outstanding.md:250`, `:259`), which the brief's spot-check did not
consult. Nothing found is a capability lost outright; the D findings are silent renames, a changed default and two
methods whose meaning moved under an unchanged name.

### Findings ranked by what an application would notice first

1. **R25** — `EventSourcingConfiguration::withEventStreamTableName()` kept its name and now names the default event
   table instead of the 1.x catalogue: a 1.x application that customised it points its events at the wrong table.
2. **R4** — container ids of `CommandBus`, `EventBus`, `QueryBus`, `EventStore` (every gateway §13/§13b moved)
   changed, while the guide twice says no id moved before §13e. A string service id or binding with the 1.x FQCN breaks.
3. **R24** — Laravel silently ignores a `skippedModulePackageNames` key left in `config/ecotone.php` and loads every
   package (Symfony refuses the key).
4. **R26** — `FlowTestSupport::withEvents()` seeds `ecotone_event_stream`, not `'default'`; undocumented.
5. **R27** — `ExecutionPollingMetadata::withTestingSetup()` now handles 100 messages, not 1; `PollingMetadata`'s
   same-named method still handles 1; §9 names only the static factory.
6. **R3**, **R22**, **R20**, **R21**, **R31** — undocumented removals and named-argument renames that fail loudly:
   `allPackagesExcept()` / `ASYNCHRONOUS_PACKAGE`, `withDocumentStore(enableDocumentStoreStandardRepository:)`,
   `#[ProjectionStateGateway(projectioManagerReference:)]` (also silently ignored now), attribute getters,
   `#[Stream]` final, dead-letter builder plumbing.
7. **R1**, **R2** — CSV gaps: the vendored `Enqueue\Dbal\*` classes, and the 1.x names of `Serializer` and
   `StandardRepository`; §5 wrongly says `DbalContext` did not move.
8. **R28**, **R29**, **R30**, **R23**, **R18** — guide statements wrong or incomplete for a 1.x reader.
9. **R6** — SQLite and MariaDB event-store suites run in **no** CI job, although §4 advertises SQLite.
10. **R15** — a required, missing `#[ConfigurationVariable]` fails on the first message rather than at build (unsure
    whether 1.x refused it at an application's bootstrap).
11. **R5**, **R7**, **R8** — gap tolerance unproved for `#[Polling]`/`#[Asynchronous]` projections; cross-module
    guarantees lost four CI dimensions; encrypted event storage proved only through raw SQL.
12. **R10–R14**, **R16**, **R17** — behaviours the deleted builder/invoker/channel unit tests proved that nothing
    asserts now, one replacement test vacuous, one a tie order §15 promises.
13. **R9**, **R19** — a committed log file; a merge that dropped main's test silently.

### What this review did NOT examine

- **W3 behaviour inside the 2306 modified files** beyond the public surface: module/runtime diffs were not read
  file by file. Behaviour changes were sampled through the `Api/` signature diff, attribute diff, commit subjects
  mentioning `#[`, and the guide; a changed runtime behaviour with an unchanged signature is detectable here only if a
  commit subject or a deleted test pointed at it.
- **Classes outside `packages/*/src|Api`** were walked for `packages/Symfony/SymfonyBundle`,
  `packages/Symfony/DependencyInjection` and `packages/Laravel/config` only (two moves, both with correct CSV rows).
  Benchmarks, `Monorepo/ExampleApp*` and `quickstart-examples/` were not walked.
- **Test files that survived but lost test methods.** W2 is file-level by brief; a test class that kept its name and
  dropped methods was not diffed method by method (except the ones the deleting commits named: `RouterBuilderTest`,
  `SplitterBuilderTest`, `KafkaChannelAdapterTest`, `ConnectionReferenceCredentialsTest`).
- **Tests were not run.** Every coverage verdict is from reading the HEAD test bodies; "covered" means a test asserts
  it, not that the test passes. The W2 E-findings would be confirmed by a mutation per behaviour; none was made.
- **The 211 deleted non-PHP files** (skills, fixtures, logs) were not classified, except `packages/LiteApplication/*`
  (B, §7/§14).
- **1.x documentation** was used as a reachability oracle through mechanical extraction only: `#[Attribute]` names,
  `use Ecotone\…` imports (89) and `Class::member` references on surviving 1.x classes. Prose descriptions of
  behaviour were not read page by page.
- **Enterprise-only behaviour** (`licence Enterprise` files) was not separately examined beyond what the above
  sweeps touched.
- **Defaults not compared:** Symfony bundle `Configuration.php` defaults and Tempest `EcotoneConfig` defaults (only
  the key renames were checked); SQS, Redis, Kafka and AMQP *inbound* adapter defaults beyond their `Api` builders and
  publisher configurations. Property initialisers and constants were compared for every CSV pair.
- **How attributes are interpreted at bootstrap** — the attribute sweep compared constructors, targets and defaults;
  module-side interpretation was sampled only through the 45 commit subjects containing `#[` and the guide.
- **The §14/§15 behavioural claims** were spot-checked, not each verified against the code.

### Brief claims that did not hold

| Brief claim | Reality |
|---|---|
| 70 deleted classes, no survivor, no map row | 70 reproduces with the brief's method (file basename, `src`/`Api` paths). By declared FQCN the 1.x classes HEAD no longer has are **307**: 207 with a CSV row, **82** with no row and no same-named survivor (the 70 plus **12 renamed in place**, which the basename method cannot see), and **18** whose short name survives under another FQCN — of which 8 are *not* the same class (`MessageConverter`, `Transaction`, the v1 `Projection`, `ProjectionExecutorBuilder`, `PollingProjectionChannelAdapter`, `Prooph\Metadata\{MetadataMatcher,Operator,FieldType}`: losses the basename method counts as survivors) and 10 are the vendored `Enqueue\Dbal\*` moves (R1). Two of the brief's 70-method hits are test fixtures under `packages/Symfony/tests/phpunit/*/src` |
| `MessageStore`, `MessageGroupStore`, `TaskScheduler` cannot be explained | Recorded on the ledger's rule-5 table: `docs/conventions-outstanding.md:250` (`TaskScheduler` collapsed into `SyncTaskScheduler`, `f5db099f8`), `:259` (message store deleted, "the maintainer chose deletion", `6e8914781`). B |
| `upgrade-2.0.md` is 2688 lines | 2787 at HEAD |
| `MissingReferenceRuntimeFailureTest` (Tempest) survives in Laravel | The Laravel file of that name is a different test that already existed at base. The Tempest one was folded into `packages/Tempest/tests/Hardening/RealBootRuntimeFailureTest.php` (`d336ecc99`), method for method |
| (commit `e29ee123e`) `addCatchAllRoute()` "has no attribute wiring anywhere — dead capability" | Wrong: an `object`-typed handler reaches it; `7d3cbdd58` later added `BusRoutingTest::test_a_handler_typed_to_the_native_object_type_catches_every_event_class` |
| (commit `fdce0fabd`) the three `__TypeId__` cases test "the conversion service's own stubbed behaviour" | They are reachable with real converters (R17) |
| (commit `a7890abe1`) the replacement releases with `run(…, releaseAwaitingFor:)` | Stale: `3317f76e7` removed that parameter |
| (ledger `:428`) "Zero raw-SQL assertions remain" | One remains, pre-dating 2.0 — R8 |

---

## W1 — deleted classes

**Method.** Every PHP file under `packages/*/src` and `packages/*/Api` at both commits was parsed for its `namespace`
and first `class|interface|trait|enum` declaration, giving 1175 FQCNs at base and 1256 at HEAD. Every base FQCN absent
at HEAD was checked against the CSV's `old_fqcn` column, then against HEAD's short names. Separately, every CSV row was
checked both ways: its new FQCN exists at HEAD (all 216 do) and its old FQCN existed at base (9 do not — see R2).

### W1 result: 307 base FQCNs gone, 0 lost

| Group | Count | Class | Citation |
|---|---|---|---|
| CSV row, target exists at HEAD | 207 | C | `upgrade/namespace-map-2.0.csv` |
| `Ecotone\Messaging\Transaction\*` (`Transactional`, `Transaction`, `TransactionFactory`, `TransactionInterceptor`, `TransactionException`, `NullTransaction`, `NullTransactionFactory`) and `WithRequiredReferenceNameList` | 8 | B | `upgrade-2.0.md` §7b (`:1127`); `f293c22f7`, `2f676e107`; ledger `:260` |
| `MessageConverter`, `MessageConverterRegistry`, `MessageConvertingException` | 3 | B | §7 table (`:1097`); `ee9d7208a`; ledger `:239` |
| `SagaIdentifier`, `TargetAggregateVersion`, `WithAggregateEvents`, `AggregateIdentifier`, `AggregateIdentifierMethod`, `AggregateVersion`, `TargetAggregateIdentifier` | 7 | B | §7 table (`:1076-1080`); `e85ef8592` |
| `EcotoneLiteConfiguration`, `EcotoneLiteApplication`, `AutowiringContainer` (whole `ecotone/lite-application` package) | 3 | B | §7 table (`:1082`), §14 (`:2208`); `e85ef8592` |
| `AmqpDistributedBusConfiguration`, `AmqpDistributionModule` | 2 | B | §6 (`:1037`); `b6304bb50` |
| v1 projection runtime: `ProjectionManager`, `ProjectionRunningConfiguration`, `ProjectionSetupConfiguration`, `ProjectionLifeCycleConfiguration`, `ProjectionStatus`, `ProjectionNotFoundException`, `ProjectionStreamSource`, `ProjectionManagerBuilder`, `ProjectionChannelAdapter`, `ProjectionEventHandler`, `ProjectionExecutorBuilder` (v1), `PollingProjectionChannelAdapter` (v1), `CachingInMemoryProjectionManager`, `CachingInMemoryReadModelProjector`, `InMemoryProjectionManager`, `InMemoryEventStoreReadModelProjector`, `LazyProophProjectionManager`, `LegacyProjectionsTableManager`, `GapDetection`, `GapDetection\DateInterval`, `ProophReadModel`, `ProophProjectionRunningOption`, `Attribute\Projection` (v1), `Prooph\Metadata\{MetadataMatcher,Operator,FieldType}`, `EventSourcingModuleRoutingExtension` | 27 | B | §3 (`:83-176`, "v1's Prooph-based projection runtime … gone entirely", no-replacement list `:116-121`); `19b67095f`, `d1e9ada82`, `0d7f0f704` |
| Prooph event store: `LazyProophEventStore`, `EcotoneEventStoreProophWrapper`, `ProophEventMapper`, `ProophMessage`, `ProophInMemoryEventStoreAdapter`, `FromProophMessageToArrayConverter`, `InterlopMariaDbSimpleStreamStrategy`, `InterlopMysqlSimpleStreamStrategy`, `PdoStreamTableNameProvider`, `StreamIteratorWithPosition` | 10 | B | §4 (`:186-197`: "`Prooph\*` classes are no longer available", persistence strategies deleted — the only 1.x doc use of the first and sixth, `persistence-strategies.md:132,158`) ; `cc7520f26` |
| DBAL 3 shims `QueryBuilderProxy`, `SchemaManagerCompatibility`; `ImplicitCommit` | 3 | B | §14 (`:2258`); §8 (`:1169`, `:1197`); `87cfa1adf`, `6dd639080`, `9eb4cfcea` |
| Rule-5 collapses and dead stubs: `CancellableAmqpStreamConsumer`, `ConversionServiceDecorator`, `ProxyBuilder`, `FieldFactoryInterface`, `TaskScheduler`, `TriggerContext`, `TaskExecutor`, `Modelling\MessageBus`, `BeforeSendGateway`, `StorageCommandBus`, `StorageEventBus`, `NullInboundGatewayEntrypoint`, `LazyRepositoryBuilder`, `MessageStore`, `MessageGroupStore`, `MessageGroup`, `SimpleMessageStore`, `InMemoryMessageGroup` | 18 | B | ledger rule-5 table `docs/conventions-outstanding.md:238-259`; `TaskExecutor` also §14 `:2169` |
| Renamed, internal: `DbalDeadLetterHandler` → `DeadLetterHandler` + `DbalDeadLetterStore` (`3ded6db71`), `AggregrateModule` → `AggregateModule` (`66f2ef586`, ledger `:422`), `InMemoryStandardRepository` → `InMemoryStateStoredRepository`, `StandardRepositoryAdapter(Builder)` → `StateStoredRepositoryAdapter(Builder)`, `ProjectionV2StateHandler` → `ProjectionStateHandler`, `TempestConnectionModule` → `TempestModule` | 7 | C, no row | internal (`src`), not named by 1.x documentation; the project's stated policy is no row for internal types (`2f676e107`, ledger `:259-260`). Not reported |
| `Enqueue\Dbal\*` (10) | 10 | C, **no row** | **R1** |
| `Ecotone\Messaging\Gateway\Converter\Serializer`, `Ecotone\Modelling\StandardRepository` | 2 | C, **wrong row** | **R2** |

Total B 81 (`8+3+7+3+2+27+10+3+18`), C 226 (`207+7+10+2`): 307.

**One B worth the maintainer's eye, not a finding:** `StorageCommandBus` / `StorageEventBus` were public-looking
recording test doubles (`StorageCommandBus::create()`, `getCalls()`) beside `CommandBus` in `Ecotone\Modelling`. An
application could have used them in its own unit tests; no 1.x documentation page names them, the ledger records their
deletion (`:257`), the guide does not. Same shape for `SimpleMessageStore`.

### R1 — the vendored `Enqueue\Dbal\*` classes moved with no CSV row, and the guide says they did not move

- **What an application can no longer do:** name `Enqueue\Dbal\DbalContext`, `DbalMessage`, `DbalConsumer`,
  `DbalProducer`, `DbalDestination`, `DbalSubscriptionConsumer`, `DbalType`, `JSON`, `DbalConsumerHelperTrait` or
  `ManagerRegistryConnectionFactory` — 1.x `ecotone/dbal` shipped them itself (`packages/Dbal/composer.json` at base:
  `"Enqueue\\Dbal\\": "src/EnqueueDbal"`, and `replace: enqueue/dbal`). They are now `Ecotone\Dbal\Connection\*`
  (`de08a095e`, e.g. `R099 packages/Dbal/src/EnqueueDbal/DbalContext.php → packages/Dbal/src/Connection/DbalContext.php`).
- **Classification:** C with no CSV row → finding against the CSV; and **GUIDE**: `upgrade-2.0.md:1028-1032` says code
  reaching into "`Ecotone\Dbal\Connection\DbalContext`) is unaffected" and to type-hint
  "`Ecotone\Dbal\Connection\DbalContext` as before". In 1.x that class was `Enqueue\Dbal\DbalContext`
  (`packages/Dbal/src/EnqueueDbal/DbalContext.php:5` at base). The guide is wrong, not the code. Only
  `Enqueue\Dbal\DbalConnectionFactory` has a row (`:167`).
- **Reachability:** none of the ten is named by 1.x documentation; all are internal plumbing an application reaches
  only by writing transport code. Low impact, but the guide line is factually wrong.
- **Test:** n/a (a rename). **Confidence:** certain.

### R2 — two CSV rows map 2.0-intermediate names; the 1.x names have no row

- **What is no longer covered:** a 1.x application imports `Ecotone\Messaging\Gateway\Converter\Serializer` and
  implements `Ecotone\Modelling\StandardRepository` — both on 1.x documentation pages
  (`share-documentation` `main`: `tutorial-php-ddd-cqrs-event-sourcing/php-interceptors-middlewares.md:221,223`,
  `php-domain-driven-design.md:82`, `modelling/command-handling/repository/configure-repository.md:12-54`). The CSV
  rows are `Ecotone\Messaging\Gateway\SerializerGateway,…` (`:157`) and `Ecotone\Modelling\StateStoredRepository,…`
  (`:201`) — names that exist only between `e85ef8592` (2026-08-22) and the `Api` moves (`4dc897d6f`, `97c0c3955`).
  A mechanical rewrite driven by the CSV leaves both 1.x imports untouched.
- **Classification:** C, finding against the CSV. The guide's §7 table gets both right (`:1081`, `:1084`); §13j's
  table repeats the intermediate name (`:2110`).
- Also intermediate, harmless (no 1.x class to miss): `Ecotone\Dbal\DbalConnectionReference` (no such 1.x class).
- **Commit:** `e85ef8592` (renames), CSV rows from `4dc897d6f` / `97c0c3955`. **Confidence:** certain.

---

## W2 — deleted test classes

**Method.** `git diff --name-status` `D` entries ending `Test.php`: 31; 29 have no declared class of that name at HEAD
(the brief's 29, confirmed). For each, every old test method was matched to a HEAD test method that asserts the same
behaviour, reading the HEAD test body, or marked NONE / UNREACHABLE. Done by two sub-reviews; the critical claims
below were re-checked by hand.

### File verdicts

| Deleted test (commit) | Verdict | Replacement |
|---|---|---|
| `Channel/DirectChannelTest` (`a7890abe1`) | covered | `DirectAndPublishSubscribeChannelTest` |
| `Channel/PublishSubscribeChannelTest` (`a7890abe1`) | covered | same, with two subscribers |
| `Channel/DelayableQueueChannelTest` (`a7890abe1`) | **partial** → R10 | `DelayedMessageReleaseTest`, `TestClockTest` |
| `Distributed/DistributedBusWithServiceMapTest` (`b6304bb50`) | covered (the legacy-only cases are unreachable: the legacy API is gone, §6) | `DistributedBusWithExplicitServiceMapTest` (19 same-named methods) |
| `ErrorHandler/ErrorHandlerTest` (`af6dd366d`) | **partial** → R11 | `DelayedRetryDelayAndDeadLetterTest`, `DelayedRetryBackOffTest`, `ErrorChannelTest` |
| `Gateway/GatewayHeaderArrayBuilderTest`, `GatewayPayloadExpressionBuilderTest`, `ParameterConverter/GatewayPayloadBuilderTest` (`144d8d789`) | covered | `GatewayParameterConverterAttributesTest` |
| `Processor/AllHeadersBuilderTest`, `PayloadBuilderTest`, `PayloadExpressionBuilderTest`, `ReferenceBuilderTest` (`c6eec6112`) | covered (`#[Reference]` by class name only implicitly, through fixtures) | `ParameterConverterAttributesTest`, `ExpressionFailureContextTest` |
| `Processor/ConfigurationVariableBuilderTest` (`c6eec6112`) | **partial** → R14, R15 | `ParameterConverterAttributesTest` |
| `Processor/HeaderBuilderTest`, `HeaderExpressionBuilderTest` (`c6eec6112`) | **partial** → R16 | `ParameterConverterAttributesTest`, `HeaderConversionTest`, `ExpressionFailureContextTest` |
| `Processor/MethodInvokerTest` (`fdce0fabd`) | **partial** → R17 | `MethodArgumentResolutionTest` |
| `RequestReplyProducerTest` (`f459f27fd`) | **partial** → R12 | `RequestReplyAndRoutingSlipTest`, `Splitter/SplitterAttributeTest` |
| `Router/BusRoutingMapTest` (`e29ee123e`) | **partial** → R13 | `BusRoutingTest`, `MessageBusTest` (optimized/not-optimized provider unreachable: the container always optimizes) |
| `Scheduling/SyncTaskSchedulerTest` (`01ee5c7e9`) | covered | `ScheduledEndpointTest` (both cases; ledger `:255`) |
| `Store/InMemoryMessageGroupTest`, `SimpleMessageStoreTest` (`6e8914781`) | unreachable — the classes were never registered or constructed outside their own tests (B, ledger `:259`) | — |
| `PdoEventSourcing/…/CategoryStreamTest` (`d1e9ada82`) | capability removed, documented (§3 `:109-112`, §4 `:196`); "every instance of an aggregate type" still proved by `SynchronousEventDrivenProjectionTest::test_building_synchronous_event_driven_projection` | — |
| `CustomEventStreamTest` (`88d482323`) | covered: `EventStoreProjectingIntegrationTest::test_it_handles_custom_name_stream_source` (`:455`) | — |
| `SpecificEventStreamTest` (`88d482323`) | capability removed with the stream-per-aggregate layout, **only implicitly documented** → R18 | — |
| `GapDetectionInSynchronousProjectionTest` (`d1e9ada82`) | configuration removed (§3 `:118-121`); behaviour covered by `GapAwarePositionIntegrationTest:111,128,153` (PostgreSQL/MySQL/MariaDB; skipped on SQLite) | — |
| `GapDetectionInPollingProjectionTest` (`d1e9ada82`) | configuration removed (§3); **behaviour uncovered** → R5 | — |
| `MultiplePersistenceStrategiesTest` (`88d482323`) | strategy choice removed (§4 `:195-197`); separate stream tables, custom-stream projection and `linkTo` from a plain handler covered (`MultiStreamPartitionedProjectionTest:489`, `EventStoreProjectingIntegrationTest:455`, `LinkingEventsWithoutProjectionTest:29`, `EventStreamEmitterStreamTest:136`) | — |
| `ProjectionWithMetadataMatcherTest` (`d1e9ada82`) | removed, documented (§3 `:120`); `EventStore::load()` matching still proved by `EventStoreConformanceTest` | — |
| `LiteApplication/…/EcotoneLiteApplicationTest` (`e85ef8592`) | package removed (§7 `:1082`, §14 `:2208`); cached `EcotoneLite::bootstrap()` still exercised (`CachedBootstrapIsolationTest:72-80`) | — |
| Tempest `DbalConnectionRequirementWithConnectionTest` | moved unchanged to `Monorepo/CrossModuleTests/Tests/Tempest/` (`fecad541c`) | runs in the root suite only (Tempest was already root-only in CI) |
| Tempest `MissingReferenceRuntimeFailureTest` | folded into `packages/Tempest/tests/Hardening/RealBootRuntimeFailureTest.php` (`d336ecc99`) | covered |

### R5 — gap tolerance through a `#[Polling]` or `#[Asynchronous]` projection is no longer proved

- **No longer proved:** that a projection run through its polling inbound adapter (`ecotone:run <endpointId>`), or
  through an asynchronous channel, projects past a hole in `no` and later projects the event that commits into it.
- **Classification E.** The capability exists (`PollingProjectionChannelAdapter` reaches the same
  `ProjectingManager::execute()` → `EventStoreGlobalStreamSource`, `packages/Ecotone/src/Projecting/EventStoreAdapter/PollingProjectionChannelAdapter.php:25-28`);
  only its *configuration* was removed on purpose (§3 `:118-121`).
- **Evidence:** `packages/PdoEventSourcing/tests/Integration/GapDetectionInPollingProjectionTest.php` (3 methods, all
  drive `->run()` past a deleted row), deleted in `d1e9ada82`. The surviving gap tests
  (`GapAwarePositionIntegrationTest:111`, `:128`, `:153`) use `DbalTicketProjection`, synchronous
  (`Projecting/Fixture/DbalTicketProjection.php:38`); no PdoEventSourcing test using `#[Polling]` creates a gap or a
  late commit. Rule 10a asks for each entry point.
- **Covered now:** no. **Confidence:** likely.

### R6 — the SQLite and MariaDB event stores run in no CI job

- **No longer proved in CI:** every PdoEventSourcing guarantee on SQLite and on MariaDB, although §4 says "**SQLite is
  now a supported event store engine**, alongside PostgreSQL, MySQL and MariaDB" (`upgrade-2.0.md:328`), MariaDB has
  its own schema class (`MariaDbEventStreamSchema`), and ledger rows repeatedly record suites "green on PostgreSQL,
  MySQL, MariaDB and SQLite" — measured locally.
- **Classification E** (proved locally, by no CI job). `.github/workflows/split-testing.yml:230-231` skips
  `PdoEventSourcing|DataProtection|Tempest` in its SQLite pass, with the comment "SQLite event store not supported"
  (from main's `e3887e735`, #609, which predates 2.0's SQLite event store). No workflow defines a MariaDB service or
  DSN (`grep -i maria .github/` is empty; `docker-compose.yml:27,39` has one for local runs only).
  `test-monorepo.yml:143-147` runs PostgreSQL with MySQL secondary, no SQLite, no MariaDB.
- **Covered now:** in tests yes (e.g. the conformance suites), in CI no. **Confidence:** certain.

### R7 — guarantees moved into `Monorepo/CrossModuleTests` lost four CI dimensions

- **Weaker now:** `Monorepo/CrossModuleTests` has no `composer.json` and runs only as the root suite "Monorepo tests"
  (`phpunit.xml.dist:78-80`), in `test-monorepo.yml`: PHP 8.5 only, prefer-stable only, PostgreSQL primary with MySQL
  secondary (`:22-25`, `:143-147`). The per-package job (`split-testing.yml:26-31`, `:206-232`) runs PHP 8.2, 8.5 and
  8.2 `--prefer-lowest`, each with PostgreSQL, MySQL-primary and SQLite passes.
- **Classification E (weakened coverage).** Moved by wave 14 (`c71367790`, `5fc9576c8`, `96db93cfb`, `c5e294227`,
  `7539f3727`, `fecad541c`), each now proved only in the root job:

  | Guarantee | From | Lost |
  |---|---|---|
  | `#[Sensitive]` event fields are stored encrypted (`DataProtection/EncryptStoredEventsTest`) | DataProtection | 8.2, lowest, MySQL-primary |
  | DCB smoke on Laravel and on Symfony: tagged append, decision model, missing-table instructions naming the framework's console command (`Laravel/DcbSmokeTest`, `Symfony/DcbSmokeTest`) | Laravel, Symfony | 8.2, lowest (Laravel 11 / Symfony 6.4 floors), MySQL-primary, SQLite |
  | A failed `#[KafkaConsumer]` message reaches the DBAL dead letter and replays (`Kafka/KafkaChannelAdapterDeadLetterTest`) | Kafka | 8.2, lowest, MySQL-primary, SQLite |
  | `#[WithTenantResolver]` on a Kafka consumer (`Kafka/KafkaTenantResolverTest`) | Kafka | same |
  | `%env()%` topic on `#[KafkaConsumer]` survives with DBAL error handling, #669 path B (`Symfony/EnvPlaceholderKafkaConsumerTest`) | Symfony | same |
  | `OutboxForwardingMessageChannel` with a non-DBAL source refused at bootstrap (`Kafka/CombinedChannelForwardingTest`; asserts the class only, not the message) | Kafka | same |
  | Seven Tempest files | Tempest | nothing — Tempest was already excluded from split testing (`split-testing.yml:144-147`) |

- **Covered now:** yes, root job only. **Confidence:** certain on the CI shape; the importance ranking is judgement.

### R8 — a raw-SQL assertion on Ecotone's own table remains, and the ledger says none does

- **No longer proved at the userland level:** `Monorepo/CrossModuleTests/Tests/DataProtection/EncryptStoredEventsTest.php:85`
  reads `select payload from ecotone_event_stream` and decrypts each column; the test ends `self::assertTrue(true)`
  (`:95`) — the proof is "decryption did not throw". Present at base (`packages/DataProtection/tests/Integration/EncryptStoredEventsTest.php:85`),
  moved by wave 14.
- **Classification E** (the guarantee R7 ranks first is proved only through Ecotone's table layout, against rule 10),
  and the ledger's "**Zero raw-SQL assertions remain**" (`docs/conventions-outstanding.md:428`) is wrong. A userland
  form exists: read the stream through the `EventStore` gateway with `deserialize: false` and assert the field is not
  the plaintext. **Confidence:** certain.

### R9 — merge `00045b9a1` committed a 3584-line `laravel.log`

- `packages/Laravel/tests/DcbSmoke/storage/logs/laravel.log` is tracked; neither parent of the wave-14 merge has it
  (`git log -m --first-parent --diff-filter=A` names `00045b9a1`), and its test directory moved away in the same
  wave. Not D/E — repository hygiene, recorded because nothing else would catch it. **Confidence:** certain.

### R10 — the in-memory delayable channel: same-due-time order, `DELIVERY_DELAY` stripping, relative release

- **No longer proved:** (a) messages that become due at the same instant are delivered in send order — which
  `upgrade-2.0.md` §15 promises ("the earliest due message first (send order for equal due times)");
  `TestClockTest::test_due_delayed_messages_are_released_in_the_order_they_became_due` (`:108`) uses two *different*
  delays, and `receive()` was rewritten in `3317f76e7`. (b) The delivered message no longer carries
  `MessageHeaders::DELIVERY_DELAY` (old `DelayableQueueChannelTest.php:64`, `:96`, `:144`, `:191`; observable through
  `#[Headers]`). (c) Release by relative `TimeSpan`/`int` through the `Api` gateway
  `MessagingTestSupport::releaseMessagesAwaitingFor()` (old `:56`, `:60`, `:93`, `:141`) — narrow, since `run()` releases
  at the clock's time.
- **Classification E.** Deleted in `a7890abe1`; the replacement `DelayedMessageReleaseTest` covers not-before-due,
  release at due time and ascending-delay order.
- **Covered now:** (a) no — a guide claim with no test; (b) no; (c) no. **Confidence:** certain.

### R11 — delayed retry logs one info entry per retry and no error while retrying

- **No longer proved:** old `ErrorHandlerTest.php:52`, `:64`, `:65`; the log is emitted at
  `packages/Ecotone/src/Messaging/Handler/Recoverability/DelayedRetryErrorHandler.php:102`. The dead-letter log line is
  covered (`DelayedRetryBackOffTest::test_dead_letter_log_counts_failed_deliveries_as_initial_delivery_plus_retries`).
- **Classification E.** Deleted in `af6dd366d`. **Covered now:** no. **Confidence:** certain. Low impact (log
  content, which §15 shows applications alert on).

### R12 — splitter: header and correlation propagation, sequence headers, runtime refusal

- **No longer proved** (old `RequestReplyProducerTest.php`, deleted in `f459f27fd`):
  (a) `:212-224`, `:326-332` — each split message carries the request's custom headers, reply channel and a shared
  correlation id; (b) `:248-254` — `SEQUENCE_SIZE`/`SEQUENCE_NUMBER` and the request's correlation id on messages the
  splitter service built itself; (c) `:263-273` — a `#[Splitter]` whose result is not iterable at runtime throws.
  The bootstrap check catches only a declared non-iterable return type; a method declared `array|string` passes it and
  reaches the runtime throw at `packages/Ecotone/src/Messaging/Handler/Splitter/SplitterHandler.php:40`.
- **Classification E.** `SplitterAttributeTest` covers payload splitting, sequence headers on framework-built
  messages and ids; its `token` assertion in `test_splitting_when_service_already_returns_built_messages` is vacuous.
- **Covered now:** no for (a)–(c). **Confidence:** certain.

### R13 — a subclass of a `#[NamedEvent]` class does not inherit its name

- **No longer proved:** old `BusRoutingMapTest.php:57-70` (deleted `e29ee123e`): routing by the parent's `#[NamedEvent]`
  alias does not reach a subclass. `BusRoutingTest` covers parent-class and interface routing, `MessageBusTest` the
  alias itself. **Classification E.** **Covered now:** no. **Confidence:** likely.

### R14 — `#[ConfigurationVariable]`: by parameter name, and `null` when missing

- **No longer proved** (old `ConfigurationVariableBuilderTest.php`, deleted `c6eec6112`): `:44-63` — a bare
  `#[ConfigurationVariable]` resolves the variable named like the parameter; `:65-83` — a nullable parameter receives
  `null` when the variable is missing. `ParameterConverterAttributesTest` covers the explicit name, the default value
  and the required-missing failure. **Classification E.** **Covered now:** no. **Confidence:** certain.

### R15 — a required, missing `#[ConfigurationVariable]` now fails on the first message, not at build

- **What changed:** the deleted `test_throwing_exception_if_missing_configuration_variable`
  (`ConfigurationVariableBuilderTest.php:108-126` at base) expected `InvalidArgumentException` from `->build()`; its
  replacement `ParameterConverterAttributesTest::test_configuration_variable_converter_throws_when_required_variable_is_missing`
  (`:163-173`) bootstraps successfully and expects the exception from `sendCommandWithRouting()`. No test pins the
  message.
- **Classification D, unsure.** No guide entry (grep `ConfigurationVariable` in `upgrade-2.0.md`: no behaviour entry).
  It conflicts with rule 1a (a configuration mistake refused at bootstrap). **Unsure** because the old proof used the
  internal `ComponentTestBuilder`, whose `build()` may have resolved the converter eagerly where a 1.x application
  bootstrap did not; whether a 1.x application failed at boot was not reproduced. Commit `c6eec6112` changed the
  test's expectation without saying so.

### R16 — header conversions the builder tests proved

- **No longer proved** (deleted `c6eec6112`): `HeaderBuilderTest.php:89-123` — an array header converts to the
  declared class through `application/x-php`, chosen over the JSON fallback; `HeaderExpressionBuilderTest.php:101-116` —
  a nullable `#[Header(name, expression)]` receives `null`, not an exception, when the header is missing;
  `HeaderExpressionBuilderTest.php:59-76` — `reference('svc')` inside a header expression (low value: the payload twin
  is covered); implicit only: `HeaderBuilderTest.php:152-168` — a non-null default is used when the header is missing
  (`ParameterConverterAttributesTest`'s optional case uses `= null`, so it cannot tell the two apart).
- **Classification E.** **Covered now:** no (the last implicitly, through fixtures). **Confidence:** certain for the
  first two, likely for the rest.

### R17 — method-argument conversion edge cases, one with a vacuous replacement

- **No longer proved** (old `MethodInvokerTest.php`, deleted `fdce0fabd`): `:76-98` a handler returning a `Message`
  keeps that message's content type; `:321-351` a `stdClass|array` parameter resolved through `__TypeId__` from JSON;
  `:353-383` an `object` parameter resolved through `__TypeId__`; `:385-416` a union parameter without `__TypeId__`
  throws (and that message names a `__TYPE__` header, while the header is `__TypeId__` — a rule-1 defect in the
  message); `:418-439` a header converted into a union-typed parameter; `:500-516` a `@param Uuid[]` payload drives
  element conversion.
- **The last has a replacement that proves nothing:** `MethodArgumentResolutionTest::test_converts_a_collection_of_identifiers_into_a_collection_of_confirmations`
  calls a handler `buyMultiple(array $value)` with no docblock that calls `Uuid::fromString` itself (HEAD
  `MethodArgumentResolutionTest.php:272-275`), so the framework converts nothing. Also mislabelled:
  `test_two_unannotated_parameters_default_to_payload_and_headers` declares `#[Headers]` explicitly (the real default
  is covered by `ModellingBehatMigrationTest::test_metadata_propagation`).
- **Classification E.** The commit said the `__TypeId__` cases test "stubbed behaviour"; they are reachable with real
  converters. **Covered now:** no. **Confidence:** likely (certain for the vacuous test).

### R18 — projecting one aggregate instance's stream is removed, but only implicitly

- `SpecificEventStreamTest` (deleted `88d482323`) proved `#[FromStream('basket_stream-1000')]` sees one instance under
  the stream-per-aggregate layout. §4 removes that layout (`:181-183`, `:195-197`, `:220-225`) and §3 points
  `fromCategories` at `#[Partitioned]` (`:111-112`), but no line says "a projection over a single aggregate instance's
  stream has no replacement; use `#[Partitioned]`". Neither does `88d482323`'s message.
- **Classification GUIDE** (B by implication). **Confidence:** likely.

### R19 — the merge that brought main's #701 fix dropped its test silently

- `GapDetectionInSynchronousProjectionTest::test_retry_delays_are_waited_in_milliseconds_before_skipping_gap` existed
  only on main (`343824b51`); merge `33bb663d5` resolved the modify/delete conflict as a delete. The ledger's *Main
  fixes whose code 2.0 deleted* row for #704 records why the defect cannot occur, so the loss is justified; the merge
  commit does not say it dropped a test. Not D/E — recorded so the next main merge does not re-ask.
  **Confidence:** certain.

---

## W3 — public behaviour changed without the guide saying so

**Method.** (1) Every `#[Attribute]` class at base (125) mapped to HEAD through the CSV; constructor, targets and
defaults diffed, including the internal parents that carry constructor parameters (sub-review). (2) A mechanical diff
of normalized public method signatures for every CSV pair and every `Api/` class (55 classes differ, 152 removed or
changed signatures), each grepped against `upgrade-2.0.md`. (3) Framework configuration keys: Symfony
`DependencyInjection/Configuration.php`, Laravel `config/ecotone.php` and the keys `EcotoneProvider` reads.
(4) Names referenced by 1.x documentation (`#[…]`, `use Ecotone\…`, `Class::member`) checked against HEAD.
(5) Gateways, `EcotoneLite`/`FlowTestSupport`, configuration classes, defaults and console commands — sub-review, see
below.

**Documented and checked (A):** every signature change in the mechanical diff not listed below is in the guide —
connection-reference defaults (§5), `DbalConfiguration` table-initialization removals (§8 `:1205-1228`),
`EventSourcingConfiguration` strategy/projection/write-lock removals (§3, §4, §8), `EventStore` (§4), `DocumentStore`
expected versions (§14), `FlowTestSupport`/`MessagingTestSupport` `pop*`, routing and time renames (§15 `:2596-2664`),
`DeadLetterGateway::replay*` (§15), `RetryTemplateBuilder` and `#[DelayedRetry]` renames (§15 `:2615-2627`),
`ExecutionPollingMetadata`/`PollingMetadata` parameter renames (§15 `:2711-2727`), `SimpleMessageChannelBuilder`
delayable default and JMS defaults (§9), `ServiceConfiguration` module-package methods (§1), `MessageHeaders` statics
and `ModulePackageList::allPackages()`/`getModuleClassesForPackage()` (§13c `:1706-1720`), `MediaType::getTypeParameter()`
(§13a), `MethodInvocation` (§7), `DistributedServiceMap` (§6), `InternalHandler`/`ServiceActivator` (§7a), `#[Stream]`
(§4), `WithEvents::popRecordedEvents()` (§15). Every 1.x attribute named in 1.x documentation and present in 1.x code
exists at HEAD, except `#[ServiceActivator]` (§7a) and `#[ProjectionV2]` (§3).

### R3 — `ModulePackageList::allPackagesExcept()` and `ASYNCHRONOUS_PACKAGE` are deleted; the guide does not say so

- **What an application can no longer do:** call `ModulePackageList::allPackagesExcept([...])` or name
  `ModulePackageList::ASYNCHRONOUS_PACKAGE` — both raise PHP errors (undefined method / undefined constant). HEAD
  `packages/Ecotone/Api/ExtensionObject/ModulePackageList.php:12-25` has neither; base
  `packages/Ecotone/src/Messaging/Config/ModulePackageList.php:16`, `:76`.
- **Classification GUIDE** (A in substance: §1 replaces `withSkippedModulePackageNames()` with `withModulePackages()`,
  which makes the call unnecessary). But §1 says only "Remove `ModulePackageList::ASYNCHRONOUS_PACKAGE` from any skip
  list" (`:51`), not that the constant is gone — §1's own example builds a *load* list, where an upgrader would
  naturally keep it — and `allPackagesExcept` appears nowhere in the guide while §13c's table names its two siblings.
  1.x documentation uses `withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([...]))` on 7 pages
  (`modelling/testing-support/testing-asynchronous-messaging.md:145`, `modelling/business-workflows/orchestrators.md:631,660`,
  `connecting-handlers-with-channels.md:505`, three contributor pages).
- **Commits:** `f035855fb` (asynchronous into core), `b6304bb50`. **Confidence:** certain.

### R4 — gateway container ids moved in §13 and §13b; the guide says no id moved before §13e

- **What an application can no longer do:** reach `CommandBus`, `EventBus`, `QueryBus`, `DistributedBus`, the
  `EventStore` gateway (and any other gateway §13 moved) by its 1.x FQCN as a **string** service id — a Symfony
  `services.yaml` `@Ecotone\Modelling\CommandBus`, a Laravel `app('Ecotone\EventSourcing\EventStore')`, an
  `EventSourcingConfiguration::create(eventStoreReferenceName: 'Ecotone\EventSourcing\EventStore')` or a
  `#[FromStream(eventStoreReferenceName: …)]` with the old string. Gateways are registered under their interface's
  `::class`: `MessagingSystemContainer.php:62` (`$this->container->get(CommandBus::class)`), and the event store under
  `EventSourcingConfiguration`'s `eventStoreReferenceName`, default `EventStore::class`
  (`packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:24`, registered at
  `packages/PdoEventSourcing/src/Config/EventSourcingModule.php:522`). `FromStream`/`FromAggregateStream` default to
  the same `EventStore::class` (`packages/Ecotone/Api/Projecting/FromStream.php:22`, `FromAggregateStream.php:33`).
  Code that imports the new class and uses `::class` is unaffected.
- **Classification GUIDE**, and the guide is wrong: `upgrade-2.0.md:1611-1612` ("The event store's container id is
  unchanged: `EventStore::RAW_REFERENCE` …" — true of the raw reference only) and `:1848` ("§13a to §13d moved no
  container service, so no id moved with them") — §13's own bus moves and §13b's `EventStore` did. The guide documents
  exactly this pattern once, for Kafka's `brokerConfigurationReference` (`:1507-1512`), and for §13e's two ids.
- **Commits:** `4dc897d6f` and siblings (buses), `ff66106b3` (`EventStore`). **Confidence:** certain on the code;
  impact limited to string ids.

### R20 — `#[ProjectionStateGateway]`: named parameter and getter renamed, default names an internal type

- **What an application can no longer do:** pass `projectioManagerReference:` (1.x's spelling) — now "Unknown named
  parameter"; call `getProjectioManagerReference()`. Base `packages/PdoEventSourcing/src/Attribute/ProjectionStateGateway.php:14`,
  HEAD `packages/Ecotone/Api/Projecting/ProjectionStateGateway.php:16`. The default moved from the v1
  `ProjectionManager::class` to `ProjectingManager::class`, a type §3 calls internal, and nothing in `src` or `Api`
  reads the parameter at HEAD — so a value passed positionally is **silently ignored**, where 1.x routed the gateway
  through it (base `packages/PdoEventSourcing/src/Config/EventSourcingModule.php:145`,
  `$attribute->getProjectioManagerReference()`).
- **Classification D** (low impact). Not A: grep `projectioManager`, `projectionManagerReference` in `upgrade-2.0.md`
  finds nothing; the only `ProjectionStateGateway` hits are the usage example (`:125-131`) and the namespace (`:1482`).
  The commit records it (`0d7f0f704`: "fixes the projectioManagerReference typo"), so B by the brief's letter for the
  rename — but the default naming an internal type is recorded nowhere. **Confidence:** certain.

### R21 — `CommandHandler`/`EventHandler::getIdentifierMapping()` renamed; `#[Stream]` became `final`

- **What an application can no longer do:** call `getIdentifierMapping()` on a `CommandHandler`/`EventHandler`
  attribute instance (now `getAggregateIdentifierMapping()`; base `packages/Ecotone/src/Modelling/Attribute/CommandHandler.php:45`,
  `EventHandler.php:66`; HEAD `packages/Ecotone/Api/Attribute/CommandHandler.php:45`, `EventHandler.php:66`) — only a
  custom module reads attributes; extend `#[Stream]` (HEAD `packages/PdoEventSourcing/Api/Stream.php:17`, `final` added
  in `ba468c62f`, whose subject claims new files only).
- **Classification D** (low impact). grep `getIdentifierMapping`, `getAggregateIdentifierMapping`, `final` near
  `Stream` in the guide: nothing. Rename in `e85ef8592`, unmentioned by its message. **Confidence:** certain.

### R22 — `withDocumentStore(enableDocumentStoreStandardRepository:)` was renamed; the guide names only the new spelling

- **What an application can no longer do:** pass `enableDocumentStoreStandardRepository:` to
  `DbalConfiguration::withDocumentStore()` — "Unknown named parameter". Base signature
  (`packages/Dbal/src/Configuration/DbalConfiguration.php:254`): `…, bool $initializeDatabaseTable = true, bool
  $enableDocumentStoreStandardRepository = false, …`; HEAD: `…, bool $enableDocumentStoreStateStoredRepository =
  false, …`. `isEnableDocumentStoreStandardRepository()` is likewise renamed.
- **Classification GUIDE.** §8 (`:1224-1228`) documents the removed `initializeDatabaseTable` and its positional shift,
  naming the parameter by its *new* name only; the rename itself is in no entry (grep
  `enableDocumentStoreStandardRepository`: 0 hits). Side note, not a 2.0 defect: 1.x documentation spells it
  `enableDocumentStoreAggregateRepository` (`modules/dbal-support.md:114,141`), a name 1.x code never had.
- **Commit:** `e85ef8592`. **Confidence:** certain.

### R23 — two small guide inaccuracies about 1.x

- `upgrade-2.0.md:1104` says `#[InternalHandler]` was "introduced earlier in 2.0"; it existed at the 1.x tip
  (`final class InternalHandler extends ServiceActivator`, constructor `inputChannelName, outputChannelName,
  endpointId, requiredInterceptorNames, changingHeaders`). §7a's warning stays correct for `ServiceActivator` users.
- `:2572` says `#[DelayedRetry(initialDelayInMilliseconds: 0)]` "used to throw `Initial delay must be greater than 0`";
  in 1.x the parameter was `initialDelayMs` and the message `DelayedRetry initialDelayMs must be greater than 0`.
- **Classification GUIDE.** **Confidence:** certain.

### R24 — Laravel silently ignores a `skippedModulePackageNames` key left in `config/ecotone.php`

- **What changed silently:** §1 (`:55-57`) says the key "is renamed to `modulePackages`". Symfony refuses the old key
  (the tree builder no longer declares it, `packages/Symfony/DependencyInjection/Configuration.php:81`), so a Symfony
  application is told. Laravel's `EcotoneProvider` reads only `ecotone.modulePackages` at HEAD (it read
  `ecotone.skippedModulePackageNames` at base), so a published 1.x config keeps its old key and **every installed
  package loads**, including the ones the application skipped — no error.
- **Classification GUIDE** (A for the rename; the Laravel failure mode is rule-1a's "read as an empty result" and the
  guide does not warn). **Covered now:** no test refuses or reports the old key. **Confidence:** certain.

### R25 — `EventSourcingConfiguration::withEventStreamTableName()` kept its name and now names a different table

- **What changed silently:** in 1.x it named Prooph's stream **catalogue** (default `event_streams`,
  `LazyProophEventStore::DEFAULT_STREAM_TABLE`, base `packages/PdoEventSourcing/src/Prooph/LazyProophEventStore.php:54`,
  passed as the catalogue at `:261`; property at base `EventSourcingConfiguration.php:26`). In 2.0 it names the
  **default event stream table** that stores every aggregate's events (default `ecotone_event_stream`, HEAD
  `packages/PdoEventSourcing/Api/EventSourcingConfiguration.php:19`, `:62`; registered as the default stream's table at
  `packages/PdoEventSourcing/src/Config/EventSourcingModule.php:223`). A 1.x application that renamed its catalogue
  (`withEventStreamTableName('my_event_streams')`) now has its default stream pointed at that table: setup skips it as
  existing (`CREATE TABLE IF NOT EXISTS`) and appends hit a catalogue-shaped table.
- **Classification D.** Not A: grep `withEventStreamTableName`, `EventStreamTableName` in `upgrade-2.0.md` — 0 hits;
  `event_streams` appears only to say the catalogue is gone (`:181`, `:187`, `:219`). Not in the CSV (same FQCN path
  moved, same method). The 2.0 documentation uses the new meaning (`docs-2-0`
  `…/persistence-strategy/persistence-strategies.md:103`); 1.x documentation never named the method.
- **Commit:** `cc7520f26`. **Covered now:** no test of either meaning. **Confidence:** certain on the code, likely on
  the impact (only applications that customised the catalogue name).

### R26 — `FlowTestSupport::withEvents()` appends to a different stream

- **What changed:** 1.x appended to the stream `'default'` (base `packages/Ecotone/src/Lite/Test/FlowTestSupport.php:180`);
  2.0 appends to `ecotone_event_stream` (`AggregateDefinitionResolver::DEFAULT_STREAM`, HEAD
  `packages/Ecotone/Api/Lite/Test/FlowTestSupport.php:183`). A 1.x flow test that seeded events with `withEvents()` and
  read or projected the stream `'default'` sees nothing.
- **Classification D** (test surface). grep `withEvents(` in `upgrade-2.0.md`: 0 hits. **Commit:** `78092cfcd`.
- **Covered now:** the new behaviour, by
  `packages/Ecotone/tests/EventSourcing/Tagging/FlowTestSupportDefaultStreamTest::test_with_events_writes_to_the_ecotone_event_stream_by_default`.
  **Confidence:** certain.

### R27 — `ExecutionPollingMetadata::withTestingSetup()` changed its default from 1 to 100; `PollingMetadata`'s did not

- **What changed:** §9 documents `createWithTestingSetup()` going from 1 to 100 handled messages (`:1393`). The
  instance method `withTestingSetup()` changed the same way (base `packages/Ecotone/src/Messaging/Endpoint/ExecutionPollingMetadata.php:81`,
  HEAD `packages/Ecotone/Api/ExtensionObject/ExecutionPollingMetadata.php:79`), undocumented — while
  `PollingMetadata::withTestingSetup()` still defaults to 1 (HEAD `packages/Ecotone/Api/ExtensionObject/PollingMetadata.php:74`),
  so the two same-named methods now disagree.
- **Classification D** (a changed default nothing records). §15 `:2723` names `withTestingSetup` for the parameter
  renames only. **Commit:** `73841e54f`. **Covered now:** called by many Amqp tests, asserted by none.
  **Confidence:** certain.

### R28 — `FlowTestSupport::resetProjection()` now replays for a 1.x `#[ProjectionV2]`

- **What changed:** for a v2 projection, 1.x `resetProjection()` deleted and re-initialised it, leaving it empty
  (base `packages/Ecotone/src/Lite/Test/FlowTestSupport.php:342-354`); 2.0 calls `executeAllWithReset()`, which
  deletes, initialises and **replays** every partition (HEAD `packages/Ecotone/Api/Lite/Test/FlowTestSupport.php:332-337`).
- **Classification GUIDE.** §3 (`:166-174`) says the helpers are now synchronous and implies a reset replays ("use
  `resetProjection()` for that"), but writes for a v1 reader; a `#[ProjectionV2]` user, whose reset did not replay, is
  not told. **Commit:** `ac8f21f1d`. **Covered now:**
  `ProjectingTest::test_reset_projection_replays_previously_processed_events_from_scratch`. **Confidence:** certain.

### R29 — §8's "Before" lines describe early 2.0, not 1.x

- `:1213` calls `withAutomaticTableInitialization()` "a deprecated alias" of `AutoCreateLevel`; at the 1.x tip it was
  a plain, undeprecated method (base `packages/Dbal/src/Configuration/DbalConfiguration.php:378-390`). `:1220` calls
  `withInitializeEventStoreOnStart()` "already deprecated"; it was not (base `EventSourcingConfiguration.php:147`).
  `AutoCreateLevel` (`:1205`) and `FlowTestSupport::initializeDatabase()` (described as renamed, `:1236`) never
  existed in 1.x, and `:1207`'s "`None` was the default outside test bootstraps" is early-2.0 — in 1.x tables were
  created on first use by default.
- **Classification GUIDE**: the code is right, the guide addresses a reader of an unreleased build. A 1.x reader can
  still act on the "How to adapt" lines, which are correct. **Confidence:** certain.

### R30 — two more guide statements that are wrong for a 1.x reader

- `:85-88` (§3 *Before*) lists `FlowTestSupport::initializeProjection()`, `resetProjection()`, `stopProjection()`,
  `deleteProjection()` and `triggerProjection()` with the v1 surface that "is gone entirely"; only `stopProjection()`
  went, and `:166-174` and the code keep the other four.
- `:1011` (§5) says `SymfonyConnectionReference`, `LaravelConnectionReference` and `TempestConnectionReference`
  "already resolved to `DbalConnectionReference::DEFAULT`"; in 1.x they resolved to `Enqueue\Dbal\DbalConnectionFactory::class`
  (base `LaravelConnectionReference.php:31`, `SymfonyConnectionReference.php:62`) and `DbalConnectionReference` did not
  exist. The resolved id did change (to `Ecotone\Dbal\Connection\DbalConnectionFactory`), which the §5 table covers.
- **Classification GUIDE.** **Confidence:** certain for the first, likely for the second.

### R31 — small undocumented signature changes

- `DbalDeadLetterBuilder` (`Api`): `createList`, `createShow`, `createCount`, `createDelete`, `createDeleteAll`,
  `createReplay`, `createReplayAll` lost their `$connectionReferenceName` parameter, and `createStore($connectionReferenceName)`
  became `createStore($referenceName, $inputChannelName)` (`3ded6db71`); §15 `:2660` names only the reply→replay
  rename. Applications use the builder's constants and `getChannelName()`, per the ledger (`:64`), so plumbing.
- `Duration::minutes(seconds:)` → `minutes(minutes:)` — a named-argument rename that fixes a wrong name
  (HEAD `packages/Ecotone/Api/Scheduling/Duration.php:32`).
- `EventSourcedRepository::findBy()`: `$fromAggregateVersion` → `$fromVersion` (named-argument break; the interface
  stays in `src`, the ledger records why, `:67`).
- **Classification D**, each low impact. grep of each old name in `upgrade-2.0.md`: 0 hits. **Confidence:** certain.


## Addendum, wave 20 — found by running every quickstart example

Wave 20 made the quickstart examples run on 2.0 and ran each one; these three surfaced there. Numbering continues from
R31 and the totals above are wave 16's.

### R32 — a document-stored aggregate or saga using `WithEvents` cannot be loaded back

- **What broke:** `WithEvents` declares `private ?array $recordedEvents = null` (HEAD
  `packages/Ecotone/Api/Modelling/WithEvents.php:12`, unchanged from 1.x). 2.0 turned JMS null serialization on by
  default (`upgrade-2.0.md:1390`; applied at `packages/JmsConverter/src/JMSConverter.php:34-38`), so the document store
  now writes `"recordedEvents":null`, and on the next load JMS reaches its type check for that key and throws
  `JMS\Serializer\Exception\RuntimeException: You must define a type for …::$recordedEvents`. Every state-stored
  aggregate or saga kept in the DBAL document store that uses `WithEvents` fails on its second load. With
  `JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false)` it loads again: confirmed
  both ways on a clean database with `quickstart-examples/BuildingBlocks`.
- **Seen in:** `BuildingBlocks` (`OrderSaga`), `Testing` (`VerificationProcess`), `Workflows/Saga` (`OrderProcess`),
  `MicroservicesAdvanced` (`Issue`) — each example's `run_example.php` stops there, and all their other 2.0 fixes are in.
- **Classification D.** The default change is documented (§9); its consequence for `WithEvents`, which 2.0 itself
  ships, is not: grep `recordedEvents`, `WithEvents` in `upgrade-2.0.md` finds the trait only as a rename. **Covered
  now:** no test loads a `WithEvents` aggregate back from the DBAL document store. **Confidence:** certain.

### R33 — `EventSourcingConfiguration::createInMemory()` gives a projection no state storage outside flow tests

- **What broke:** with the in-memory event store, `EventSourcingProjectingModule` registers no stream source and no
  projection state storage (`packages/PdoEventSourcing/src/Config/EventSourcingProjectingModule.php:294`, `:428`);
  only the test-support module supplies the in-memory ones
  (`packages/Ecotone/src/Lite/Test/Configuration/EcotoneTestSupportModule.php:253`). So `EcotoneLite::bootstrap()` with
  `createInMemory()` and a `#[Projection]` boots, then the first event dies with `RuntimeException: No projection state
  storage found for projection: …` (`packages/Ecotone/src/Projecting/ProjectionStateStorageRegistry.php:34`) — a
  runtime failure with no way out named, against rules 1 and 1a.
- **Seen in:** `quickstart-examples/EventSourcing`, which on 1.x used exactly this (`main:quickstart-examples/EventSourcing/src/Infrastructure/EcotoneConfiguration.php:14`)
  and ran. Wave 20 moved the example to the DBAL event store it already had a connection for, so it runs; the defect
  stays.
- **Classification D.** grep `createInMemory` in `upgrade-2.0.md`: no entry saying it is test-only now. **Covered now:**
  no test bootstraps it outside flow testing. **Confidence:** certain.

### R34 — a multi-tenant application has no way to create Ecotone's tables in its tenant databases

- **What broke:** 1.x created tables lazily, on the first message that needed one, so a multi-tenant application got
  them in each tenant's database. 2.0 creates none on the message path (conventions rule 16), and leaves it to
  `ecotone:migration:database:setup`, which cannot open a tenant connection: run in a multi-tenant application it stops
  at ``Lack of context about tenant in Message Headers. Please add `tenant` header metadata to your message.``
  (`packages/Dbal/src/MultiTenant/HeaderBasedMultiTenantConnectionFactory.php:105`), and with
  `--connection=tenant_a_connection` at `No database setup manager registered for connection 'tenant_a_connection'.
  Known connections: Ecotone\Dbal\Connection\DbalConnectionFactory`
  (`packages/Dbal/Api/ExtensionObject/DatabaseSetupManagerRegistry.php:34`).
- **The documented escape cannot be built.** `upgrade-2.0.md:1356-1363` calls multi-tenant connections out of scope
  and tells the application to provision each tenant itself, for example with
  `DatabaseSetupManager::getCreateSqlStatementsForFeatures()` against a connection factory it constructs. But
  `DatabaseSetupManager` (`packages/Dbal/Api/ExtensionObject/DatabaseSetupManager.php:21`, in `Api`) takes
  `DbalTableManager[]` in its public constructor (`:26-28`), and `DbalTableManager` is
  `Ecotone\Dbal\Database\DbalTableManager`, in `src`: a rule-12a leak, a public `Api` constructor that requires an
  internal type — a new instance of the open ledger row on the `Api` inward surface.
- **The refusal points at a locked door.** The missing-table `ConfigurationException` these applications hit says
  `Run: bin/console ecotone:migration:database:setup --initialize --feature=event_stream` and offers `--sql` as the
  alternative (`packages/Dbal/src/Database/MissingTableInstructions.php:28-29`). Run in
  `quickstart-examples/MultiTenant/Symfony/EventSourcing`, both forms stop at `Lack of context about tenant in Message
  Headers`. The message names a way out that does not exist — a rule-1 failure in its own right, and not one to fix by
  weakening the message, since there is no correct instruction to give yet.
- **The capability exists, unreachable:** `DbalDatabaseTablesSetUp` already iterates
  `databaseConnectionsOfEveryTenant()` (`packages/Dbal/src/Database/DbalDatabaseTablesSetUp.php:76`), and its only
  wiring is the flow-test bootstrap (`packages/Dbal/src/Database/DatabaseSetupModule.php:87-88`, `:131`).
- **Seen in:** `MultiTenant/Laravel/AsynchronousEvents` (`ecotone_deduplication`), `MultiTenant/Laravel/EventSourcing`
  and `MultiTenant/Symfony/EventSourcing` (`ecotone_event_stream`), `MultiTenant/Symfony/AsynchronousEvents`
  (`enqueue`), `MultiTenant/General/HookIntoTenantSwitch` (`ecotone_document_store`).
- **Classification D.** **Covered now:** no test runs the setup command against a multi-tenant connection.
  **Confidence:** certain.

---

## Sub-review notes not raised as findings

- `EcotoneLite::bootstrapFlowTesting()` skips the in-memory event-sourced repository when a `BaseEventSourcingConfiguration`
  extension object is present (HEAD `packages/Ecotone/Api/Lite/EcotoneLite.php:90`, `e85ef8592`), unmentioned by the
  guide; whether an application observes a difference was not established.
- `AmqpConfiguration` and `CustomDeadLetterGateway` stay in `src` although applications construct them — a rule-12a
  question, not a loss.
- `#[DbalTransaction]` / `#[AmqpTransaction]` still open transactions when placed on a handler
  (`packages/Dbal/src/DbalTransaction/DbalTransactionModule.php:52`), stay in `src` with no row, and the guide's §7b
  points only at `DbalConfiguration` and `#[WithoutDatabaseTransaction]`. Unsure whether applications are meant to use
  them.
- `#[Distributed]`'s default `distributionReference` now resolves to `Ecotone\Api\Gateway\DistributedBus`; nothing
  reads it at base or HEAD, so no effect.
- `Monorepo/CrossModuleTests/Tests/Kafka/CombinedChannelForwardingTest` asserts the exception class, not its message
  (rule 1).
