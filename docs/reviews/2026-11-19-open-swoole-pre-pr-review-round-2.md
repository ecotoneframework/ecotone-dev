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
