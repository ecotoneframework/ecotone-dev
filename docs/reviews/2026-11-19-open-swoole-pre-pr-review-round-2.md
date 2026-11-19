# Pre-PR review, round 2: dgafka/open-swoole at 94d8acee9

Four review sessions (guidelines, breaking changes, behaviour, developer experience) read `main...HEAD` after the
merge of `origin/main`. Round 1 is `2026-11-18-execution-scope-and-connection-pool-pre-pr-review.md`; numbering
continues from it.

## Guidelines (G)

- **G20 BLOCKING** (error handling 1) `DbalTransactionInterceptor.php:165`: the refusal of a transaction another
  execution holds names only a DSN registration and `enable_coroutine=false`; it does not name
  `DbalConnection::createWithCopiesForConcurrentExecutions()` or `SymfonyConnectionReference::defaultConnection()`.
  Same stale text in `InterleavedExecutionsTest.php:249` and `upgrade-2.0.md:3011`.
- **G21 SHOULD-FIX** (1, 1b) `ConnectionPoolExhausted.php:20`, `ConnectionPool.php:36`: the way out names only
  `connection_pool_size` and the Symfony call, wrong for copies of an application connection; message not asserted.
- **G22 SHOULD-FIX** (service design 2, 3) `AlreadyConnectedDbalConnectionFactory.php:19`: nullable pool assigned
  after construction.
- **G23 SHOULD-FIX** (public API 12a) `DbalConnection.php:37`: `createWithCopiesForConcurrentExecutions()` is an
  application entry point on a `src/` class returning an internal type, and is missing from `upgrade-2.0.md`.
- **G24 BLOCKING** (service design 3a): `RecordedSpans` and `AsyncEndpointAnnotationContext` moved into
  `ExecutionScope` with no interleaving test.
- **G25 SHOULD-FIX** (testing 10b, E3): coroutine tests order executions by wall-clock pauses; `runCoroutines()` is
  copied into three files.
- **G26 SHOULD-FIX** (dev environment, A2): `workflow.md` does not mention the `swoole` service, the `coroutines`
  job or `check-framework-connections`.
- **G27 NIT** (A1) `test-monorepo.yml:222`: `COROUTINE_TESTS` is a hand-written list.
- **G28 SHOULD-FIX** (E4): phpstan errors silenced with `ignoreErrors` / `excludePaths` instead of OpenSwoole stubs.
- **G29 NIT** (6) `AmqpTransactionInterceptor.php:32`: orphaned constructor docblock.
- **G30 NIT** (6a): two constants for one default pool size.
- **G31 NIT** (18): positional calls of three or more arguments (`SymfonyConnectionReference.php:69`,
  `ConnectionPool.php:120,132`).
- **G32 NIT** (A1) `bin/check-framework-connections.php:93`: regex misses some property spellings; no self-test.
- **G33 NIT** (11) `InterleavedOrmExecutionsTest.php:21`: shared fixture directory and a `src/` import.
- **G34 NIT** (1a, Pulse): `PULSE_CONSUMER_COROUTINES`, `PULSE_HTTP_WORKERS`, `PULSE_WORKER_*` read with raw
  `getenv`; prose docblock and static state in `WriterTurnBetweenCoroutines`.
- **G35 NIT** (2) `PendingDeliveryRegistry.php:155`: `?Closure` state.
- Round 1 still open: G1 (tests for multi-tenant factory, retry tracker, tracers), G6/V15 (server sessions
  counted), G9, G12, G13, G16.

## Breaking changes (B)

- **B2 (round 1) SHOULD-FIX**: a fiber started by a handler on an application-built connection is refused where 1.x
  joined; the note and the wording "running concurrently" do not cover it.
- **B10 SHOULD-FIX** `upgrade-2.0.md:3003`: the note says AMQP channels are pooled; the code opens one connection
  per concurrent execution with no limit.
- **B11 SHOULD-FIX** `DbalConnectionModule.php:46`: `#[EventHandler]` (not an `EndpointAnnotation`) and
  interceptors taking `Connection` are not refused, contrary to the note.
- **B12 SHOULD-FIX** `upgrade-2.0.md:3031`: the autowired form without an attribute and the reference names Symfony
  and Laravel register under are not named.
- **B13 NIT** `SymfonyConnectionReference.php:44`: two entry points for the pool size, one validated.
- **B14 NIT** §2b: named-argument rename not mentioned.
- **B15 NIT** `upgrade-2.0.md:3005`: the Symfony copy applies to any fiber.
- **B6 (round 1) NIT**: process connection plus fiber connection both count toward the size.

## Behaviour (V)

- **V20 BLOCKING**: OpenSwoole does not hook `pdo_pgsql`. Two coroutines of one worker contending a row deadlock
  the worker (probe: B waits out `lock_timeout`, A cannot commit meanwhile). Untested, undocumented; the CI
  PostgreSQL step passes because nothing contends.
- **V21 BLOCKING**: on a cold worker, coroutines building the same lazy Ecotone service fail with Symfony's
  `ServiceCircularReferenceException`; a consumer coroutine whose `run()` throws dies silently.
- **V22 SHOULD-FIX** `ConnectionPool.php:41`: a consumer coroutine keeps its connection for life; more consuming
  coroutines than the pool size fail messages with `ConnectionPoolExhausted`.
- **V23 SHOULD-FIX** `PcntlTerminationListener.php:63`: termination state is per process; the first coroutine to
  stop clears it for the others.
- **V24 SHOULD-FIX** `ConnectionPool.php:72`: reconnecting for a waiter inside a destructor leaks the slot when the
  database is down.
- **V25 SHOULD-FIX**: ext-amqp is not hooked either; undocumented outside Pulse's README.
- **V26 SHOULD-FIX** `AmqpReconnectableConnectionFactory.php:92`: kept-open contexts have no upper bound.
- **V27 SHOULD-FIX** `Amqp/CoroutineExecutionsTest.php:52`: draining in the main process hides stuck consumers.
- **V28 NIT** `DbalConnectionModule.php:95`: `#[CurrentConnection('name')]` naming a plain `Connection` passes
  bootstrap.
- **V29 NIT** `ExecutionScope.php:74`: a fiber inside a coroutine uses the coroutine's scope.
- **V30 SHOULD-FIX**: eval `store-feature-state` 0.88, rule-9 failed 3/3 after the prompt changed. Evals:
  keep-ambient-state 1.00, add-dependency 1.00, framework-store-transaction 1.00, handle-version-conflict 1.00,
  new-rule-enforcement 1.00, open-pr 1.00, update-open-pr 0.00 (not changed by this branch).
- Round 1 still open: V6 (tenant switchers), V9 (partly), V14 (retry, two tenants, tracers), V15.

## Developer experience (D)

- **D10 SHOULD-FIX**: no declarative way to consume on coroutines (`ecotone:run --coroutines`); every application
  writes `openswoole-consumer.php` by hand.
- **D11 SHOULD-FIX**: ext-amqp on coroutines blocks silently (same as V25).
- **D12 SHOULD-FIX**: SQLite DSN on coroutines: `:memory:` gives each execution an empty database; a file database
  blocks on a second writer.
- **D13 SHOULD-FIX**: same as G20.
- **D14 SHOULD-FIX** `DbalConnectionModule.php:52,115`: refusals miss the framework references and constructor
  injection as ways out.
- **D15 SHOULD-FIX**: `#[MultiTenantObjectManager]` stays while `#[CurrentConnection]` replaced its sibling.
- **D16 SHOULD-FIX**: a child coroutine writes outside the handler's transaction silently.
- **D17 NIT**: same as G34.
- **D18 NIT**: pool messages name the connection by driver/host, not its reference name.
- **D19 NIT**: quickstarts still take the tenant connection factory by `#[Reference]`.
- **D20 NIT**: same as B10.
- Round 1 still open: D1 (Laravel), D2, D6, D8 (public skills), D9.

## Re-verification at ee370314d

Resolved: G20, G24, G26, G29, G31, B2, B10, B11, B12, B14, V20, V21, V23, V25 (documented), D13, D17, D20
(documented). Resolved in code without a test: V24. Partly: G34, V22, V26, V28, D11, D12, D14, D16.

- **G1 (round 1) BLOCKING, narrowed**: no interleaving test for `HeaderBasedMultiTenantConnectionFactory` /
  `ActiveTenants`, `RetryStatusTracker`, `OpenTelemetryTracer`.
- **G36 SHOULD-FIX** `Ecotone/CoroutineExecutionsTest.php:125`: the termination test asserts a wall-clock bound, sends
  a real signal after a sleep, and does not require `pcntl` and `posix`.
- **G37 NIT**: the PostgreSQL refusal is raised at runtime. **G38 NIT**: prose added to docblocks. **G39 NIT**:
  nullable `OneExecutionAtATime` in the trait. **G40 NIT**: Pulse kernel calls three validators for their refusals.
- **B16 SHOULD-FIX** `upgrade-2.0.md`: "How to adapt" says "nothing" for one message at a time, but an OpenSwoole
  server has `enable_coroutine` on by default, so a PostgreSQL DSN is refused there.
- **B17 NIT**: the PostgreSQL refusal covers only connections Ecotone opens itself. **B18 NIT / V23 follow-up**
  `InterceptedConsumer.php:79`: a throwing stop hook skips `onShutdown()`, leaving the listener count and flag set.
  **B19 / V31 NIT** `OneExecutionAtATime.php:39`: the turn is awaited without a timeout.
- **D21 SHOULD-FIX**: Pulse with a PostgreSQL transport and `PULSE_RUNTIME=swoole` restarts in a loop instead of
  refusing at start.
- **D22 NIT**: same as G23. **D23 NIT** `ConnectionPoolExhausted.php:20`: does not name consumer coroutines.
- Still open and left for the maintainer: G21, G22, G23, G25, G27, G28, G30, G32, G33, G35, B13, B15, B6, V6, V27,
  V29, V30, D10, D15, D18, D19, D1, D2, D8, D9.

## Closing check at 51a40e303

All four sessions: no BLOCKING finding remains. Resolved: G1, G40, B16, B17, B18, D21, D23, V14 (retries, DSN-based
tenants, tracing). New: **G41 NIT** `InterleavedTracingTest.php` filters spans by the name of an internal class;
**V32 SHOULD-FIX** the OpenTelemetry SDK keeps its context per fiber, so spans exported from coroutines running at
once can carry the wrong parent (stated in `upgrade-2.0.md`; not fixed). Open and left for the maintainer: the list
above, plus G36 (wall-clock bound), V6 for the Laravel and Tempest tenant switchers, V22, V26, V27, V28, V29, V30,
V31 / B19.

## Maintainer decisions after the closing check

- **D10** resolved: `ecotone:run --coroutines=N`, `ExecutionPollingMetadata::withCoroutines()` and
  `PollingMetadata::withCoroutines()`; refused without the `openswoole` or `swoole` extension, below one coroutine and
  for a scheduled endpoint. Pulse consumes with it in place of its own script.
- **V32** resolved by a test suite: `CoroutineTracingTest` runs on OpenSwoole with `open-telemetry/context-swoole` and
  is its own CI step; without that storage three of its four tests fail.
- **D15** resolved: `#[MultiTenantObjectManager]` is `#[CurrentConnectionObjectManager]`.
- **D8** resolved for coroutines: public skill `ecotone-open-swoole` with eval case `consume-on-coroutines` (1.00).
- **V30** resolved: `store-feature-state` 1.00 after its prompt asks for the tests; `update-open-pr` 1.00 unchanged.
  `bin/test-agent-skills` copies `api/skills` and `api/evals` into its throwaway plugin, because
  `claude plugin eval` refuses an evals directory linked from outside the plugin.
- **V26 / B10** accepted as is: RabbitMQ connections stay unbounded.
- The PostgreSQL refusal stays.

## Round 3 at c34707253

Four new sessions (the earlier ones could not be resumed), given the whole range and the lists above; they read in
full the commits since e72af8669: consumer coroutines, the `#[CurrentConnectionObjectManager]` rename, the public
skill and Swoole as a tested runtime. Behaviour session runs: Swoole 27 tests, OpenSwoole 24, tracing 4 on each,
refusals 7 in `app`.

### Guidelines

- **G42 SHOULD-FIX** `ConsumerCoroutines.php:23` (rules 1a, 1c): `PollingMetadata::withCoroutines(N)` for a scheduled
  endpoint is known while the container is built, but refused only when the endpoint is run.
- **G43 SHOULD-FIX** `ConsumerCoroutinesRefusedTest.php:94` (rule 10a): the scheduled-endpoint refusal is tested through
  `ExecutionPollingMetadata` only, not through `PollingMetadata` and the console option.
- **G44 SHOULD-FIX** `workflow.md:243`: the CI table still describes one OpenSwoole job.
- **G45 NIT** `CoroutineTracingTest.php:216,236`: static helper methods in a test class.
- G25 (sleeps order the coroutine tests, `runCoroutines()` copied) is wider: a fourth copy and more staggered sleeps.
  G28 (no stubs for the extensions) is wider in reach. Both stay open.

### Breaking changes

- **B20 SHOULD-FIX** `api/skills/ecotone-open-swoole/SKILL.md:88,182`, grader `rule-5.md`, `prompt.md:43`: the skill
  names `Enqueue\AmqpLib\AmqpConnectionFactory`, which 2.0 replaced by `Ecotone\Api\Amqp\AmqpLibConnectionFactory`.
- **B21 NIT** `PollingMetadata.php:55`: the public constructor takes `coroutines: 0`; the consumer then exits silently.
- **B22 NIT** `upgrade-2.0.md:144`: "renamed the same way", but `#[CurrentConnectionObjectManager]` keeps
  `referenceName`, the multi-tenant default and the Enterprise licence.
- One message at a time, `ecotone:run` without `--coroutines`, the compiled `PollingMetadata` definition and the
  namespace map are unchanged or complete.

### Behaviour

- **V33 BLOCKING** `ConsumerCoroutines.php:23`, `KafkaInboundChannelAdapter.php:54`, `BatchCommitCoordinator.php:60`,
  `AmqpStreamInboundChannelAdapter.php:65`: Kafka and RabbitMQ Streams consumers are accepted on coroutines. The
  coroutines share one consumer and commit the offset of whichever message finished, so a later offset is committed
  while an earlier message is still being handled, and that message is lost when the process dies.
- **V34 SHOULD-FIX** `ConsumerCoroutinesTest.php:126`: the failure test asserts only the thrown message; removing the
  `stop()` of the other coroutines leaves it green.
- **V35 SHOULD-FIX**: no test of consumer coroutines with an error channel, dead letter or retries.
- **V36 SHOULD-FIX**: every consumer-coroutine test consumes an in-memory channel; none a DBAL or RabbitMQ channel,
  and none with more coroutines than pooled connections.
- **V37 SHOULD-FIX**: "limits apply to every coroutine" has no test.
- **V38 NIT**: restoring the application's hooks on OpenSwoole is untested. **V39 NIT**: the termination signal
  through `withCoroutines()` is untested. **V40 NIT**: the console option over configured coroutines, and an explicit
  one coroutine over configured three, are untested. **V41 NIT** `CoroutineTracingTest.php:120`: does not assert the
  messages overlapped.

### Developer experience

- **D24 SHOULD-FIX** `ConnectionPool.php:124`, `ConnectionPoolExhausted.php:19`: `--coroutines=16` with the default pool
  of 10 leaves six coroutines failing after a five second wait; the message names `max_coroutine`, not `--coroutines`.
- **D25 SHOULD-FIX** `ConnectionPool.php:108`: the PostgreSQL refusal does not say which of the three causes applies
  (OpenSwoole, Swoole built without the flag, hook not enabled) and gives a consumer no way out but a server setting.
- **D26 SHOULD-FIX** `SKILL.md:22,92,167`, `upgrade-2.0.md:3070`: PostgreSQL is described as a problem on OpenSwoole
  only; a stock Swoole build is refused too.
- **D27 SHOULD-FIX** `SKILL.md:35`: the Laravel command is shown as working while Laravel's connection is one per
  process.
- **D28 SHOULD-FIX** `CurrentConnectionObjectManager.php:17`: the new name suggests it works in a single-tenant
  application as `#[CurrentConnection]` does; it needs a `MultiTenantConfiguration` and the Enterprise licence.
- **D29 NIT**: "limits apply to every coroutine" and "when one coroutine fails" read ambiguously. **D30 NIT**
  `SKILL.md:90`: the SQLite row names no build flag and contradicts the upgrade note; Kafka is missing from the table.
  **D31 NIT**: same as B21. **D32 NIT** `upgrade-2.0.md:3029`: the parenthesis describes the build that is not refused.

### Re-verification at 759cda4e4

Resolved: G42, G43, G44, G45, B20, B21, B22, V34, V36, V37, V38, V40, V41, D25, D26, D27, D29, D31, D32; V33 for a
stream consumed directly; V35 for the error channel and dead letter. Partly: D24 (the message names the coroutines;
no refusal at start), D30 (sending to Kafka from a coroutine is not in the table). Left for the maintainer: D24, D28,
V39, G25, G28.

- **V42 BLOCKING** `DynamicMessageChannelBuilder.php:230`, `InterceptedPollingConsumerBuilder.php:121`: a dynamic
  channel receiving from Kafka or RabbitMQ stream channels reports no stream, so its consumer is accepted on
  coroutines (same finding as **B23 NIT**).
- **D33 SHOULD-FIX** `ConsumerCoroutines.php:41`, `SKILL.md:67`: "start more processes" is wrong for a RabbitMQ stream,
  where every process reads the whole stream.
- **D34 NIT** `ConsumerCoroutines.php:38`: the refusal names Kafka and RabbitMQ only, also for an in-memory streaming
  channel.
- **V43 NIT**: the refusal at consumer start is tested for the in-memory streaming channel only, not for Kafka.
- **G46 NIT** `ConnectionPool.php:110`: the "built without --enable-swoole-pgsql" cause has no test; CI's Swoole is
  built with it.

### Re-verification at e793fa110

All four sessions: no BLOCKING finding. Resolved: V42, V43, B23, D30, D33, D34. G46 accepted as untestable in CI.

- **G47 SHOULD-FIX / B24 NIT** `DynamicMessageChannelBuilder.php:235`: `canReceiveFromAStream()` is container plumbing on a
  public `Api/` class, and reads the receiving channel names out of a `Definition` argument.
- **D35 SHOULD-FIX / V45 NIT / B25 NIT / G48 NIT** `DynamicMessageChannelBuilder.php:240`: a throttling dynamic channel
  over queues is refused when the application has any stream, with a message saying it reads one; the custom-strategy
  case has no test and no wording of its own.
- **V44 NIT**: a dynamic channel receiving from another dynamic channel over a stream is not detected.

### Closing check at c63e9b776

All four sessions: no BLOCKING finding. Resolved: G47, G48, B24, B25, V44, V45, D35 for a throttled channel. The
refusal then gained the sentence D35 asked for, naming the receiving strategies that keep coroutines for a custom one.

Open and left for the maintainer:

- **D24**: more coroutines than pooled connections fails after a five second wait instead of being refused at start
  or covered by the pool.
- **D28**: `#[CurrentConnectionObjectManager]` needs a `MultiTenantConfiguration` and the Enterprise licence, which its
  new name does not suggest.
- **D35 NIT**: the stream refusal does not name the stream channel. **G49 NIT**: two plain getters added to
  `DynamicMessageChannelBuilder`, a class that is mutable as a whole. **G46 NIT**: the "built without
  --enable-swoole-pgsql" cause is untested. **V39 NIT**: the termination signal through `withCoroutines()` is
  untested. **V35 NIT**: consumer coroutines with retries are untested. **G25**, **G28** as before.

### The maintainer's decisions on the open findings

- **D28, decided: works without tenants, stays Enterprise.** `#[CurrentConnectionObjectManager]` injects the Doctrine
  ObjectManager of the connection the message is handled on, with or without `MultiTenantConfiguration`. Its argument
  is the connection reference name and defaults to the default connection, like `#[CurrentConnection]`. A connection
  without Doctrine ORM, a name that is not registered and a missing licence are each refused with a message naming the
  way out.
- **D35**: the stream refusal names the stream channels the consumer reads, when they differ from the consumer's name.
- **G49**: `DynamicMessageChannelBuilder` is immutable: every `with*()` returns a copy, and the two getters are
  `public readonly` properties. `upgrade-2.0.md` says so.
- **V39**, **V35**: tests of a termination signal stopping every coroutine of a consumer, and of a failing message
  retried on its coroutine while the others go on.
- **G25, in part**: the four copies of `runCoroutines()` are one `Ecotone\Test\CoroutineTesting`. The handlers still
  pause with `usleep()`: under the hooks that pause is the wait a coroutine yields on, which is what the tests observe.
- **G28, not done**: `openswoole/ide-helper` requires `ext-openswoole`, so it cannot be a development dependency of a
  repository whose jobs mostly run without the extension. Framework code names no extension symbol; the two benchmark
  server files stay excluded from phpstan.
- **G46** stays untestable in CI. **D24** is left as it is.

## Re-verification at 3f862505f

All four sessions: no BLOCKING finding. Resolved: D28, D35, G49, G25, V35, V39. New findings:

- **V47 SHOULD-FIX** `EcotoneManagerRegistryConnectionFactory.php:34`: the object manager is asked for by the connection
  name, which fails in a Symfony application whose entity manager is named differently from its connection; no Symfony
  test of the attribute without tenants.
- **V46 SHOULD-FIX** `CurrentConnectionObjectManagerTest.php:73`: the named connection has the DSN of the default one, so
  the test passes when the name is ignored.
- **B26 SHOULD-FIX** `upgrade-2.0.md:144`: a bare attribute beside a `MultiTenantConfiguration` under another reference
  name now injects the default connection's object manager; the note does not say so as a step. **B27 NIT**: the
  argument rename is not mentioned.
- **D36 SHOULD-FIX** `DbalConnectionModule.php:130`: the refusal of an unregistered name suggests registrations without
  Doctrine ORM. **D37 SHOULD-FIX**: Symfony's manager registry is one ObjectManager for the process; the note recommends
  it without saying so.
- **G50 SHOULD-FIX / D38 NIT** `ObjectManagerOfCurrentConnection.php:46`: the refusal names the internal
  `ManagerRegistryEmulator` without a namespace, and the test constructs it.
- **D39 NIT**: "it reads a stream, `a`, `b`" trips. **D40 NIT / B28 NIT**: a statement-form `with*()` on
  `DynamicMessageChannelBuilder` loses its value without a warning. **V48 NIT**: the retry test passes with a retry
  count shared between coroutines. **V49 NIT**: the refusal of a `#[KafkaConsumer]` names no topic. **G51 NIT**:
  `CoroutineTesting` has no test of its own.

## Re-verification at e96a599ff

All four sessions: no BLOCKING finding. Resolved: V46, V47 (the defect), V48, V49, B26, B27, B28, D36, D38, D39, D40, G50,
G51. New findings:

- **G52 SHOULD-FIX / V50 SHOULD-FIX / D42 SHOULD-FIX / B29 NIT** `EcotoneManagerRegistryConnectionFactory.php:45`: a
  connection no entity manager uses is given the registry's default manager, which writes through another connection;
  no test separates the match from the fallback.
- **D43 SHOULD-FIX**: the refusals and §2c no longer name the one registration that gives every coroutine its own
  ObjectManager.
- **D41 SHOULD-FIX**: an attribute without a name beside a `MultiTenantConfiguration` under its own reference name
  injects the default connection's ObjectManager without an error when that connection has Doctrine ORM. Left for the
  maintainer: refusing it needs the attribute to tell "no name" from "the default name", for `#[CurrentConnection]` too.
- **V51 NIT**: a `#[KafkaConsumer]` whose topic is named like its endpoint is refused without the topic's name. Left:
  the refusal already names the consumer by that name.

## Re-verification at 9dba3cde7

All four sessions: no BLOCKING finding. Resolved: G52, V50, D42, D43, B29. New findings:

- **G53 SHOULD-FIX**: the refusals and §2c name `Ecotone\Dbal\ManagerRegistryEmulator::fromDsnAndConfig()`, a `src` class,
  as an application's entry point; D43 asked for it to be named. Left for the maintainer: an `Api/` factory, or the
  class accepted as an entry point like `DbalConnection::createForManagerRegistry()` (G23).
- **G54 NIT**: `RegistryOfTwoManagers` is a named fixture an anonymous class can replace. **D44 NIT**: the
  per-coroutine registration builds its ObjectManager outside a Symfony application's `doctrine.orm` configuration,
  unsaid. **D45 NIT**: the refusal names the Doctrine connection, not the reference name the developer wrote.
- **V52 NIT**: the multi-tenant path still asks for a manager named like the connection, as before this branch. Left.
