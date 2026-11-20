# Pre-PR review of the execution scope and the connection pool

Range reviewed: `main...2601129b3` (four commits). Four sessions, each from a fresh context. Ranks: BLOCKING,
SHOULD-FIX, NIT. Fixes cite these numbers.

## Guidelines (G)

- **G1 BLOCKING** rule 3a: services moved to `ExecutionScope` without a fiber-interleaving test:
  `HeaderBasedMultiTenantConnectionFactory`, `PendingDeliveryRegistry`, `RetryStatusTracker`,
  `AmqpTransactionInterceptor`, `EcotoneTracer`, `TracingChannelInterceptor`, `OpenTelemetryTracer`,
  `ObjectManagerInterceptor`, `CachedConnectionFactory` for non-DBAL transports.
- **G2 SHOULD-FIX** rule 3a's Expected ("no mutable or static property") is contradicted by state shared across
  executions on purpose: `DbalTransactionInterceptor::$executionsHoldingTransaction`, `ConnectionPool`,
  `MessageHeadersPropagatorInterceptor::$exceptionsWithCausationChain`, the static WeakMap of
  `PendingDeliveryRegistry`. State the carve-out or move them.
- **G3 SHOULD-FIX** rule A1: a `Connection`/`DbalContext` property and "a property written outside the constructor"
  can be decided by a check with an exact allowlist instead of an eval.
- **G4 SHOULD-FIX** `bin/check-framework-connections.php` matches only a literal `DriverManager::getConnection(`,
  while rule 16e names the check for the whole rule.
- **G5 SHOULD-FIX** AMQP: contexts are now per execution, `AmqpPublisherConfirmations` and the socket stay
  process-wide.
- **G6 SHOULD-FIX** testing rule 10: the Dbal test imports `Ecotone\Enqueue\ConnectionFactory` and counts server
  sessions.
- **G7 SHOULD-FIX** testing rule 10a: isolation is proved only for routed command-bus sends, not for the query bus,
  a class-routed send, a gateway or an asynchronous endpoint.
- **G8 NIT** assertion messages in `$this->fail()` in the Dbal test.
- **G9 NIT** prose line added to the `DbalConnectionFactory` docblock under `Api/`.
- **G10 NIT** `DbalTransactionInterceptor`: the constructor's array-shape docblock is orphaned above the new property.
- **G11 NIT** `ConnectionPool.php:45`: three arguments, only the last named.
- **G12 NIT** `ExecutionList` is used as a string-keyed map in `CachedConnectionFactory`.
- **G13 NIT** the `keep-ambient-state` eval prompt names Swoole coroutines, so it does not prove the skill is found
  without the hint.
- **G14 NIT** storage `SKILL.md` References line not updated for 16e; 16e sits between 16d and 16b.
- **G15 NIT** P2's list does not name the two `check-framework-*` scripts.
- **G16 NIT** the pool-size refusal fires when the application constructs the factory, which can be the first message.
- **G17 NIT** no upgrade note or docs for `connection_pool_size` and the new refusal.

Code the diff did not touch:

- **U1 BLOCKING** `packages/Dbal/src/Tracing/StoringSpanRecorder.php:37-49` keeps the scope of the handled message in
  properties on the command, event and query bus path.
- **U2 SHOULD-FIX** rule 16e: `ManagerRegistryEmulator` and `DbalConnection` take one pooled connection at
  registration and pin it, with an EntityManager, for the life of the process.
- Unverified, probably safe: `KafkaAdmin`, `KafkaDeliveryTracker`, `SqsRequestDispatchPool`,
  `Enqueue*ChannelAdapter::$initialized` keyed by `spl_object_id($context)`, the Redis and SQS clients.
- Consumer-only: `AsyncEndpointAnnotationContext`, the consumer interceptors, `DbalConsumer`, the batch commit
  coordinators.

## Breaking changes (B)

- **B1 BLOCKING** a fiber or coroutine started inside a handler gets a fresh scope: another connection outside the
  outer transaction, no propagated headers, an empty collector. In 1.x it joined the outer flow.
- **B2 BLOCKING** the new refusal in `DbalTransactionInterceptor` fires for a nested fiber on an application-built
  connection, which is sequential code that used to join the open transaction.
- **B3 SHOULD-FIX** without Swoole a fiber cannot wait for a connection and keeps its own until the Fiber object is
  collected, so the eleventh live fiber fails at once; the message advises Swoole settings only.
- **B4 SHOULD-FIX** each execution's cached AMQP context opens a channel that nothing closes.
- **B5 SHOULD-FIX** `DbalConnectionFactory::close()` opens a connection to close it, can wait five seconds on an
  exhausted pool, and closes only the caller's and the idle connections.
- **B6 NIT** an application running in one long-lived fiber holds two connections, the process's and the fiber's.
- **B7 NIT** the shutdown flush of pending deliveries covers the process scope only.
- No upgrade note exists for any of the above.

## Behaviour (V)

- **V1 BLOCKING** `ConnectionPool::takeBack()`: a connection returned disconnected frees its slot without waking a
  waiting coroutine, which then fails after five seconds. Reproduced on PostgreSQL and MySQL.
- **V2 SHOULD-FIX** the coroutine wait, the hand-off, rollback on return and `ExecutionScope` on
  `Coroutine::getContext()` have no automated test; the `app` container has no Swoole.
- **V3 BLOCKING** same as B1, verified: `'direct' => 'tenant-a', 'inside-child-fiber' => NULL`, and a child coroutine
  gets a session outside the parent's transaction.
- **V4 BLOCKING** same as U1.
- **V5 SHOULD-FIX** `AsyncEndpointAnnotationContext::$currentAnnotations` is a process property.
- **V6 BLOCKING** tenant activation is per execution, but `LaravelTenantDatabaseSwitcher` sets
  `database.default` for the process and the Tempest switcher swaps container singletons.
- **V7 SHOULD-FIX** every execution's `createContext()` resets the one `AmqpPublisherConfirmations`; channels leak
  (B4, G5).
- **V8 SHOULD-FIX** same as U2; the refusal text ("register the connection from a DSN") is wrong for it.
- **V9 SHOULD-FIX** on an application-built connection only a second transaction is refused: an execution without a
  transaction joins the other's, interleaved reads on MySQL are not refused, and `ObjectManagerInterceptor` clears
  a shared EntityManager.
- **V10 SHOULD-FIX** same as B5.
- **V11 NIT** fiber state is released when the Fiber object is collected, not when it terminates.
- **V12 BLOCKING** the eval cases `keep-ambient-state` and `store-feature-state` were not graded: the run hit a usage
  limit.
- **V13 BLOCKING** `framework-pull-request` case `update-open-pr` scored 0.50 (rule-p3 failed three of three); not
  yet compared with `main`.
- **V14 SHOULD-FIX** no Dbal test was seen red; the rollback tests hang on the base instead of failing. No test for
  retry, a nested command bus, an asynchronous consumer, two tenants, the tracers or AMQP under interleaving.
- **V15 NIT** counting server sessions is sensitive to other sessions on the test database.

Verified by the behaviour session on OpenSwoole 26.2, one worker, coroutines on: PostgreSQL and MySQL interleave
scenario correct, 300 of 300 requests saw their own tenant, a killed pooled session recovered, 100 concurrent
deposits to one aggregate lost no update.

## Developer experience (D)

- **D1 SHOULD-FIX** `SymfonyConnectionReference` and `LaravelConnectionReference`, the documented default, get no
  pool, only the runtime refusal.
- **D2 SHOULD-FIX** the refusal fires only when two transactions overlap, so it appears under load.
- **D3 SHOULD-FIX** the refusal names only `withTransactionOnCommandBus(false)`.
- **D4 SHOULD-FIX** "run the handler without a database transaction" is offered as an equal way out.
- **D5 SHOULD-FIX** `ConnectionPoolExhausted` does not name the connection.
- **D6 NIT** no advice for plain fibers; the process's own connection counts toward the size.
- **D7 NIT** the docblock does not mention workers times size or the five-second wait.
- **D8 NIT** no public skill or documentation mentions the option.
- **D9 NIT** the size cannot be set in a DSN string.
