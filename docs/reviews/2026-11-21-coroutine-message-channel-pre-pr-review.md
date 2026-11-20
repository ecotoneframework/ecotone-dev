# Coroutine message channel: pre-PR review, round 1

Reviewed at d936b06eb (range `origin/main...HEAD`: the configurable connection pool wait, a734e9fd6 and a5446f2c3, and
the coroutine message channel, 15 commits) by four independent sessions: guidelines (G), breaking changes (B),
behaviour (V), developer experience (D). Verdicts: breaking changes safe to release; the other three not ready.

## Blocking

- **V1 BLOCKING** `CoroutineMessageChannelModule.php:95`: a console command is not a release boundary. A
  `#[ConsoleCommand]` that sends through the command bus and then throws still starts the handler, and with DBAL the
  handler starts before the console transaction commits. Reproduced on both runtimes (expected `[]`, got
  `['from-console']`).
- **G1 BLOCKING** `packages/Dbal/tests/Integration/CoroutineMessageChannelTest.php:51,146,168,225`: the tests read
  `DATABASE_MYSQL`, which only docker-compose sets; the CI coroutine job sets `DATABASE_DSN` and
  `SECONDARY_DATABASE_DSN`, so the file fails there with a TypeError. The transaction boundary is proven on MySQL only.
- **G2 BLOCKING** `CoroutineMessageRelease.php:33-66`: no test interleaves two senders (A holds a message and suspends
  before its commit, B sends and fails; only A's message may be released).

## Should fix

- **V2** `CoroutineMessageChannel.php:62-63`: a delay is a plain `usleep` through the clock. Without `SWOOLE_HOOK_SLEEP`
  a delayed message or delayed retry freezes the worker (reproduced: a 400 ms delay stalled a sibling coroutine).
- **V3** `CoroutineMessageChannelTest.php:109-153`: the concurrency test passes with unbounded concurrency too; nothing
  proves "at most N", nor messages arriving while N handlers run.
- **V4**: no test of a send with no command bus, endpoint or gateway call around it being released at once.
- **V5**: untested combinations: `MultiTenantConfiguration` (does the handler use the tenant's connection),
  `#[Deduplicated]`, OpenTelemetry trace continuity, a handler sending to its own channel at `withCoroutines(1)`.
- **G3** `CoroutineChannelConsumer.php:44`, `CoroutineMessageChannelModule.php:70-86`: `PollingMetadata` stop-on-error,
  handled-message, time and memory limits for the channel are silently overridden; `#[Priority]` and `#[TimeToLive]`
  on its handlers are neither supported with a test nor refused.
- **G4**: release after commit is not proven for an event published through the Event Bus from a handler.
- **G5 / V6** `SymfonyConnectionModule.php:98`: nothing proves `withConnectionPoolWaitSeconds()` reaches the pool.
- **G6 / D6** `CoroutineMessageChannelModule.php:80`: the licence refusal names no place to put the key.
- **G7** `api/skills/ecotone-open-swoole/SKILL.md:3-11`: the description does not mention handlers run in the sending
  worker, which the `send-in-process` eval relies on.
- **G8**: the approved decisions are recorded only in the skill; no spec maps each to its test.
- **B3** `CoroutineMessageChannelBuilder.php:22-24,47-63`: surface that freezes at 2.0: an unbounded
  `maximumDelay()`, `isPollable(): true`, two public properties, a bare default of 10 repeated in a message.
- **D1** `CoroutineMessageChannelBuilder.php:24`, `ConnectionPoolExhausted.php:25`: the channel's default of 10 equals
  the default pool size; a burst takes every connection and requests fail. The exhausted message and the skill do not
  name the channel's limit.
- **D2** `CoroutineMessageFailureLogger`: "Coroutine message failed and was dropped." names no channel, no handler and
  no way to keep failed messages.
- **D3** `CoroutineMessageHoldingInterceptor`: the outside-a-coroutine refusal does not name the likely causes (console
  command, consumer without `--coroutines`, `enable_coroutine` off) nor that the sender's transaction is rolled back.
- **D4** `CoroutineMessageChannelModule.php`: an application's tests on PHP without the extension fail at bootstrap;
  neither the refusal nor the skill says how to register another channel under the name in tests.
- **D5** `SKILL.md:196`: "its transaction commits" is Ecotone's transaction; a transaction opened around the bus is
  not waited for. Nor does it say the handler starts when the request coroutine first yields or ends.

## Nit

- **B1** `upgrade-2.0.md:3073`: "after five seconds" where the wait is now configurable. **B2 / G13**: the 5 s default
  in two public constants. **G9**: `SymfonyConnectionReference::createForConnection(connectionPoolWaitSeconds: -1)`
  bypasses the Symfony guard. **G10**: see D3. **G11**: "coroutine channel" and "coroutine message channel" for one
  concept. **G12**: irregular blank lines in the three new test files and `CoroutineMessageChannel.php:112`.
  **D7** `CoroutineChannelConsumer`: "send to its asynchronous handlers instead" reads oddly. **D8**: the
  PollingMetadata refusal gives no reason. **D9** `SKILL.md:202-203`: the sender is described, not shown. **D10**: the
  eval grader is one long conjunction and does not ask for the pool to cover the channel. **V7**: a pending timer keeps
  a discarded application alive. **V8**: a sender on a fiber outside a coroutine is refused but untested.

## Verified by the behaviour session

New coroutine files: 28 tests on Swoole and on OpenSwoole; refusal file 3 tests in `app`; skill eval
`ecotone-open-swoole`: both cases 1.00. Found sound by reading: discard on failure, instant retry outside the release,
the failure logger outside the error channel, FIFO order, no WeakMap cycle.
