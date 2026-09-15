# Simplifying EcotoneLite testing support

Research report — module loading, defaults, zero-config tests.

Branch `dgafka/ecotone-2-0-work` @ `bcf4efc0`. All `file:line` citations are against that tree.
Public API lives in `Ecotone\Api\*` / `Ecotone\Api\<Package>\*`, physically at `packages/<Pkg>/Api/`
(see `upgrade/namespace-map-2.0.csv`).

---

## 0. Executive summary

The maintainer's complaint — "people need to know which packages to load" — is, in code, one line:

```php
// packages/Ecotone/src/Lite/EcotoneLite.php:248-251
if (! $configuration->areSkippedPackagesDefined()) {
    $configuration = $configuration
        ->withModulePackages($packagesToLoad);   // [] for bootstrapFlowTesting
}
```

`bootstrapFlowTesting()` passes `$packagesToLoad = []` (`EcotoneLite.php:83`), and
`ServiceConfiguration::withModulePackages([])` is *subtractive* — it skips every package except Core
(`packages/Ecotone/Api/ServiceConfiguration.php:238-246`). So **flow testing defaults to Core-only**,
while **production `EcotoneLite::bootstrap()` defaults to every installed package**
(`MessagingSystemConfiguration::addCorePackage`, `packages/Ecotone/src/Messaging/Config/MessagingSystemConfiguration.php:287-300`).

That inversion is the root cause. Everything the user "must know" — which constants exist, which
their scenario needs, that Core+Async are implicit, that DBAL needs a connection, that
`bootstrapFlowTestingWithEventStore` silently no-ops if you also called `withModulePackages([])` —
follows from tests starting from *nothing* instead of from *everything*.

**Recommendation (detail in §4/§5):** invert the default (Option A) — flow tests load every installed
package, with each package's *test profile* auto-applied when its infrastructure is absent — and add
capability-driven channel defaults (Option B) so `#[Asynchronous('orders')]` gets an in-memory queue
channel in flow tests without weakening the "never inline" rule. Ship slice entry points (Option D)
as sugar on top. Do **not** hide: a real DB requirement, a missing licence, a missing handler, or the
fact that async ran via `run()`.

---

## 1. Current state, grounded in code

### 1.1 Bootstrap entry points

| Method | File:line | Packages loaded by default | Repositories added | Test module |
|---|---|---|---|---|
| `EcotoneLite::bootstrap()` | `packages/Ecotone/src/Lite/EcotoneLite.php:41-62` | **all installed** (`addCorePackage($config, false)`) | none | no |
| `EcotoneLite::bootstrapFlowTesting()` | `EcotoneLite.php:73-93` | **Core + Test only** | in-memory state-stored (`:259`) + in-memory event-sourced (`:87-89`) | yes |
| `EcotoneLite::bootstrapFlowTestingWithEventStore()` | `EcotoneLite.php:104-136` | Core + Test + **EventSourcing, Dbal, JmsConverter** (`:116`) | same two | yes |

Full signature of `bootstrapFlowTesting` (`EcotoneLite.php:73-84`):

```php
public static function bootstrapFlowTesting(
    array                    $classesToResolve = [],
    ContainerInterface|array $containerOrAvailableServices = [],
    ?ServiceConfiguration    $configuration = null,
    array                    $configurationVariables = [],
    ?string                  $pathToRootCatalog = null,
    bool                     $allowGatewaysToBeRegisteredInContainer = false,
    bool                     $addInMemoryStateStoredRepository = true,
    bool                     $addInMemoryEventSourcedRepository = true,
    ?TestConfiguration       $testConfiguration = null,
    ?string                  $licenceKey = null
): FlowTestSupport
```

`bootstrapFlowTestingWithEventStore` differs only in: `$modulePackageNames` (`:116`), a
`$runForProductionEventStore` flag replacing `$addInMemoryEventSourcedRepository`, and two
auto-added extension objects — `EventSourcingConfiguration::createInMemory()` (`:124`) and
`DbalConfiguration::createForTesting()` (`:129`).

### 1.2 The module-loading pipeline

```
ServiceConfiguration::withModulePackages([...])          Api/ServiceConfiguration.php:238-246
   skippedModulesPackages = allPackages()+TEST − (given + CORE)
   areSkippedPackagesDefined = true
        ↓
EcotoneLite::prepareForFlowTesting                        Lite/EcotoneLite.php:237-266
   if (!areSkippedPackagesDefined) withModulePackages($packagesToLoad)
        ↓
MessagingSystemConfiguration::addCorePackage              Config/MessagingSystemConfiguration.php:287-300
   force CORE in; force TEST in when $enableTesting, else out
        ↓
ContainerCacheLayout::resolve                             SymfonyContainer/ContainerCacheLayout.php:33-68
   getModuleClassesFor() → class list fed to the annotation finder
        ↓
MessagingSystemConfiguration::getModuleClassesFor         MessagingSystemConfiguration.php:277-285
   array_filter(..., class_exists || interface_exists)      ← absent packages silently dropped
        ↓
AnnotationModuleRetrievingService::findAllModuleConfigurations   Config/Annotation/AnnotationModuleRetrievingService.php:41-47
   keep module unless its getModulePackageName() is in the skip list
```

Two properties matter for every option below:

1. **Skipping is by *package name on the module*, not by class presence.** Each module declares
   `getModulePackageName()`; `AnnotationModuleRetrievingService.php:45` filters on it.
2. **Uninstalled packages cost nothing.** `getModuleClassesFor` already filters by `class_exists`
   (`MessagingSystemConfiguration.php:284`), so "load everything" means "load everything *installed*".

Module inventory per package (`packages/Ecotone/src/Messaging/Config/ModuleClassList.php`):

| Package | Const | Modules | ModuleClassList lines |
|---|---|---|---|
| core | `CORE_PACKAGE` | 44 (incl. `AsynchronousModule`, `ProjectingModule`, `AggregrateModule`, `EventSourcedRepositoryModule`) | `:93-144` |
| amqp | `AMQP_PACKAGE` | 6 | `:146-153` |
| dbal | `DBAL_PACKAGE` | 11 | `:155-167` |
| redis | `REDIS_PACKAGE` | 2 | `:169-172` |
| sqs | `SQS_PACKAGE` | 4 | `:174-179` |
| eventSourcing | `EVENT_SOURCING_PACKAGE` | 2 | `:181-184` |
| jmsConverter | `JMS_CONVERTER_PACKAGE` | 2 | `:186-189` |
| tracing | `TRACING_PACKAGE` | 1 | `:191-193` |
| test | `TEST_PACKAGE` | 1 (`EcotoneTestSupportModule`) | `:195-197` |
| laravel / symfony / tempest | resp. | 1 each | `:199-209` |
| kafka | `KAFKA_PACKAGE` | 1 | `:211-213` |
| dataProtection | `DATA_PROTECTION_PACKAGE` | 1 | `:215-217` |

Note `TEST_PACKAGE` is deliberately **not** in `ModulePackageList::allPackages()`
(`ModulePackageList.php:51-68`); it is merged in ad hoc at
`ServiceConfiguration.php:240` and `MessagingSystemConfiguration.php:280,297`.

### 1.3 What happens when a package is loaded without its infrastructure

This is the make-or-break question for "load everything by default".

| Package | Loaded with no infra configured → | Evidence |
|---|---|---|
| **dbal** | **Breaks.** `DbalConfiguration::createWithDefaults()` turns on transaction-on-command-bus, deduplication, dead letter, object manager and document store (`packages/Dbal/Api/DbalConfiguration.php:14-54`). `DbalTransactionModule::prepare` then registers a `CommandBus`-wide around interceptor holding `new Reference(DbalConnectionFactory::class)` (`packages/Dbal/src/DbalTransaction/DbalTransactionModule.php:52-79`), so *every* `sendCommand()` resolves a connection that does not exist. `DbalConnectionModule` also calls `requireReference()` (`packages/Dbal/src/Configuration/DbalConnectionModule.php:47-58`) — the only `requireReference` call in the tree — but that check is **disabled in test mode** (`ValidateRequiredReferencesPass.php:30-34`, wired at `MessagingSystemConfiguration.php:1163-1167`), so the failure is deferred to runtime instead of bootstrap. Fix = auto-apply `DbalConfiguration::createForTesting()` (`DbalConfiguration.php:65-77`). |
| **kafka** | **Breaks unconditionally without a licence.** `KafkaModule::prepare` throws before any guard: `if (! $messagingConfiguration->isRunningForEnterpriseLicence()) throw LicensingException::create('Kafka module is available only with Ecotone Enterprise licence.')` (`packages/Kafka/src/Configuration/KafkaModule.php:73-76`). |
| **dataProtection** | Safe. `DataProtectionModule::prepare` returns early unless a `DataProtectionConfiguration` extension object is present (`packages/DataProtection/src/Configuration/DataProtectionModule.php:64-70`); the licence check is after that guard. |
| **amqp / redis / sqs** | Safe. No `requireReference`; the modules only act on their own extension objects/attributes. |
| **eventSourcing** | Safe *but changes behaviour*: once loaded, `EcotoneTestSupportModule` stops registering the in-memory `EventStore` unless an `EventSourcingConfiguration` with `isInMemory()` is present (`packages/Ecotone/src/Lite/Test/Configuration/EcotoneTestSupportModule.php:396-441`, and the `getModuleExtensions` mirror at `:181-212`). |
| **jmsConverter** | Safe; changes default serialization (2.0 defaults per `upgrade-2.0.md:244-245`). |
| **tracing** | Safe (`OpenTelemetryModule`). |
| **laravel / symfony / tempest** | Safe; connection modules only translate framework connection references. |

### 1.4 Test-support surface that already exists

`EcotoneTestSupportModule` (`packages/Ecotone/src/Lite/Test/Configuration/EcotoneTestSupportModule.php`)
— package `test` (`:214-217`), so it is loaded only when `$enableTesting = true`:

| Feature | Lines |
|---|---|
| In-memory `ConsumerPositionTracker` (default on) | `:113-124`, config flag `packages/Ecotone/Api/TestConfiguration.php:97-105` |
| In-memory `EventStore` + `InMemoryEventStoreStreamSource` when eventSourcing package is off | `:396-441` |
| `InMemoryProjectionStateStorage` + `InMemoryEventStoreStreamSource` module extensions | `:181-212` |
| `InMemoryConsoleWriter` / `DelegatingConsoleWriter` | `:128-137` |
| `MessageCollectorHandler` + before-interceptors on Command/Event/Query bus (recorded messages) | `:139-140`, `:249-393` |
| `DelayedMessageReleaseHandler` (time travel for delayed messages) | `:141`, `:224-247` |
| `AllowMissingDestination` around-interceptor (opt-in via `TestConfiguration`) | `:143-163` |
| Auto-spy on every handler `outputChannelName`, registered as a **default** pub/sub channel | `:77-106`, `:170-177` |
| Media-type conversion channel interceptor | `:165-168` |

`TestConfiguration` (`packages/Ecotone/Api/TestConfiguration.php`) — six knobs only:
`failOnCommandHandlerNotFound`, `failOnQueryHandlerNotFound`, `pollableChannelMediaTypeConversion`,
`channelToConvertOn`, `spiedChannelNames`, `inMemoryConsumerPositionTracker`
(`:18-31`). Defaults: `new self(true, true, null, '', [], true)` (`:30`).

`InMemoryRepositoryBuilder` (`packages/Ecotone/src/Lite/Test/Configuration/InMemoryRepositoryBuilder.php:22-45`)
— state-stored and event-sourced factories; both wired by default in flow testing
(`EcotoneLite.php:87-89`, `:257-260`).

`FlowTestSupport` (`packages/Ecotone/src/Lite/Test/FlowTestSupport.php`) — 45 public methods:
buses (`:66-103`), `run()` (`:154-160`), event-store setup (`:165-201`), time control (`:203-230`),
aggregate/saga setup and read (`:265-297`, `:450-470`), projections (`:299-372`), recorded
messages (`:374-425`), direct channel access (`:472-508`), console (`:548-566`).

### 1.5 The `#[Asynchronous]` contract in 2.0

`AsynchronousModule` is a **core** module (`AsynchronousModule.php:147-150`). It collects
`#[Asynchronous]` endpoints at `:44-104` and calls `registerAsynchronousEndpoint()` at `:134`.
The hard failure is in `MessagingSystemConfiguration::configureAsynchronousEndpoints`:

```php
// MessagingSystemConfiguration.php:455-459
foreach ($this->asynchronousEndpoints as $targetEndpointId => $asynchronousMessageChannels) {
    $asynchronousMessageChannel = array_shift($asynchronousMessageChannels);
    if (! isset($this->channelBuilders[$asynchronousMessageChannel]) && ! isset($this->defaultChannelBuilders[$asynchronousMessageChannel])) {
        throw ConfigurationException::create("Registered asynchronous endpoint `{$targetEndpointId}`, however channel configuration for `{$asynchronousMessageChannel}` was not provided. ...");
    }
```

Two facts that make Option B cheap:

* the check accepts a **default** channel builder, not only an explicit one (`:457`);
* `configureDefaultMessageChannels()` runs at `:345`, **immediately before**
  `configureAsynchronousEndpoints()` at `:346`, and promotes every `defaultChannelBuilders` entry
  into `channelBuilders` (`:636-640`).

So `registerDefaultChannelFor(SimpleMessageChannelBuilder::createQueueChannel($name))` from the test
module satisfies the constraint while still losing to any user-registered channel of the same name.

Async polling defaults in test mode are already auto-registered
(`AsynchronousModule.php:205-228`): `stopOnError + finishWhenNoMessages` when the channel came from a
`SimpleMessageChannelBuilder` *extension object* (`:211-218`), else `withTestingSetup(100, 100, true)`
(`:221-224`). Note the discriminator is `isInMemoryPollableChannel()` (`:166-175`) which inspects
**extension objects only** — a default channel registered by the test module would land in the second
branch. That is a real implementation detail for §7.

### 1.6 Friction inventory — everything a user must "know" today

| # | Must know | If you don't | Evidence |
|---|---|---|---|
| 1 | Flow tests start from **Core-only**, unlike production which starts from **all installed** | Any non-core feature silently does nothing; e.g. `#[DbalQuery]` gateway is never registered → "Gateway ... has not existing request channel" | `EcotoneLite.php:83` + `:248-251`; `MessagingSystemConfiguration.php:1050` |
| 2 | `withModulePackages()` takes packages to **load**, and is **subtractive** internally | Passing `[DBAL]` silently drops eventSourcing/jms you also needed | `ServiceConfiguration.php:238-246` |
| 3 | Core and Async are implicit; `ASYNCHRONOUS_PACKAGE` no longer exists | `ModulePackageList::getModuleClassesForPackage()` throws "Given unknown package name" | `ModulePackageList.php:44`; `upgrade-2.0.md:48-49` |
| 4 | `withModulePackages([])` means **Core-only**, not "defaults" | Silent feature loss; 196 call sites in this repo do exactly this | `ServiceConfiguration.php:240-242`; grep §2 |
| 5 | Calling `withModulePackages(...)` **cancels** `bootstrapFlowTestingWithEventStore`'s package list | ES/DBAL/JMS never load; the auto-added `EventSourcingConfiguration::createInMemory()` becomes an inert extension object. Live example: `quickstart-examples/Testing/tests/Acceptance/ListingBasketProductsScenarioTest.php:86-98` — it works only because the *test* module's in-memory event store covers for it | `EcotoneLite.php:118` gated by `:248` |
| 6 | An `#[Asynchronous('x')]` handler needs a registered channel `x` | `ConfigurationException` at bootstrap | `MessagingSystemConfiguration.php:457-458`; `upgrade-2.0.md:54-58` |
| 7 | Async handlers never run inline; you must call `->run('x')` | Assertions see nothing happened | `upgrade-2.0.md:23-25` |
| 8 | Async endpoints must declare an explicit `endpointId` | `ConfigurationException: ... should have endpointId defined for handling asynchronously` | `AsynchronousModule.php:71-73`, `:92-95` |
| 9 | `enableAsynchronousProcessing` is gone | Unknown named argument | `upgrade-2.0.md:47` |
| 10 | DBAL tests need a connection reference **and** `DbalConfiguration::createForTesting()` | Runtime container error on every `sendCommand` (transaction interceptor); only 4 call sites in the repo pass `createForTesting()` | `DbalTransactionModule.php:52-79`; `DbalConfiguration.php:14-26,65-77` |
| 11 | The in-memory event store exists and is automatic — but **only while the eventSourcing package is off** | Loading eventSourcing without `EventSourcingConfiguration::createInMemory()` silently switches to Prooph/PDO and fails on connection | `EcotoneTestSupportModule.php:396-431` |
| 12 | In-memory document store is a `DbalConfiguration` flag, not a test concept | You need `withDocumentStore(true, true)` (what `createForTesting()` does) or a real DB | `DbalConfiguration.php:47,76`; `packages/Dbal/src/DocumentStore/DbalDocumentStoreModule.php:65,150-199` |
| 13 | Kafka cannot be loaded without a licence at all | `LicensingException` at bootstrap, even for an unrelated test | `KafkaModule.php:73-76` |
| 14 | Enterprise features need `licenceKey:` — 34 distinct feature gates | `LicensingException`; see §6 for the list | grep of `LicensingException::create` across `packages/*/src` |
| 15 | `pathToRootCatalog:` is needed inside the monorepo / symlinked installs | `RootCatalogNotFound` or wrong class scanning | `EcotoneLite.php:145-155`; quickstarts pass `pathToRootCatalog: __DIR__` |
| 16 | Anonymous classes disable container caching | Slow, but silent | `ContainerCacheLayout.php:64,77-86` |
| 17 | 2.0 default changes: 100 messages per `run()`, delayable queue channels, instant retries on | Off-by-N assertions, delayed messages invisible | `upgrade-2.0.md:240-260`; `ExecutionPollingMetadata.php:33` |

---

## 2. Survey of the monorepo's own usage

Counts via `grep -rn ... packages quickstart-examples --include='*.php'` at `bcf4efc0`.

| Metric | Count |
|---|---|
| `EcotoneLite::bootstrapFlowTesting(` call sites | **907** |
| `EcotoneLite::bootstrapFlowTestingWithEventStore(` call sites | **161** |
| `EcotoneLite::bootstrap(` call sites | 63 |
| Files containing `bootstrapFlowTesting` | **277** |
| Files containing any `EcotoneLite::` | 294 |
| `*Test.php` files under `packages/*/tests` | 412 |
| `withModulePackages` occurrences | **703** |
| `withModulePackages([])` (→ Core-only) | **196** |
| `bootstrapFlowTesting(` sites with `withModulePackages` within 12 following lines | **526** (58%) |
| … with `SimpleMessageChannelBuilder` within 12 lines | **234** (26%) |
| … with `withExtensionObjects` within 12 lines | **420** (46%) |
| … with `licenceKey` within 12 lines | **132** (15%) |
| `LicenceTesting` / `licenceKey:` references | 577 |

Per package:

| Package | `bootstrapFlowTesting` | `…WithEventStore` | `withModulePackages` |
|---|---|---|---|
| Ecotone | 481 | 1 | 192 |
| Dbal | 149 | 0 | 148 |
| PdoEventSourcing | 16 | 150 | 123 |
| Amqp | 88 | 0 | 87 |
| Kafka | 50 | 0 | 51 |
| OpenTelemetry | 26 | 0 | 26 |
| Symfony | 19 | 0 | 20 |
| Laravel | 15 | 0 | 16 |
| Sqs | 13 | 0 | 13 |
| Redis | 9 | 0 | 9 |
| DataProtection | 9 | 0 | 9 |
| JmsConverter | 3 | 2 | 3 |
| quickstart-examples | 37 combined | | |

Most-used `withModulePackages` argument shapes (normalised, whitespace-stripped, top 12):

| Count | Shape |
|---|---|
| 196 | `withModulePackages([])` |
| 96 | `[DBAL_PACKAGE]` |
| 55 | `[AMQP_PACKAGE, …]` |
| 43 | `[DBAL_PACKAGE, …]` |
| 35 | `[DBAL_PACKAGE, EVENT_SOURCING_PACKAGE, …]` |
| 31 | `[KAFKA_PACKAGE]` |
| 30 | `[AMQP_PACKAGE]` |
| 22 | `[EVENT_SOURCING_PACKAGE]` |
| 21 | `[CORE_PACKAGE]` |
| 16 | `[TRACING_PACKAGE]` |
| 9 | `[SQS_PACKAGE]` |
| 8 | `[EVENT_SOURCING_PACKAGE, DBAL_PACKAGE]` / `[DBAL_PACKAGE, JMS_CONVERTER_PACKAGE]` |

Package-constant mentions overall: DBAL 285, EVENT_SOURCING 130, AMQP 104, CORE 92, KAFKA 50,
TRACING 28, JMS 24, TEST 17, SQS 17, REDIS 13, DATA_PROTECTION 12, SYMFONY 11, TEMPEST 9, LARAVEL 8.

**What this says about "defaults that just work":**

* 196/703 `withModulePackages` calls (28%) express *"give me nothing extra"* — under an
  "everything by default" model those become **deletions**, not migrations.
* 21 sites write `[CORE_PACKAGE]`, which is a no-op verbosity (`ServiceConfiguration.php:241`
  appends `CORE_PACKAGE` anyway).
* DBAL is the single most-requested package and is exactly the one that *breaks* when auto-loaded
  without `createForTesting()`. Any "load everything" move must ship the DBAL test profile with it.
* 26% of flow tests register a channel by hand; the overwhelming majority of those are in-memory
  queue channels named after an `#[Asynchronous]` attribute — that is the case Option B removes.
* 15% pass a licence key. Enterprise-gated behaviour is common enough that a "test licence by
  default" idea must be considered and (see §6) rejected.

---

## 3. How other frameworks do it

| Framework | Module/plugin loading in tests | Infra faking | Day-one test code |
|---|---|---|---|
| **Symfony** | `KernelTestCase`/`WebTestCase` boot the same kernel as production; bundles come from `config/bundles.php` with per-env flags, so the test env loads the full bundle set plus `config/packages/test/*` overrides. | `framework.messenger.transports.*: 'in-memory://'` in `config/packages/test/messenger.yaml`; the transport holds messages instead of delivering them (introduced 4.3). | `class FooTest extends KernelTestCase { self::bootKernel(); … }` — no module list. ([symfony.com/doc/messenger](https://symfony.com/doc/4.x/messenger.html), [SymfonyCasts](https://symfonycasts.com/screencast/messenger/test-in-memory), [PR #29097](https://github.com/symfony/symfony/pull/29097/files/8f8c82e009e885848876562598d8f06d163c2e88)) |
| **Symfony + zenstruck/messenger-test** | Trait `InteractsWithMessenger` on the existing kernel test case; no extra registration. | `TestTransport` intercepts everything by default; assertions on queued/acknowledged/rejected; messages are serialized+deserialized as an added check; explicit `->process()` to run them. | `$this->transport('async')->queue()->assertCount(1);` ([github.com/zenstruck/messenger-test](https://github.com/zenstruck/messenger-test/blob/1.x/README.md)) |
| **Laravel** | Package **auto-discovery**: providers/aliases read from installed packages' `composer.json extra.laravel`; nothing to list. | `Queue::fake()`, `Event::fake()`, `Bus::fake()`, `Mail::fake()` swap the container binding for a recording fake; `QUEUE_CONNECTION=sync` in `phpunit.xml`; `RefreshDatabase` + sqlite `:memory:`. | `Queue::fake(); … Queue::assertPushed(Job::class);` ([laravel.com/docs mocking](https://laravel.com/docs/5.7/mocking), [QueueFake API](https://api.laravel.com/docs/11.x/Illuminate/Support/Testing/Fakes/QueueFake.html)) |
| **Spring Boot** | `@SpringBootTest` runs the whole auto-configuration graph; each auto-config is `@ConditionalOnClass`/`@ConditionalOnMissingBean` so *installed* = *configured*. | Test **slices** (`@DataJpaTest`, `@WebMvcTest`) disable global auto-config (`@OverrideAutoConfiguration(enabled=false)`) and re-enable a curated subset; `@DataJpaTest` substitutes an embedded in-memory DB for the real `DataSource` and wraps each test in a rolled-back transaction. | `@DataJpaTest class RepoTest { @Autowired TestEntityManager em; }` ([DataJpaTest API](https://docs.spring.io/spring-boot/api/java/org/springframework/boot/data/jpa/test/autoconfigure/DataJpaTest.html), [reference](https://docs.spring.io/spring-boot/docs/2.1.6.RELEASE/reference/html/boot-features-testing.html)) |
| **Quarkus** | `@QuarkusTest` boots the real app; extensions present on the classpath are active. | **Dev Services**: an unconfigured datasource/broker is auto-provisioned (Testcontainers) in dev+test with credentials wired in — "focus on your code, we'll handle the infrastructure". `@TestProfile` overrides config per test. | `@QuarkusTest class OrderTest { … }` with no DB config at all. ([quarkus.io Dev Services](https://redhat-developer-demos.github.io/quarkus-tutorial/quarkus-tutorial/06_dev-services.html), [TechTarget overview](https://www.techtarget.com/searchapparchitecture/tip/Quarkus-Dev-Services-Zero-config-development)) |
| **Micronaut** | `@MicronautTest` starts the real `ApplicationContext`; nothing is mocked by the annotation; `@Property`/`@Requires` tune it. | Per-test property overrides and bean replacement (`@MockBean`). | `@MicronautTest class T { @Inject Foo f; }` ([micronaut-test guide](https://micronaut-projects.github.io/micronaut-test/latest/guide/)) |
| **Axon Framework** | `AggregateTestFixture<T>` / `SagaTestFixture<T>` — a **fixture DSL**, not a container. All infrastructure (event store, command bus, gateway) is in-memory inside the fixture. | Given/when/expect: `fixture.given(events…).when(command).expectEvents(…)`. | `new AggregateTestFixture<>(GiftCard.class).given(new IssuedEvt(…)).when(new RedeemCmd(…)).expectEvents(new RedeemedEvt(…));` ([docs.axoniq.io testing](https://docs.axoniq.io/axon-framework-reference/4.13/testing/commands-events/), [AggregateTestFixture API](https://apidocs.axoniq.io/4.0/org/axonframework/test/aggregate/AggregateTestFixture.html)) |
| **NestJS** | `Test.createTestingModule({ imports/providers })` — an explicit list, but scoped to the *feature module* you already declared, then `.overrideProvider(X).useValue(mock)` for fakes. | Override-by-token; no global fakes. | `const mod = await Test.createTestingModule({providers:[Svc]}).overrideProvider(Dep).useValue(stub).compile();` ([docs.nestjs.com/fundamentals/testing](https://docs.nestjs.com/fundamentals/testing)) |
| **Django** | `INSTALLED_APPS` is the single app list, shared by prod and tests; the test runner does not change it. | Test runner **auto-swaps** the email backend to `locmem` (`mail.outbox`), creates a throwaway test database, and wraps tests in transactions. | `class T(TestCase): def test(self): … assertEqual(len(mail.outbox), 1)` ([docs.djangoproject.com email](https://docs.djangoproject.com/en/6.1/topics/email/)) |
| **Rails** | Everything in the Gemfile is loaded; no per-test module list. | `ActiveJob::Base.queue_adapter = :test` (jobs enqueued, not run; `perform_enqueued_jobs` to drain), `config.action_mailer.delivery_method = :test` (mails collected in `ActionMailer::Base.deliveries`). | `assert_enqueued_with(job: MyJob) { … }` ([ActiveJob TestAdapter](https://api.rubyonrails.org/classes/ActiveJob/QueueAdapters/TestAdapter.html), [Active Job Basics](https://guides.rubyonrails.org/active_job_basics.html)) |
| **Tempest (PHP)** | `Tempest\Framework\Testing\IntegrationTest` boots the framework "with configuration suitable for testing"; discovery is automatic for non-dev namespaces, with `discoverTestLocations()` to add test-only paths. | Framework-provided testing utilities on the base class. | `final class T extends IntegrationTest { … }` ([tempestphp.com testing](https://tempestphp.com/2.x/essentials/testing)) |
| **EventSauce (PHP)** | No container. `AggregateRootTestCase` requires only `newAggregateRootId()` + `aggregateRootClassName()`. | Ships `InMemoryMessageRepository` and `SynchronousMessageDispatcher`. | `$this->given(...)->when(...)->then(...)` ([eventsauce.io/docs/testing](https://eventsauce.io/docs/testing/)) |

**Patterns worth stealing, ranked by fit for Ecotone**

1. **Installed ⇒ configured** (Spring `@ConditionalOnClass`, Laravel auto-discovery, Rails, Django).
   Nobody makes you list modules in tests. Ecotone already does this in production
   (`addCorePackage` → all installed) — tests are the outlier.
2. **Fakes by default, real infra opt-in** (Django `locmem`, Rails `:test` adapter, Symfony
   `in-memory://`, Spring embedded DB, Laravel `sync`). The *substitution* is the test default; the
   real driver is the explicit choice. Ecotone has all the pieces (`InMemoryEventStore`,
   `InMemoryDocumentStore`, `InMemoryConsumerPositionTracker`, `SimpleMessageChannelBuilder`) but
   wires them per-bootstrap-method rather than as a profile.
3. **Queue faked but not inlined** (Rails `:test` adapter, zenstruck `TestTransport`, Symfony
   `in-memory://`). All three enqueue and require an explicit drain — exactly Ecotone 2.0's
   "never inline" rule. This validates Option B: auto-provisioning an in-memory channel is
   orthogonal to inlining.
4. **Slices / fixtures** (Spring `@DataJpaTest`, Axon `AggregateTestFixture`, EventSauce
   `AggregateRootTestCase`). Narrow entry points that name the thing under test.
5. **Auto-provisioned real infra** (Quarkus Dev Services). Powerful but out of scope: it needs
   Testcontainers and would violate "tests must run without docker".

---

## 4. Options for Ecotone

### The five candidates

**A. Invert the default — flow tests load every installed package, modules degrade gracefully.**
`bootstrapFlowTesting`'s `$packagesToLoad = []` stops meaning "nothing" and starts meaning
"unspecified ⇒ everything installed", matching `EcotoneLite::bootstrap()`. Each package gets a
*test profile* auto-applied when running with `$enableTesting = true` and no explicit configuration
object of its own.

Modules needing a fallback (from §1.3):

| Module | Fallback in test mode |
|---|---|
| `DbalTransactionModule`, `ObjectManagerModule`, `DeduplicationModule`, `DbalDeadLetterModule`, `DbalDocumentStoreModule`, `DatabaseSetupModule` | default `DbalConfiguration` becomes `createForTesting()` when no user `DbalConfiguration` is present **and** no connection reference is resolvable |
| `DbalConnectionModule` | already inert in test mode (`ValidateRequiredReferencesPass.php:30-34`) |
| `EventSourcingModule` / `ProophProjectingModule` | default `EventSourcingConfiguration` becomes `createInMemory()`; keeps `EcotoneTestSupportModule`'s in-memory store consistent (`EcotoneTestSupportModule.php:411-431`) |
| `KafkaModule` | must gain the `DataProtectionModule`-style guard: return early unless a Kafka extension object or `#[KafkaConsumer]` attribute is present, *then* check the licence |
| `AmqpModule`, `Sqs*`, `Redis*` | already inert without their extension objects — no change |

**B. Capability-driven defaults — derive infrastructure from the classes under test.**
The test module already scans the annotation finder (it does this for spied channels,
`EcotoneTestSupportModule.php:77-106`). Extend it:

| Detected | Auto-registered (as a *default*, losable to user config) |
|---|---|
| `#[Asynchronous('x')]` on a non-query handler | `registerDefaultChannelFor(SimpleMessageChannelBuilder::createQueueChannel('x'))` |
| `#[EventSourcingAggregate]` / `#[EventSourcingSaga]` | in-memory event store (already done at `:396-441`) |
| `#[Projection]`/`#[ProjectionV2]` | in-memory `StreamSource` + `ProjectionStateStorage` (already done at `:181-212`) |
| `#[DbalQuery]` / `#[DbalWrite]` / `#[DocumentStore]` | nothing auto — see §4 "must not be hidden" |

Feasible today: the missing-channel guard already accepts `defaultChannelBuilders`
(`MessagingSystemConfiguration.php:457`) and defaults are promoted one step earlier
(`:345` before `:346`). **This does not weaken "never inline"** — the message still goes to a
pollable queue and still requires `->run('x')`.

**C. Named test profiles / presets.**
`TestConfiguration::createWithDefaults()->withInMemoryInfrastructure()`,
`->withEventSourcing()`, `->withDatabase(DbalConnectionReference $ref)`. A curated bundle of
extension objects behind one call. Fixes discoverability but keeps the "know which preset"
problem — it renames the question rather than removing it.

**D. Slice-style entry points / fixture DSL.**
`EcotoneLite::forAggregate(Order::class)`, `forSaga()`, `forProjection()`, `forHandlers([...])`,
each returning `FlowTestSupport` pre-seeded with the right profile; optionally an Axon-style
`->given(...)->when(...)->expectEvents(...)` façade over `withEventsFor`/`sendCommand`/`getRecordedEvents`.
Pure sugar over A+B; zero risk; excellent day-one ergonomics for the 4 canonical shapes.

**E. Environment-driven defaults.**
`ServiceConfiguration::withEnvironment('test')` (`ServiceConfiguration.php:132-138`) selects fakes;
real infra only via extension objects. Attractive because it unifies Lite, Symfony `test` env,
Laravel `phpunit.xml`, and Tempest. Risk: `environment` currently only drives the annotation finder's
`#[Environment]` filtering (`ContainerCacheLayout.php:48`) — overloading it changes production
semantics for anyone who names an env "test".

### Comparison

Scores: ●●● best, ●● acceptable, ● poor.

| Criterion | A. Load-all + graceful | B. Capability-driven | C. Presets | D. Slices/DSL | E. `test` env |
|---|---|---|---|---|---|
| Day-one ergonomics | ●●● nothing to write | ●●● async "just works" | ●● must know preset names | ●●● names the intent | ●●● |
| Explicitness / no magic | ●● packages appear silently | ●● a channel appears silently | ●●● everything named | ●●● | ● env string is invisible magic |
| Prod/test divergence risk | ●● defaults differ from prod's `DbalConfiguration` | ● a test passes where prod fails on a missing channel | ●●● | ●●● | ● biggest divergence surface |
| Bootstrap performance | ●● more module classes scanned; **better cache reuse** (one config hash instead of N) | ●●● negligible | ●●● | ●●● | ●● |
| Compatible with "async never inline" | ●●● untouched | ●●● queue + explicit `run()` preserved | ●●● | ●●● | ●●● |
| Licence gating | ● Kafka throws on load today (`KafkaModule.php:73-76`) — needs a guard | ●●● | ●●● | ●●● | ●● |
| Implementation cost | high: DBAL/ES test profiles + Kafka guard + regression sweep over 1068 call sites | low: ~1 module + 1 config flag | low | low–medium | medium |
| Migration blast radius | 196 deletable `withModulePackages([])`; 526 sites become optional | additive, no breakage | additive | additive | additive |

### Verdict

**Primary: A, with B as a mandatory companion. D as sugar. C absorbed into A. E rejected.**

* **A alone is not enough** — loading DBAL/eventSourcing by default only helps if their test profiles
  come with them, and it does nothing for the missing-async-channel error (friction #6), which is the
  single most-hit 2.0 regression in the corpus (234 of 907 flow tests register a channel by hand).
* **B alone is not enough** — it fixes async but leaves the Core-only surprise (frictions #1, #2, #4, #5)
  intact.
* **C** becomes redundant once A ships: `withInMemoryInfrastructure()` *is* the default. Keep only the
  inverse — an explicit `TestConfiguration::withRealInfrastructure()` escape hatch.
* **D** is cheap and high-value; ship it after A+B land so the slices can be thin.
* **E** is rejected: it couples a user-visible configuration string to framework behaviour, duplicates
  what `$enableTesting` already expresses (`EcotoneLite.php:142` param, threaded to
  `MessagingSystemConfiguration::prepareWithAnnotationFinder`), and would make production behaviour
  depend on a naming convention.

### What must NOT be hidden

| Never auto-default | Why |
|---|---|
| A **missing command/query handler** | `TestConfiguration` already defaults `failOnCommandHandlerNotFound = true` (`TestConfiguration.php:30`). Keep it. Silently swallowing routing mistakes is the classic test-lies failure. |
| A **missing enterprise licence** | `LicensingException` is a product boundary, not a config gap. Never auto-inject `LicenceTesting::VALID_LICENCE` (`packages/Ecotone/src/Test/LicenceTesting.php:12`); 132 call sites pass it deliberately. |
| A **real database connection** | If a test needs `#[DbalQuery]`, deduplication tables, a dead-letter store, or Doctrine ORM repositories, it must say so. Auto-substituting sqlite `:memory:` (supported at `packages/Dbal/src/Connection/DbalConnectionFactory.php:256-283`) would let SQL-dialect bugs through. Recommendation: **fail with a directive message**, not a silent fallback. |
| **Which channel a message went to** | Auto-registering an in-memory channel for `#[Asynchronous('x')]` must still require `->run('x')`, and the test-mode polling metadata must keep `stopOnError = true`. |
| **`endpointId` on async handlers** | `AsynchronousModule.php:71-73` — generating one would break `run()`/polling-metadata addressing and error-channel routing. |
| **Prod-vs-test `DbalConfiguration` divergence** | When the test profile is auto-applied, say so: the deviation (transactions off, deduplication off, dead letter off, in-memory document store) should be recoverable from `FlowTestSupport` for debugging, and documented. |

---

## 5. Proposed public API

### 5.1 Signatures

```php
// packages/Ecotone/src/Lite/EcotoneLite.php
final class EcotoneLite
{
    public static function bootstrapFlowTesting(
        array                    $classesToResolve = [],
        ContainerInterface|array $containerOrAvailableServices = [],
        ?ServiceConfiguration    $configuration = null,
        array                    $configurationVariables = [],
        ?string                  $pathToRootCatalog = null,
        bool                     $allowGatewaysToBeRegisteredInContainer = false,
        bool                     $addInMemoryStateStoredRepository = true,
        bool                     $addInMemoryEventSourcedRepository = true,
        ?TestConfiguration       $testConfiguration = null,
        ?string                  $licenceKey = null,
    ): FlowTestSupport;   // signature UNCHANGED — only the default package set changes

    /** @deprecated 2.0 — bootstrapFlowTesting() now loads eventSourcing/dbal/jmsConverter when installed */
    public static function bootstrapFlowTestingWithEventStore(...): FlowTestSupport;

    // Slice entry points (Option D)
    public static function forAggregate(string $aggregateClass, array $services = [], ?TestConfiguration $t = null, ?string $licenceKey = null): FlowTestSupport;
    public static function forSaga(string $sagaClass, array $services = [], ?TestConfiguration $t = null, ?string $licenceKey = null): FlowTestSupport;
    public static function forProjection(string $projectionClass, array $aggregateClasses = [], array $services = [], ?TestConfiguration $t = null, ?string $licenceKey = null): FlowTestSupport;
    public static function forHandlers(array $classes, array $services = [], ?TestConfiguration $t = null, ?string $licenceKey = null): FlowTestSupport;
}
```

```php
// packages/Ecotone/Api/TestConfiguration.php — new knobs, all defaulting to today's-best-behaviour
public function withAutomaticInMemoryChannels(bool $enabled = true): self;   // default true
public function withRealInfrastructure(): self;                              // opt out of every test profile
public function withPackages(array $packageNames): self;                     // narrow the auto-loaded set (perf escape hatch)
public function isUsingRealInfrastructure(): bool;
```

```php
// packages/Ecotone/Api/ServiceConfiguration.php — clarity, not behaviour
/** @deprecated use TestConfiguration::withPackages() in tests; withModulePackages() stays for production */
public function withModulePackages(array $packagesToLoad): self;   // unchanged
```

The key change is three lines in `EcotoneLite::prepareForFlowTesting`
(currently `EcotoneLite.php:237-266`):

```php
if (! $configuration->areSkippedPackagesDefined()) {
    $configuration = $packagesToLoad === []
        ? $configuration                                   // leave undefined ⇒ addCorePackage() loads all installed
        : $configuration->withModulePackages($packagesToLoad);
}
```

`addCorePackage($config, enableTestPackage: true)` (`MessagingSystemConfiguration.php:287-300`) then
produces `allPackages() + TEST` exactly as production does, because
`$serviceConfiguration->getSkippedModulesPackages()` is still the constructor default
`[TEST_PACKAGE]` (`ServiceConfiguration.php:36`) and `TEST` is in `$requiredModules`.

### 5.2 Before / after — the six common scenarios

**1 — Sync command handler**

```php
// before (works today, unchanged)
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderHandler::class], [new OrderHandler()]);
$ecotone->sendCommand(new PlaceOrder('1'));

// after — identical; add the slice form if you like
$ecotone = EcotoneLite::forHandlers([OrderHandler::class], [new OrderHandler()]);
```

**2 — Async handler with `run()`**

```php
// before
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderHandler::class], [new OrderHandler()],
    ServiceConfiguration::createWithDefaults()
        ->withExtensionObjects([SimpleMessageChannelBuilder::createQueueChannel('orders')])
);
$ecotone->sendCommand(new PlaceOrder('1'));
$ecotone->run('orders');

// after — the channel is auto-provisioned as an in-memory queue; run() is STILL required
$ecotone = EcotoneLite::bootstrapFlowTesting([OrderHandler::class], [new OrderHandler()]);
$ecotone->sendCommand(new PlaceOrder('1'));
$ecotone->run('orders');
```

**3 — Event-sourced aggregate**

```php
// before
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore([Ticket::class]);

// after
$ecotone = EcotoneLite::forAggregate(Ticket::class);
$ecotone->withEventsFor('t-1', Ticket::class, [new TicketWasRegistered('t-1', 'alert')])
        ->sendCommand(new CloseTicket('t-1'));
```

**4 — Saga**

```php
// before
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderProcess::class, PaymentService::class], [new PaymentService()],
    ServiceConfiguration::createWithDefaults()->withModulePackages([])
);

// after
$ecotone = EcotoneLite::forSaga(OrderProcess::class, [new PaymentService()]);
$saga = $ecotone->publishEvent(new OrderWasPlaced('o-1'))->getSaga(OrderProcess::class, 'o-1');
```

**5 — Projection**

```php
// before
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
    [CurrentBasketProjection::class, Basket::class],
    [new CurrentBasketProjection()],
    configuration: ServiceConfiguration::createWithDefaults()->withNamespaces([...]),
    pathToRootCatalog: __DIR__,
);

// after
$ecotone = EcotoneLite::forProjection(CurrentBasketProjection::class, [Basket::class], [new CurrentBasketProjection()]);
$ecotone->withEventsFor($userId, Basket::class, [new ProductWasAddedToBasket(...)])
        ->triggerProjection('current_basket');
```

**6 — DBAL business method / dead letter (must stay explicit)**

```php
// before AND after — a real connection is required, deliberately
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [PersonQueryApi::class],
    [DbalConnectionFactory::class => $this->getConnectionFactory()],
    ServiceConfiguration::createWithDefaults()
        ->withExtensionObjects([DbalConfiguration::createForTesting()]),
);
```

Difference after the change: `withModulePackages([DBAL_PACKAGE])` is no longer needed (DBAL loads
because it is installed), and omitting the connection factory produces a **directive** error —
`"Dbal module requires 'Ecotone\Dbal\Connection\DbalConnectionFactory' to be configured…"`
(the message already exists at `DbalConnectionModule.php:50-56`) instead of today's opaque runtime
container miss. That means re-enabling `ValidateRequiredReferencesPass` in test mode *only* for
references whose owning feature is actually exercised.

### 5.3 Skill-doc changes (`.claude/skills/ecotone-testing/`)

| File | Change |
|---|---|
| `SKILL.md:18-38` | Replace the two-row bootstrap table with one row: `bootstrapFlowTesting()` for everything; mark `bootstrapFlowTestingWithEventStore()` deprecated; add the four slice entry points. |
| `SKILL.md:98-122` | Rewrite "Async-Tested-Synchronously": drop the `SimpleMessageChannelBuilder` boilerplate, keep `->run('notifications')` and state explicitly that async is never inline. |
| `SKILL.md:144-154` | Update the failure table: "Channel not found" row becomes "should not occur in flow tests — if it does, the channel name is dynamic/combined"; add a "Dbal module requires … to be configured" row. |
| `SKILL.md:152` | Delete the `allPackagesExcept()` reference — that helper does not exist in this tree. 3 hits repo-wide, all in skill docs: `.claude/skills/ecotone-testing/SKILL.md:152`, `.claude/skills/ecotone-testing/references/testing-patterns.md:100`, `.claude/skills/ecotone-module-creator/references/module-anatomy.md:66`. All three are stale. |
| `references/usage-examples.md:170-193` | Delete the `ModulePackageList` section; replace with "packages load automatically; use `TestConfiguration::withPackages()` only to trim bootstrap time". |
| `references/testing-patterns.md` | Replace the channel-registration recipe with the `run()`-only recipe. |
| `references/api-reference.md` | Add the four slice signatures and the new `TestConfiguration` methods. |

### 5.4 Migration impact on existing tests

Estimated from the §2 greps (1068 flow-testing call sites across 277 files; the "273 existing Lite
tests" in the brief maps to the 277-file / 294-file counts here).

| Change | Sites | Action | Risk |
|---|---|---|---|
| `withModulePackages([])` | 196 | **Delete** (now a no-op that means the same thing… ) | ⚠️ **Not** a no-op: today it means Core-only, after the change deleting it means all-installed. Behaviour *widens*. Needs a sweep, not a blind delete. |
| `withModulePackages([CORE_PACKAGE])` | 21 | Delete | none |
| `withModulePackages([DBAL_PACKAGE])` and friends | ~309 | Delete once DBAL's test profile is automatic; keep where a real connection is used | medium |
| `bootstrapFlowTestingWithEventStore` | 161 | Mechanical rename to `bootstrapFlowTesting` | low |
| Manual `SimpleMessageChannelBuilder::createQueueChannel` for an `#[Asynchronous]` name | ≤234 | Delete; keep where the channel is DBAL/AMQP/SQS-backed or delayable-specific | low |
| `licenceKey:` | 132 | unchanged | none |
| Kafka tests (50) | 50 | unchanged (they pass a licence) — but the `KafkaModule` guard must land first or every *non*-Kafka test in a workspace with `ecotone/kafka` installed starts throwing | **high — this is the gating item** |

The honest number: **~430 call sites can be simplified, ~50 must be reviewed one-by-one (Kafka +
real-DB DBAL), and 0 are forced to change** if the compatibility shim in §7 step 1 is kept.

---

## 6. Edge cases

| Case | Behaviour today | Under the proposal |
|---|---|---|
| **Enterprise module, no licence** | `KafkaModule::prepare` throws immediately on load (`KafkaModule.php:73-76`). `DataProtectionModule` returns early unless configured (`DataProtectionModule.php:66-68`). 39 `LicensingException::create` sites across `packages/*/src`, of which 5 are licence-key validation itself (`packages/Ecotone/src/Messaging/Config/Licence/LicenceService.php`) and **34 are feature gates**, most of them attribute-triggered (`#[Orchestrator]`, `#[Streaming]`, `#[Polling]`, `#[ErrorChannel]` on gateways, `#[InstantRetry]`, custom `#[StreamSource]`/`#[PartitionProvider]`/`#[StateStorage]`, dynamic channels, distributed bus with service map, high-throughput publishing, batch forwarding). | Kafka must adopt the DataProtection pattern (guard first, licence second). All attribute-triggered gates are already correct — they only fire when the user wrote the attribute — and must keep firing. Never auto-inject a licence. |
| **Two packages provide a channel with the same name** | `registerMessageChannel` throws `"Trying to register message channel with name `x` twice."` (`MessagingSystemConfiguration.php:900-905`). Defaults are separate (`defaultChannelBuilders`, `:915-920`) and last-write-wins by name (`:917`). | Auto-provisioned async channels go through `registerDefaultChannelFor`, so a user's explicit `SimpleMessageChannelBuilder`/`DbalBackedMessageChannelBuilder`/`AmqpBackedMessageChannelBuilder` for the same name always wins (`:627-640`). No new collision class. |
| **Tests that need a real DB** | Must pass a connection factory service + `withModulePackages([DBAL])`; only 4 sites in the whole repo also pass `DbalConfiguration::createForTesting()`. | Package loads automatically; the connection is still the user's job. Detect "user supplied a connection reference" → skip the test profile so transactions/deduplication behave like production. This is the single most important discriminator to get right. |
| **`withModulePackages([])` meaning Core-only** | Documented meaning; 196 uses. | Keep `withModulePackages()` semantics **exactly as-is** (it is a production API too). Introduce the change only in the *absence* of the call. Core-only stays expressible — as `TestConfiguration::withPackages([])`. |
| **Framework kernel tests (Symfony / Laravel / Tempest)** | Do not go through `EcotoneLite`; they read `modulePackages` from `ecotone.yaml` / `config/ecotone.php` (`packages/Laravel/src/EcotoneProvider.php:55-66`) / `EcotoneConfig::$modulePackages` (`packages/Tempest/src/EcotoneConfig.php:21`, applied at `packages/Tempest/src/MessagingSystemInitializer.php:129-130`). All three already default to "null ⇒ everything installed". | Unaffected. Worth noting in docs that Lite-testing defaults now *match* the framework defaults — today they do not, which is a second source of "works in Symfony, fails in EcotoneLite" confusion. |
| **Bootstrap performance with all modules loaded** | `getModuleClassesFor()` feeds the module class list to the annotation finder (`ContainerCacheLayout.php:44-52`), so more packages = more reflection. Offsetting: the config hash (`ContainerCacheLayout.php:53-58`) is derived from the `ServiceConfiguration`, so **today every distinct `withModulePackages` combination is a separate cache entry** — the corpus has at least 25 distinct shapes. A uniform "all packages" set collapses those to one. Monorepo runs never cache at all (`EcotoneLite::shouldUseAutomaticCache` returns false when the root `composer.json` name starts with `ecotone`, `EcotoneLite.php:270-283`), so the monorepo pays the scan cost and gains no cache benefit — user projects get the opposite trade. | Provide `TestConfiguration::withPackages([...])` as the documented performance escape hatch, and keep the monorepo's own suites on explicit narrow lists where they measurably help. This needs a measurement before/after (see §8 step 0). |
| **Anonymous classes** | Disable caching entirely (`ContainerCacheLayout.php:64,77-86`). Very common in this repo's tests. | Unchanged, but it means the "cache collapses to one entry" benefit does not apply to inline-class tests. |
| **Static / global state between tests** | `EcotoneLite::prepareConfiguration` ends with `gc_collect_cycles()` (`EcotoneLite.php:219`); `InMemoryEventStore`, `InMemoryProjectionStateStorage`, `InMemoryConsumerPositionTracker` and `MessageCollectorHandler` are all per-container `Definition`s (`EcotoneTestSupportModule.php:114-139`, `:396-440`), so a fresh bootstrap is a fresh world. `StaticPsrClock` is fetched from the *external* container (`FlowTestSupport.php:232-260`) — if a test case shares one clock instance across tests, time leaks. | No change, but `advanceTimeTo`/`changeTimeTo` refuse to move backwards (`FlowTestSupport.php:207-215`), which turns a leaked clock into a confusing `InvalidArgumentException`. Worth a doc note in the skill. |
| **`#[Asynchronous]` with a `CombinedMessageChannel` name** | Resolved at `AsynchronousModule.php:180-203`; the combined channel's members must each exist. | Auto-provisioning must resolve combined channels the same way, or skip auto-provisioning when the name matches a `CombinedMessageChannel` reference. |
| **Dynamic channels** | `DynamicMessageChannelModule` is enterprise-gated (`DynamicMessageChannelModule.php`). | Do not auto-provision for dynamically resolved names. |

---

## 7. Implementation sketch

Ordered, PR-sized, each independently shippable and testable.

**Step 0 — Measure (no code).**
Bench `bootstrapFlowTesting` with Core-only vs all-installed on a representative fixture, warm and
cold cache, inside the docker app container. Record module count, annotation-finder time, container
compile time. `phpbench.json` exists at the repo root; add a bench case. Gate: if all-installed costs
>25% on a warm bootstrap, land `TestConfiguration::withPackages()` (step 5) *first* and document it.

**Step 1 — Guard `KafkaModule` (blocker for everything else).**
`packages/Kafka/src/Configuration/KafkaModule.php:73-76`: return early unless a Kafka extension
object or a `#[KafkaConsumer]`/Kafka channel builder is present, *then* verify the licence — mirroring
`DataProtectionModule.php:64-70`.
Tests: (a) a flow test with the kafka package loaded and no licence and no Kafka usage boots clean;
(b) a flow test that *does* use Kafka without a licence still throws `LicensingException`;
(c) the existing 50 Kafka tests are unchanged.

**Step 2 — DBAL test profile.**
In every `DBAL_MODULES` `prepare()` that calls
`ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults())`
(`DbalTransactionModule.php:52`, `DeduplicationModule.php:56`, `DbalDeadLetterModule.php:53`,
`ObjectManagerModule.php:46`, `DatabaseSetupModule.php:40-44`, `DbalDocumentStoreModule.php`),
replace the fallback with a single helper that returns `createForTesting()` when the test package is
enabled **and** no user `DbalConfiguration` was supplied **and** no connection reference is present in
the external container.
Tests: flow test with DBAL loaded, no connection → `sendCommand` works, no transaction interceptor;
flow test with a connection factory supplied → production defaults, transaction interceptor active;
existing `packages/Dbal/tests/**` unchanged.

**Step 3 — Event-sourcing test profile.**
`EventSourcingModule` / `ProophProjectingModule`: default `EventSourcingConfiguration` to
`createInMemory()` under `$enableTesting` when the user supplied none. Align
`EcotoneTestSupportModule::registerInMemoryEventStoreIfNeeded` (`:396-441`) and
`getModuleExtensions` (`:181-212`) so the "eventSourcing loaded" and "eventSourcing not loaded"
branches converge on the same in-memory store.
Tests: `bootstrapFlowTesting` with eventSourcing installed reproduces every assertion currently
covered by `bootstrapFlowTestingWithEventStore` in `packages/PdoEventSourcing/tests` (150 sites) —
run that suite unchanged against the new default as the acceptance criterion.

**Step 4 — Auto in-memory channels for `#[Asynchronous]` (Option B).**
In `EcotoneTestSupportModule::create` (`:77-106`), additionally collect async channel names the way
`AsynchronousModule::create` does (`AsynchronousModule.php:44-104`), excluding
`CombinedMessageChannel` references. In `prepare` (`:170-177`), call
`registerDefaultChannelFor(SimpleMessageChannelBuilder::createQueueChannel($name))` for each, gated on
`TestConfiguration::isAutomaticInMemoryChannelsEnabled()`.
Also fix `AsynchronousModule::isInMemoryPollableChannel` (`:166-175`) to recognise default channel
builders, so auto-provisioned channels get `stopOnError + finishWhenNoMessages` polling metadata
rather than `withTestingSetup(100, 100, true)`.
Tests: async handler with no channel config → boots, `sendCommand` enqueues, nothing runs until
`run('orders')`; a user-registered `DbalBackedMessageChannelBuilder('orders')` still wins; a
`CombinedMessageChannel` name is left alone and still errors as today.

**Step 5 — Invert the flow-testing default (Option A).**
The three-line change in `EcotoneLite::prepareForFlowTesting` (`:248-251`) from §5.1, plus
`TestConfiguration::withPackages()` / `withRealInfrastructure()`.
Tests: `bootstrapFlowTesting([X])` with dbal+eventSourcing+jms installed loads them;
`TestConfiguration::withPackages([])` reproduces the old Core-only behaviour;
`ServiceConfiguration::withModulePackages([...])` still wins over the default.
Then run the **full** package suites sequentially (never in parallel — shared docker services) and
triage the diff.

**Step 6 — Deprecate `bootstrapFlowTestingWithEventStore`.**
Keep it as a thin alias; add `@deprecated`. Mechanical codemod over the 161 call sites in a separate PR.

**Step 7 — Slice entry points (Option D).**
`forAggregate` / `forSaga` / `forProjection` / `forHandlers` on `EcotoneLite`, each a thin wrapper
over `bootstrapFlowTesting` that adds the class under test to `classesToResolve`.
Tests: one per slice, mirroring the §5.2 examples.

**Step 8 — Docs.**
Skill updates per §5.3; `upgrade-2.0.md` §1 and §9 rewritten now that the async channel is
auto-provisioned in tests; a "testing" page in the docs site describing the test profile table so the
prod/test divergence is written down, not discovered.

---

## 8. Open questions for the maintainer

1. **Real-DB detection.** Step 2 hinges on "did the user give us a connection?". The only signal
   available at `prepare()` time is the external container (`MessagingSystemConfiguration::$externalContainer`,
   used by `ValidateRequiredReferencesPass.php:42`). Is checking
   `$externalContainer->has(DbalConnectionReference::DEFAULT)` acceptable as the discriminator, or do
   you want an explicit opt-in (`TestConfiguration::withRealInfrastructure()`) as the *only* way to get
   production DBAL defaults in a test?

2. **Should `withModulePackages([])` keep meaning Core-only?** 196 sites rely on it. Keeping the
   semantics means two ways to say "narrow the packages" (`ServiceConfiguration::withModulePackages`
   for production, `TestConfiguration::withPackages` for tests). Changing it means a breaking sweep.
   My recommendation is to keep it; confirm.

3. **Auto-provisioned async channel — queue or delayable queue?** 2.0 made
   `SimpleMessageChannelBuilder::createQueueChannel()` delayable by default (`upgrade-2.0.md:246`).
   Auto-provisioning a delayable channel means `#[Delayed]` messages are invisible to `run()` until the
   clock advances — correct, but a new surprise for someone who never registered a channel. Provision
   delayable (production-like) or non-delayable (simplest)?

4. **Should the missing-async-channel `ConfigurationException` survive in flow tests at all?**
   Option B removes it for the common case. Do you want it retained behind
   `TestConfiguration::withAutomaticInMemoryChannels(false)` so a team can assert their production
   channel wiring in a test, or is that better served by a dedicated "configuration validation" test
   using `EcotoneLite::bootstrap()`?

5. **Kafka guard shape.** `KafkaModule::prepare` currently throws before inspecting anything
   (`KafkaModule.php:73-76`). Guarding on "no Kafka extension objects and no `#[KafkaConsumer]`
   methods" is straightforward, but it changes the error a *misconfigured* enterprise user sees from
   "Kafka needs a licence" to silence. Acceptable?

6. **`EcotoneLite::bootstrap()` parity.** Production Lite already loads everything. Should the
   *test profiles* (in-memory DBAL/event store) apply there too when `$enableTesting = false` but no
   infra is configured — or is that strictly a testing-only behaviour? I assumed testing-only.

7. **Performance budget.** What regression in per-test bootstrap time is acceptable in exchange for
   deleting the package lists? Step 0 measures it; I need a number to decide whether
   `TestConfiguration::withPackages()` ships as an escape hatch or as a recommended practice.

8. **Slice naming.** `forAggregate` / `forSaga` / `forProjection` / `forHandlers` vs a single
   `EcotoneLite::testing()` builder (`->withAggregate(X)->withServices([...])`). The builder is more
   extensible; the four statics read better at the call site. Preference?

---

## Sources

Ecotone: all `file:line` references above, against `dgafka/ecotone-2-0-work` @ `bcf4efc0`.

External:
- [Symfony Messenger docs](https://symfony.com/doc/4.x/messenger.html) · [SymfonyCasts: testing with in-memory transport](https://symfonycasts.com/screencast/messenger/test-in-memory) · [symfony/symfony PR #29097 (in-memory:// transport)](https://github.com/symfony/symfony/pull/29097/files/8f8c82e009e885848876562598d8f06d163c2e88)
- [zenstruck/messenger-test README](https://github.com/zenstruck/messenger-test/blob/1.x/README.md)
- [Laravel mocking / fakes](https://laravel.com/docs/5.7/mocking) · [QueueFake API](https://api.laravel.com/docs/11.x/Illuminate/Support/Testing/Fakes/QueueFake.html)
- [Spring Boot @DataJpaTest API](https://docs.spring.io/spring-boot/api/java/org/springframework/boot/data/jpa/test/autoconfigure/DataJpaTest.html) · [Spring Boot testing reference](https://docs.spring.io/spring-boot/docs/2.1.6.RELEASE/reference/html/boot-features-testing.html) · [Test slices overview](https://www.diffblue.com/resources/spring-boot-test-slices-overview-and-usage/)
- [Quarkus Dev Services tutorial](https://redhat-developer-demos.github.io/quarkus-tutorial/quarkus-tutorial/06_dev-services.html) · [Dev Services overview](https://www.techtarget.com/searchapparchitecture/tip/Quarkus-Dev-Services-Zero-config-development)
- [Micronaut Test guide](https://micronaut-projects.github.io/micronaut-test/latest/guide/)
- [Axon: testing commands & events](https://docs.axoniq.io/axon-framework-reference/4.13/testing/commands-events/) · [AggregateTestFixture API](https://apidocs.axoniq.io/4.0/org/axonframework/test/aggregate/AggregateTestFixture.html)
- [NestJS testing](https://docs.nestjs.com/fundamentals/testing)
- [Django: sending email / locmem backend](https://docs.djangoproject.com/en/6.1/topics/email/)
- [Rails ActiveJob TestAdapter](https://api.rubyonrails.org/classes/ActiveJob/QueueAdapters/TestAdapter.html) · [Active Job Basics](https://guides.rubyonrails.org/active_job_basics.html)
- [Tempest testing](https://tempestphp.com/2.x/essentials/testing)
- [EventSauce testing](https://eventsauce.io/docs/testing/) · [EventSauce architecture](https://eventsauce.io/docs/architecture/)
