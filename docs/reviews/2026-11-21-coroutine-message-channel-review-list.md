# Guidelines review — coroutine message channel

Range `origin/main...HEAD` (origin/main a4ed79f8a, HEAD 4d95019cc, 60 branch commits plus one merge of origin/main).
Section: Guidelines (GL1–GL50) of `.agents/skills/framework-pull-request/references/review-list.md`.
Read only; no test or tool was run.

NOT PASSED: GL2, GL15, GL17, GL27, GL34, GL46

Counts: not-apply 12 · covered 32 · missing 6

## Purpose of the change

1. Enterprise `CoroutineMessageChannelBuilder::create('<name>')`: an `#[Asynchronous]` handler on it runs on a
   coroutine of the sending Swoole/OpenSwoole process, after the outermost command bus, gateway, asynchronous endpoint
   or console command succeeds and Ecotone's transaction commits. A failed sender starts nothing. Messages are held in
   memory, at most `withCoroutines()` (10 by default) run at once per channel, and failures go through the ordinary
   asynchronous pipeline (error channel, instant/delayed retry, dead letter), otherwise they are logged and dropped.
2. The coroutine wait for a pooled DBAL connection (fixed 5 s before) is set where the connection is registered:
   `connection_pool_wait_seconds`, `SymfonyConnectionReference::withConnectionPoolWaitSeconds()`, third argument of
   `DbalConnection::createWithCopiesForConcurrentExecutions()`; 0 fails at once.

## Inventory (every changed file, read in full)

| File | Why it changed |
|---|---|
| `packages/Ecotone/Api/ExtensionObject/CoroutineMessageChannelBuilder.php` | new public builder (Enterprise) |
| `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannel.php` | in-process queue that starts consumer coroutines, delays on the coroutine sleep |
| `.../Coroutine/CoroutineMessageChannelConsumer.php` | endpoint runner: refuses `ecotone:run`/`run()`, creates the in-process consumer |
| `.../Coroutine/CoroutineMessageChannelConsumerRegistration.php` | compiler pass: registers the sleep id, wraps the channel's endpoint runner |
| `.../Coroutine/CoroutineMessageChannelFailure.php`, `...FailureLogger.php` | ExecutionScope state + logging of dropped failures with channel, endpoint, headers |
| `.../Coroutine/CoroutineMessageChannelHoldingInterceptor.php`, `...HoldingInterceptorBuilder.php` | channel interceptor: refuses sends outside a coroutine, hands messages to the release |
| `.../Coroutine/CoroutineMessageChannelRelease.php`, `...ReleaseState.php` | around interceptor on the four boundaries; holds messages in ExecutionScope until the outer boundary returns |
| `.../Coroutine/CoroutineMessageChannelSleep.php` | coroutine-native sleep under its own service id |
| `.../Coroutine/CoroutineMessageChannelModule.php` | Enterprise module: bootstrap refusals, registrations, pinned-clock binding |
| `packages/Ecotone/src/Messaging/Config/ModuleClassList.php` | module added to `CORE_MODULES` |
| `packages/Dbal/Api/ConnectionPoolExhausted.php` | message names the channel limit and the configurable wait, 0-wait wording |
| `packages/Dbal/Api/DbalConnection.php`, `packages/Dbal/src/AlreadyConnectedDbalConnectionFactory.php` | pool wait argument for connection copies |
| `packages/Dbal/Api/DbalConnectionFactory.php` | `connection_pool_wait_seconds` key, non-numeric refusal |
| `packages/Dbal/src/Connection/ConnectionPool.php` | wait from constructor, negative refused, 0 fails at once |
| `packages/Symfony/Api/SymfonyConnectionReference.php`, `packages/Symfony/SymfonyBundle/Config/SymfonyConnectionModule.php` | `withConnectionPoolWaitSeconds()`, passed to the pool |
| `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php` | core behaviour on real coroutines |
| `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelRefusedTest.php` | refusals without licence/extension, replacement in tests |
| `packages/Ecotone/tests/Lite/Test/TestClockAtBootstrapTest.php` | pinned time leaves an application `SleepInterface` alone |
| `packages/Dbal/tests/Integration/CoroutineMessageChannelTest.php` | after-commit release per boundary, dead letter, tenancy, dedup, interleaved senders |
| `packages/Dbal/tests/Integration/CoroutineExecutionsTest.php`, `InterleavedExecutionsTest.php` | pool wait tests and updated message |
| `packages/Symfony/tests/phpunit/CoroutineConnectionPoolTest.php`, `SymfonyConnectionCopiesTest.php` | Symfony pool wait reaches the pool, refusals |
| `packages/OpenTelemetry/tests/Integration/CoroutineTracingTest.php` | trace continuity through the channel |
| `.github/workflows/test-monorepo.yml` | three files added to `COROUTINE_TESTS` |
| `upgrade-2.0.md` | pool wait now configurable |
| `api/skills/ecotone-open-swoole/SKILL.md` | §7 and rules 6–12 for the channel; pool wait in §2 |
| `api/evals/ecotone-open-swoole/send-in-process/*`, `consume-on-coroutines/graders/rule-1.md` | new eval case, graders per rule |
| `docs/superpowers/specs/2026-11-21-coroutine-message-channel-spec.md` | decisions mapped to tests |
| `docs/reviews/2026-11-21-coroutine-message-channel-pre-pr-review.md` | earlier rounds |

Skills whose requirements apply, and why: `framework-testing` (new tests), `framework-service-design` (new services,
module, constructor parameters, ExecutionScope state), `framework-public-api` (new `Api/` builder, changed `Api/`
signatures, messages), `framework-error-handling` (new refusals, new configuration), `framework-enterprise-licensing`
(Enterprise feature, new `src`/`Api` files), `framework-module-scaffolding` (new module), `framework-storage-and-transactions`
(DBAL pool and the transaction release boundary, although no table is added), `framework-design-and-briefs` (spec,
upgrade note), `framework-agent-tooling` (public skill rules, eval case), `framework-dev-environment` (phpstan
baseline), `framework-orientation` (names), `framework-pull-request` (commits).

## Missing

GL2 — missing — `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php:81-113` (no
      asynchronous-endpoint case) — "a failed sender starts nothing" is proved for the routed command bus
      (`test_a_failed_sender_starts_no_handler`), the gateway (`test_failed_gateway_discards_...`) and the console
      command (`test_failed_console_command_starts_no_handler`), but not for the fourth release boundary, an
      asynchronous endpoint (`AsynchronousRunningEndpoint` pointcut, `CoroutineMessageChannelModule.php:179`); the
      Dbal `sending_boundaries` dataset proves only its success path — add a test where an
      `#[Asynchronous('senders')]` handler on `SimpleMessageChannelBuilder::createQueueChannel('senders')` sends to
      the coroutine channel and throws, `run('senders')` inside `CoroutineTesting::runAtOnce()`, and the coroutine
      handler records nothing — skill: framework-testing 10a

GL15 — missing — `packages/Dbal/Api/DbalConnection.php:41` and
      `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelModule.php:88` — two calls the change
      adds with three positional arguments: `AlreadyConnectedDbalConnectionFactory::withCopiesForConcurrentExecutions($connection, $connectionPoolSize, $connectionPoolWaitSeconds)`
      and `ExtensionObjectResolver::resolveUnique(TestConfiguration::class, $extensions, TestConfiguration::createWithDefaults())`
      — name the arguments (`connection:`, `connectionPoolSize:`, `connectionPoolWaitSeconds:`; `className:`,
      `extensionObjects:`, `default:`) — skill: framework-service-design 18. Deterministic-check candidate (G5): a
      phpstan or php-cs-fixer rule can flag a non-variadic call with three or more positional arguments in `src`/`Api`.

GL17 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannel.php:47,64` — the concept
      is named "sleep" in the class (`CoroutineMessageChannelSleep`), its service id
      (`ecotone.coroutine_message_channel.sleep`) and the method (`sleep()`), but the channel's constructor parameter
      and property are `$timer`, left from the `CoroutineMessageChannelTimer` rename (G18 of round 2) — rename the
      parameter to `$sleep` — skill: framework-public-api 6a

GL27 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelModule.php:115-133` —
      of the `PollingMetadata` settings an application can set for the channel's endpoint id, coroutines, stop-on-error,
      handled-message, time, amount and memory limits are refused with a test and the error channel is supported with
      a test; the rest is neither: `withCron()`/`withCronExpression()`, `withFixedRateInMilliseconds()`/
      `withFixedRateExpression()` and `withInitialDelayInMilliseconds()` (they still build the trigger of this
      consumer, `InterceptedConsumerRunner.php:109-120`, so an initial delay would postpone every in-process start),
      `withMaxMessagePerPoll()`, `withEnabledErrorChannel(false)`, `withFinishWhenNoMessages()`,
      `withTriggerReferenceName()`, `withTaskExecutorName()`, `withConnectionRetryTemplate()`,
      `withSignalInterceptors()`, `withPolledChannelName()`, `withTestingSetup()`; likewise `#[Asynchronous]` listing
      the coroutine channel together with another channel — for each, refuse it in `refuseConsumerLimits()` with a row
      in `unsupported_polling_limits`, or add a test proving it is honoured (e.g. `withEnabledErrorChannel(false)`
      logs and drops) — skill: framework-error-handling 1d

GL34 — missing — `docs/reviews/2026-11-21-coroutine-message-channel-pre-pr-review.md` (round 2, "G1 (rest): MariaDB
      is not part of the coroutine CI job", never resolved) — the change touches DBAL (pool wait) and the transaction
      boundary (release after commit); MySQL and PostgreSQL runs are recorded, no MariaDB run is listed for HEAD
      (the uncommitted `var/coroutine-channel-mariadb-*.log` show 6 tests from an early commit) — run
      `packages/Dbal/tests/Integration/CoroutineMessageChannelTest.php` and the pool-wait tests of
      `CoroutineExecutionsTest.php` on MariaDB in the `swoole` and `openswoole` containers and list the runs in the
      review record — skill: framework-storage-and-transactions 16c (with framework-dev-environment for the DSNs)

GL46 — missing — `api/evals/ecotone-open-swoole/send-in-process/graders/rule-9.md`, `rule-10.md`, `rule-11.md`
      (changed in 17855865f) — the last reported scores of `send-in-process` (review record, round 4, both cases 1.00)
      predate the rubric rewrite; nothing reports a run of the current graders (`var/rv6-skills.log`, this round's run,
      shows only `consume-on-coroutines` at the time of writing) — run `bin/test-agent-skills ecotone-open-swoole` on
      HEAD and record both cases' scores; covered as soon as that run is recorded — skill: framework-agent-tooling A4

## Covered

GL1 — covered — every new or changed test boots `EcotoneLite::bootstrapFlowTesting()` and asserts through handlers,
      gateways (`DeadLetterGateway`), console commands and exception messages; SQL only on the tests' own
      `coroutine_channel_*` tables; no reflection. Non-`Api` names are the repository's sanctioned ones: the thrown
      `ConfigurationException`/`LicensingException` the application observes (142 test files already name it; no
      `Api/` counterpart exists, pre-existing), `Ecotone\Test\LicenceTesting` (named by framework-testing rules.md) and
      `Ecotone\Test\CoroutineTesting` (the framework's coroutine test support)
GL3 — covered — consumption through `run()`/the bus; real waits only where real coroutine concurrency is the subject:
      pool-wait tests (`CoroutineExecutionsTest.php:293-358`, same `usleep(50_000)` ordering as the file's existing
      tests on main), `test_delayed_message_with_hooks_off_does_not_stall_a_sibling_coroutine` (named for the runtime
      hooks it exercises); no fork, process, socket or wall-clock loop
GL4 — covered — snake_case behaviour names, no comments/docblocks/assertion messages (only the `licence Apache-2.0
      @internal` convention), anonymous fixtures; named classes only where PHP forces them:
      `CoroutineChannelCommand` (type declaration), `CoroutineChannelGateway`/`CoroutineOrderGateway` (gateway interfaces)
GL5 — covered — every test sends through the bus/gateway/console to `#[Asynchronous]` handlers; `run()` is refused for
      this channel by design and its refusal is tested; the replacement test drives `run('async')`
      (`CoroutineMessageChannelRefusedTest.php:51-76`); the direct-send test was removed (G16)
GL6 — covered — delay tests pin `initialTime` and assert exact `12:00:00.500000` (`CoroutineMessageChannelTest.php:246-300,473-499`)
GL7 — covered — every new service parameter is required (`CoroutineMessageChannel.php:47`, `CoroutineMessageChannelConsumer.php:33`,
      `CoroutineMessageChannelFailureLogger.php:35`, `ConnectionPool.php:32`); defaults only on configuration values
GL8 — covered — held messages and failed-endpoint state via `ExecutionScope::stateOf()` (`CoroutineMessageChannelRelease.php:65`,
      `CoroutineMessageChannelFailureLogger.php:69`), proved interleaved by `test_interleaved_senders_release_only_the_successful_senders_message`
      and `test_failure_logs_keep_each_coroutines_endpoint_and_headers`; `CoroutineMessageChannel::$consumers` is
      process state (running consumer coroutines of the channel), the rule 3a exception, proved by
      `test_concurrency_is_limited_and_waiting_messages_start_in_order`
GL9 — covered — `hold()` and `release()` are named for their mutation; `send()`, `process()` return void; no getter mutates
GL11 — covered — extension object from `#[ServiceContext]` (`test_class_routed_objects_keep_their_identity_with_service_context_registration`),
      `SymfonyConnectionReference` method, DBAL config key; everything compiled to `Definition`s
GL12 — covered — handler metadata via `InterfaceToCallRegistry::getFor()->hasAnnotation()` (`CoroutineMessageChannelModule.php:159-161`); no reflection
GL13 — covered — `prepare()` is a step list (`CoroutineMessageChannelModule.php:70-84`); no SQL in `src`
GL14 — covered — all 11 new `src` files, the `Api` builder and the new test classes are `final` with `strict_types=1`
GL16 — covered — no inline comment or prose docblock in added code; the one added docblock line extends the existing
      config-array shape of `DbalConnectionFactory.php:39`
GL18 — covered — `CoroutineMessageChannelBuilder`: private constructor, promoted readonly properties, `withCoroutines()`
      returns `new self(...[...get_object_vars($this), ...])`, the one-sentence Enterprise marker; `getMessageChannelName()`
      is required by `MessageChannelBuilder`. `SymfonyConnectionReference`: promoted `public readonly $connectionPoolWaitSeconds`,
      `with*()` via named `new self(...)` (parent holds state). Pending maintainer decisions, not counted either way:
      the public `$messageChannelName`, the default of 10 (`DEFAULT_COROUTINES`), the unbounded `maximumDelay()`
GL19 — covered — the builder is at `packages/Ecotone/Api/ExtensionObject/`
GL20 — covered — application-facing signatures take scalars and `Api` types; `compile(MessagingContainerBuilder)` is the
      framework-called interface method; nothing of the module or container is under `Api/`. Pending maintainer
      decision: the two public pool-wait default constants (`ConnectionPool::DEFAULT_WAIT_SECONDS` used as the default
      in `DbalConnection.php:39`, and `SymfonyConnectionReference::DEFAULT_CONNECTION_POOL_WAIT_SECONDS`)
GL23 — covered — every new message names the thing, the reason and the ways out, each asserted in full:
      `CoroutineMessageChannelTest` (outside-coroutine, consumer start ×2, polling coroutines, consumer limits,
      Priority/TimeToLive, fiber, drop log), `CoroutineMessageChannelRefusedTest` (licence, extension, zero coroutines),
      `InterleavedExecutionsTest::test_connection_pool_wait_that_is_not_a_number_is_refused`, `test_negative_connection_pool_wait_is_refused`,
      the exhausted message `InterleavedExecutionsTest.php:288-293`, `SymfonyConnectionCopiesTest.php:71-97`
GL24 — covered — builder and Symfony reference refuse when configured; licence, extension, polling settings and
      delivery attributes in `prepare()`; the pool-wait guard sits beside the pre-existing pool-size guard in the
      connection factory constructor
GL25 — covered — channel names reach the interceptor, consumer and logger through their `Definition`s
GL26 — covered — Priority/TimeToLive and PollingMetadata checks run in `prepare()`; the only runtime check is the
      coroutine context of a send, which is not a signature or placement
GL36 — covered — the only connection code changed is `ConnectionPool`'s wait
      (`ConnectionPool.php:153-166`), the class that owns pooled connections; handlers take `#[CurrentConnection]`
GL37 — covered — every new `src` file carries the full Enterprise notice, the `Api` builder the one-sentence form;
      the module's unlicensed refusal is the negative guard of an Enterprise file (known-deviations, row on
      `FetchAggregateConverter`); open-source files changed (DBAL pool, Symfony reference, `ModuleClassList`) hold no
      licence branch, and `EcotoneTestSupportModule` is back to its base state (G21, 70f37a479)
GL38 — covered — `ModuleClassList::CORE_MODULES` names `CoroutineMessageChannelModule` (`ModuleClassList.php:129`)
GL40 — covered — no test names or instantiates the module; all boot `EcotoneLite`
GL42 — covered — every decision in the spec table names tests, and every named test exists in the tree
GL43 — covered — `upgrade-2.0.md:3056-3057,3073-3074` states the configurable wait; the channel is new in 2.0 and
      beta-to-beta needs no entry
GL44 — covered — skill rules 6–12 judge an agent's answer, which needs judgement (llm graders); no new repository
      convention a program could decide
GL45 — covered — the rules sit in `api/skills/ecotone-open-swoole`, whose *Applies when* gained "Running
      asynchronous handlers in the sending HTTP worker without a broker"
GL47 — covered — `send-in-process/prompt.md` is a developer's task, names neither the builder nor a rule
GL48 — covered — no `phpstan-baseline.neon` in the diff
GL49 — covered — every name in code, messages, the skill and the spec resolves in the tree (`PollingMetadata::with*`
      of the refusals, `ConfiguredMessagingSystem::run()`, `Environment`, `CurrentConnection`,
      `SimpleMessageChannelBuilder::createQueueChannel()`, `withConnectionPoolSize()`, `FlowTestSupport::whenWaiting()`)
GL50 — covered — all 60 branch commits match `type(scope): ` and their bodies state what broke and why; the one
      merge commit (4d95019cc) syncs origin/main and is no increment of the change

## Not apply

GL10 — not-apply — the change adds no interface in `src`/`Api` (the two test interfaces are gateways)
GL21 to GL22 — not-apply — no console option and no message header added
GL28 to GL33 — not-apply — no table, versioned write, storage, DDL or database write added; failed messages reach the
      existing DBAL dead letter
GL35 — not-apply — no storage code or console command opens a transaction (no `transactional()`/`beginTransaction()` added)
GL39 — not-apply — no package added
GL41 — not-apply — the spec proposes no table or index

## Not on the list

- Blank-line irregularities in new tests: no blank line between methods at `CoroutineMessageChannelTest.php:80-81`
  and `CoroutineMessageChannelRefusedTest.php:31-32`, a trailing blank line at `CoroutineMessageChannelRefusedTest.php:77`,
  double blank lines at `CoroutineTracingTest.php:147` and `SymfonyConnectionCopiesTest.php:671` (php-cs-fixer would decide).
- `CoroutineMessageChannelTest.php:148-149` calls `$default->withCoroutines(1)` and discards the result; it reads as a
  leftover unless it is meant to prove immutability, which nothing asserts.
PASSED

Breaking-changes review of `git diff origin/main...HEAD` at 4d95019cc (origin/main a4ed79f8a).

Release (B1): latest tag `2.0.0-beta.10`, branch alias `dev-main: 2.0.x-dev` (packages/Ecotone/composer.json:86).
The change ships in the 2.0.0 major (a pre-release of it), so storage and `Api/` may change; no compatibility is
owed between beta states. Every requirement below is still judged against 1.x→2.0 and against the latest beta.

BC3 — covered — every changed public signature is additive: new final class
`packages/Ecotone/Api/ExtensionObject/CoroutineMessageChannelBuilder.php` (`create()`, `withCoroutines()`,
`DEFAULT_COROUTINES`); `DbalConnection::createWithCopiesForConcurrentExecutions()` gains an optional last parameter
`$connectionPoolWaitSeconds` (packages/Dbal/Api/DbalConnection.php:39); `SymfonyConnectionReference::createForConnection()`
gains an optional last parameter (packages/Symfony/Api/SymfonyConnectionReference.php:46), a new method
`withConnectionPoolWaitSeconds()` on a final class (:74), a new public readonly property and constant (:22, :29;
private constructor, new parameter last); `DbalConnectionFactory` gains an optional config key
`connection_pool_wait_seconds` (packages/Dbal/Api/DbalConnectionFactory.php:69), constructor signature unchanged;
`ConnectionPoolExhausted::withAllConnectionsInUse()` signature unchanged, only its message text changed (same
exception class). Existing parameter names untouched. Required parameters were added only to internal `src/`
classes (`ConnectionPool::__construct`, packages/Dbal/src/Connection/ConnectionPool.php:32;
`AlreadyConnectedDbalConnectionFactory::withCopiesForConcurrentExecutions`, packages/Dbal/src/AlreadyConnectedDbalConnectionFactory.php:31),
and all their callers in `packages/*/src|Api|SymfonyBundle` are updated (DbalConnectionFactory.php:78,
DbalConnection.php:41, SymfonyConnectionModule.php:98). A Symfony container dumped before the change calls
`createForConnection` with three arguments and gets the default wait. Pending with the maintainer, not counted
either way: the public `$messageChannelName` and the unbounded `maximumDelay(): null`
(CoroutineMessageChannelBuilder.php:27, :63) freeze into the 2.0 surface; `ConnectionPool::DEFAULT_WAIT_SECONDS`
(ConnectionPool.php:21, in `src/`, referenced from `Api/` defaults) and
`SymfonyConnectionReference::DEFAULT_CONNECTION_POOL_WAIT_SECONDS` (:22) hold the same 5.0 in two public constants.

BC4 — covered — the pool wait was the private `SECONDS_WAITING_FOR_RETURNED_CONNECTION = 5.0`; the new defaults are
5.0 at every entry point (ConnectionPool.php:21, DbalConnectionFactory.php:69, DbalConnection.php:39,
SymfonyConnectionReference.php:22/:29/:46). No other default changed. Pending with the maintainer, not counted:
the default of 10 coroutines per channel (CoroutineMessageChannelBuilder.php:25) is a new default, not a changed one.

BC6 — covered — the only change existing code can notice is that the pool wait is configurable and 0 now fails at
once; upgrade-2.0.md:3056-3057 states the option, its default and `withConnectionPoolWaitSeconds()`, and
upgrade-2.0.md:3073 replaces "after five seconds" with the configured wait. The coroutine message channel is new and
inert unless registered: `CoroutineMessageChannelModule::prepare()` returns before registering any interceptor,
service or compiler pass when no `CoroutineMessageChannelBuilder` is configured
(packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelModule.php:73-74), so its registration in
`ModuleClassList` changes nothing for applications that do not use it. The longer `ConnectionPoolExhausted` message
keeps the exception type.

BC1 — not-apply — nothing is written to a table, queue, outbox, dead letter or event store in a new form: the
channel holds messages in worker memory (CoroutineMessageChannel.php), it only reads existing headers
(`DELIVERY_DELAY`, `POLLED_CHANNEL_NAME`, `ErrorContext::DLQ_MESSAGE_REPLIED`) and adds no new header; failed messages
reach an error channel or dead letter through the existing asynchronous-endpoint path with the existing headers; no
table, column or event-store metadata key is touched.

BC2 — not-apply — no stored form changes (see BC1).

BC5 — not-apply — no console command name or option is added or changed; `ConsoleCommand` appears only as an
interceptor pointcut (CoroutineMessageChannelModule.php registerRelease).

BC7 — not-apply — the release is a major; besides, both features are opt-in (the channel only when registered, the
wait keeps its old 5 s default).

BC8 — not-apply — nothing shared between processes changes: coroutine-channel messages never leave the worker's
memory, and the pool wait is per-process runtime configuration with no persisted form.

Counts: not-apply 5 (BC1, BC2, BC5, BC7, BC8) / covered 3 (BC3, BC4, BC6) / missing 0.
# Developer experience review: coroutine message channel

Reviewed at 4d95019cc, range `origin/main...HEAD` (61 commits), by the developer experience session
(`framework-review-developer-experience`, review list section Developer experience).

NOT PASSED: DX1

## End-user code for the most common case (X1)

```php
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Environment;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;

final class NotificationChannels
{
    #[ServiceContext]
    #[Environment(['prod', 'dev'])]               // only so extension-free / non-coroutine tests can swap it
    public function notifications(): CoroutineMessageChannelBuilder
    {
        return CoroutineMessageChannelBuilder::create('notifications');   // ->withCoroutines(4) optional
    }
}

final class Notifications
{
    #[Asynchronous('notifications')]
    #[CommandHandler(endpointId: 'notifications.send')]
    public function send(SendNotification $command): void { /* ... */ }
}

// sender: any command handler / controller calling CommandBus::send(new SendNotification(...))
// pool: new DbalConnectionFactory(['dsn' => $dsn, 'connection_pool_size' => requests + channel limits])
// test: bootstrapFlowTesting(..., extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('notifications')],
//       environment: 'test'); $flow->run('notifications');
```

What it requires and why:
- `CoroutineMessageChannelBuilder::create(name)` from `#[ServiceContext]`: the channel name is the one thing the
  framework cannot know; same shape as every other channel builder.
- `#[Asynchronous(name)]` + endpointId: the existing asynchronous shape, unchanged.
- Enterprise licence + swoole/openswoole with coroutines on (`enable_coroutine=true`): runtime prerequisites, refused
  at bootstrap / send with the way out.
- `connection_pool_size` sized to requests + channel limits: needed because each running handler takes a pooled
  connection; the framework cannot know the request concurrency. Stated in skill §7 and in `ConnectionPoolExhausted`.
- `#[Environment([...])]` + queue channel in tests: needed because flow tests do not run inside a coroutine.
- Pool wait (`connection_pool_wait_seconds`): not needed in the common case (default 5 s, as before).

No consumer process, no `ecotone:run`, no hand-registered service: nothing beyond the above.

## Missing

DX1 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelHoldingInterceptor.php:41`
(asserted in `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php:224`) — the testing step of
the common case. A developer whose test PHP has the extension (the usual case for a Swoole application: same image
in CI) passes the missing-extension bootstrap check, and the first `sendCommand()` from PHPUnit hits this
outside-a-coroutine refusal. Since bba4d6d40 (the D18 fix) its only testing advice is "register
SimpleMessageChannelBuilder::createQueueChannel('x') under this name instead"; doing that beside the production
`#[ServiceContext]` registration fails with "Trying to register message channel with name `x` twice."
(`MessagingSystemConfiguration.php:954`), and the `#[Environment]` advice that makes "instead" possible is only in
the missing-extension refusal (`CoroutineMessageChannelModule.php:110`), which this developer never sees. This is the
D11 situation coming back. Add — briefly, without repeating the whole missing-extension text — how to replace the
registration: e.g. "In tests, limit the #[ServiceContext] method returning CoroutineMessageChannelBuilder to the
environments that run on Swoole with #[Environment(['prod', 'dev'])] and register
SimpleMessageChannelBuilder::createQueueChannel('x') in the test environment, then call run('x')." (Or point at the
`ecotone-open-swoole` skill §7.) Update the expected message in the test. — skill: `framework-error-handling`

## Covered

DX2 — covered — switched on declaratively: `CoroutineMessageChannelBuilder::create()` returned from `#[ServiceContext]`
(`packages/Ecotone/Api/ExtensionObject/CoroutineMessageChannelBuilder.php:31`) plus the existing
`#[Asynchronous]`, the same shape as `SimpleMessageChannelBuilder` / DBAL channel builders; the release interceptors,
consumer and failure logger are wired by `CoroutineMessageChannelModule` (`test_class_routed_objects_keep_their_identity_with_service_context_registration`,
`test_handler_runs_on_a_coroutine_of_the_sending_process`). The pool wait is one array key / one `with*()` beside
the existing pool size. Observation, not counted: a sending `#[ConsoleCommand]` must be run inside a coroutine by
the user (no Ecotone switch for it); it is not the common case and the refusal and skill §7 say so.

DX3 — covered — each setting is stated once: concurrency only on the builder (`PollingMetadata::withCoroutines()` for
the channel is refused, `CoroutineMessageChannelModule.php:103`, `test_polling_concurrency_is_refused_at_bootstrap`);
the channel name appears in the builder and `#[Asynchronous]` as for every channel; the pool wait is set once where
the connection is registered (`DbalConnectionFactory.php:69`, `SymfonyConnectionReference.php:74`, or the third
argument of `DbalConnection::createWithCopiesForConcurrentExecutions()`). `createForConnection(..., connectionPoolWaitSeconds:)`
and `withConnectionPoolWaitSeconds()` are two entry points to one value, mirroring the existing pool-size pair.

DX4 — covered — the only required value is the channel name (unknowable); `withCoroutines()` defaults to 10
(`CoroutineMessageChannelBuilder.php:25`, `test_concurrency_is_limited_and_waiting_messages_start_in_order`), the pool
wait defaults to 5 s, the value it had before (`ConnectionPool.php:21`, `upgrade-2.0.md:3056`). Pending maintainer
decision, not counted either way: the default of 10 coroutines per channel (equal to the default pool size of 10, so
a full channel plus one request exhausts a default pool; skill §7 lines 246-253 and `ConnectionPoolExhausted` tell
the user to size the pool for it).

DX5 — covered — each option has a use: `withCoroutines()` bounds connection and provider load (skill §7 example);
`connection_pool_wait_seconds` lets a latency-sensitive HTTP worker fail fast (0) or a bursty one wait longer
(`test_coroutine_waits_for_a_connection_for_as_long_as_the_pool_is_configured_to`,
`test_coroutine_is_refused_at_once_when_the_pool_is_configured_not_to_wait`,
`test_symfony_zero_pool_wait_refuses_a_second_coroutine_at_once`). The builder exposes no other option.

DX6 — covered — neighbouring defaults named: pool wait 5 s = the previous fixed wait; channel limit 10 = pool size
10 = consumer coroutine conventions (pending, see DX4); final failure is drop-and-log where `SimpleMessageChannelBuilder`
queues default to `RESEND` — the difference is justified, since resending inside the same in-memory process would
re-run a failing message in a loop while holding a coroutine slot, and it is the behaviour the reporter asked for.
Pending maintainer decision, not counted: two public constants holding the 5 s default
(`ConnectionPool::DEFAULT_WAIT_SECONDS`, `SymfonyConnectionReference::DEFAULT_CONNECTION_POOL_WAIT_SECONDS`), and the
unbounded `maximumDelay()` (`CoroutineMessageChannelBuilder.php:64`).

DX7 — covered — per default: (a) release only after the outermost bus/endpoint/gateway/console command succeeds and
Ecotone commits; a failed sender starts nothing (Dbal `test_handler_starts_after_the_sender_commits_and_sees_its_data`,
`test_a_failed_sender_starts_no_handler`) — safe side. (b) Without an error channel a final failure is logged at error
level with channel, endpoint, exception and headers and names how to keep failures, then dropped
(`CoroutineMessageChannelFailureLogger.php:59`, `test_failed_messages_are_logged_with_headers_and_dropped_while_others_continue`);
the failure is not hidden, the error channel / retries / dead letter of any asynchronous endpoint apply when
configured, and dropping is the reporter's stated requirement for this best-effort channel. (c) Pool wait 5 s, not 0
— waiting is the safe side. (d) Sending outside a coroutine is refused, not run synchronously or dropped, and rolls
back the sender (`test_sending_outside_a_coroutine_is_refused`). (e) Unsupported `#[Priority]`, `#[TimeToLive]` and
polling limits are refused, not ignored (`test_unsupported_delivery_attributes_are_refused_at_bootstrap`,
`test_polling_consumer_limits_are_refused_at_bootstrap`).

DX8 — covered — the less safe behaviour (messages in worker memory, lost on crash/restart, no shutdown draining) is
an explicit opt-in: the developer picks `CoroutineMessageChannelBuilder` instead of a durable channel for the
handler; the name says it runs on coroutines of the process, the Enterprise licence and extension are refused
without it, and skill §7 (line 289) states the loss and to use a durable channel for work that must survive.
Failing fast on the pool is opt-in by an explicit `0` whose meaning is in the name (`..._wait_seconds`).

DX9 — covered — names read without the implementation: `CoroutineMessageChannelBuilder` (follows the `*MessageChannelBuilder`
family), `create(messageChannelName)`, `withCoroutines()`, `connection_pool_wait_seconds` /
`withConnectionPoolWaitSeconds()` / `connectionPoolWaitSeconds` (unit in the name, sits beside `connection_pool_size`);
no abbreviation, no negated boolean. Refusals name the channel, the handler and the way out
(`CoroutineMessageChannelModule.php:103,107,110,131,166`, `CoroutineMessageChannelConsumer.php:39`).
Pending maintainer decision, not counted: the public `$messageChannelName` property (`CoroutineMessageChannelBuilder.php:27`).

DX10 — covered — searched: `withCoroutines` / `--coroutines` already name concurrency on `PollingMetadata` and
`ecotone:run`; "message channel builder" is the existing term (`SimpleMessageChannelBuilder`,
`DbalBackedMessageChannelBuilder`); `connection_pool_*` follows `connection_pool_size`; "coroutine message channel"
is used consistently in code, refusals, skill and upgrade note (G11 resolved); `ecotone-open-swoole` and
`ecotone-asynchronous` skills use the same words (`#[Asynchronous]`, error channel, dead letter, delayed retry).

## Not-apply

None.

Counts: not-apply 0, covered 9, missing 1.
# Behaviour review: coroutine message channel and configurable pool wait

Reviewed at 4d95019cc (`git diff origin/main...HEAD`, origin/main a4ed79f8a), by the behaviour session (rules V1-V9 of
`framework-review-behaviour`, Behaviour section BH1-BH21 of the review list).

NOT PASSED: BH17, BH14, BH13

Count: not-apply 1, covered 17, missing 3.

## Problem and outcome (V1)

1. On Swoole/OpenSwoole, a handler marked `#[Asynchronous('notifications')]` on a channel registered with
   `CoroutineMessageChannelBuilder::create('notifications')` runs on a coroutine of the sending process, after the
   sender succeeded and Ecotone committed; a failed sender starts nothing; at most `withCoroutines()` handlers run at
   once per channel and process, the rest wait in order; failures go through the ordinary asynchronous pipeline
   (error channel, instant and delayed retry, dead letter) or are logged and dropped. Observable: the HTTP request
   (a coroutine) returns before the handler finishes, the handler sees the committed row, and every message sent is
   eventually handled.
2. The pool wait is set where the connection is registered; 0 fails at once. Observable: `ConnectionPoolExhausted`
   after the configured wait, at once for 0, through all three registrations.

Trace: `#[ServiceContext]` builder -> `CoroutineMessageChannelBuilder::compile()` -> `CoroutineMessageChannel`;
`CoroutineMessageChannelModule::prepare()` registers `CoroutineMessageChannelRelease::release()` around CommandBus,
AsynchronousRunningEndpoint, MessageGateway and ConsoleCommand between instant retry and transaction, and the
holding channel interceptor (`preSend`) that holds every send to the channel while a boundary is active in this
`ExecutionScope`; after the outermost boundary returns the messages go to `CoroutineMessageChannel::send()`, which
enqueues and starts up to N consumer coroutines through `Event::defer` + `ConsumerCoroutines::consume()`. Part 2:
`connection_pool_wait_seconds` / `withConnectionPoolWaitSeconds()` / third argument -> `ConnectionPool` -> `pop($wait)`
or an immediate refusal. Both reach the outcome, except the liveness of point 1 ("every message sent is eventually
handled"), which BH17 below breaks.

## Missing

BH13 — missing — `api/evals/ecotone-open-swoole/send-in-process` — not evaluated on this head: `bin/test-agent-skills ecotone-open-swoole` (var/rv6-skills.log) scored consume-on-coroutines 1.00, but every send-in-process grader threw `judge call failed: You've hit your 5-hour limit` (score 0.13 is the skill-loaded grader only), so no score proves the case. Rerun `bin/test-agent-skills ecotone-open-swoole` after the limit resets. Every new or changed rule has a grader (rule-6..12, rule-1). — skill: `framework-agent-tooling`.

BH17 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannel.php:82-98` (with
`packages/Ecotone/src/Messaging/Endpoint/PollingConsumer/InterceptedConsumer.php:80-92`) — the channel decides from
the shared counter `$this->consumers` whether a consumer coroutine will pick up a new message, but a consumer coroutine
that has already seen the queue empty (its `FinishWhenNoMessagesInterceptor` set to stop) still runs
`onConsumerStop()` and sends to `OnConsumerStop::CONSUMER_STOP_CHANNEL_NAME` before `$this->consumers--` in the
`finally`. When anything there waits (an application `#[OnConsumerStop]` handler doing I/O, a gateway interceptor
exporting spans), execution A is in that wait while execution B releases a message: B reads
`consumers >= coroutines`, enqueues and starts nothing; A then ends without receiving again. The message stays in the
queue until the next send to the channel, and is never handled if none comes. Reproduced on this head with
`withCoroutines(1)`, a `#[OnConsumerStop]` handler that sleeps 50 ms, sender 1 at 0 ms and sender 2 at 20 ms: Swoole
`{"handled":["first"]}` (second never handled, `var/rv6-race-swoole.log`); OpenSwoole with a third send at 200 ms:
`["first","second","third"]`, the second handled only when the third arrived (`var/rv6-race-openswoole.log`). With N
coroutines it needs all N consumers in that window at once, so it is rarer but the same. Fix: after the consumer
coroutine ends and `consumers--` runs, start another consumer when the queue is not empty and the limit allows (or
decrement before the stop hooks and re-check); test to add in `CoroutineMessageChannelTest.php`:
`test_a_message_released_while_the_last_consumer_stops_is_handled` — `withCoroutines(1)`, an `#[OnConsumerStop]`
handler that yields (a `Coroutine\Channel` pop released by the test, not a timed sleep), a second sender released
during that yield, assert both payloads handled. — skill: `framework-service-design` (rule 3a, read-wait-write),
`framework-testing`.

BH14 — missing — `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php` — two combinations are
neither tested nor refused: (a) `#[OnConsumerStop]` (public `Ecotone\Api\Attribute\OnConsumerStop`): every time a
coroutine message channel's consumer coroutine runs out of messages, an application's `#[OnConsumerStop]` handler now
runs inside the HTTP worker, once per drained burst and per consumer coroutine, with the channel name as endpoint id;
a handler written for consumer-process shutdown (closing connections, flushing buffers) then runs during live requests,
and a yielding one opens the BH17 window. Either assert that behaviour in a test and say it in the skill, or keep the
channel's internal consumer from publishing the stop event; (b) aggregates, sagas and event-sourced aggregates: no
test has an aggregate or a saga as sender or as handler on the channel; add one test where an event-sourced aggregate's
recorded event reaches an `#[Asynchronous]` saga or event handler on the coroutine channel and starts after the
aggregate's command commits. The hold sits in the channel's `preSend`, so by reading every send inside a boundary is
held, but the rule asks for the test. — skill: `framework-testing`, `framework-error-handling`.

## Covered

BH1 — covered — trace above; every outcome of point 1 and 2 is reached and tested except the liveness gap filed as
BH17 (decided there, not twice).

BH2 — covered — red-first evidence handed over: `/tmp/implement-coroutine-message-channel-report.txt` lines 13-28
(release after commit `[[true, 0]]` red, runs in sending process, concurrency limit, failure logging, outside a
coroutine, delays, non-blocking return, DLQ replay, licence and extension refusals),
`/tmp/coroutine-message-channel-review-fixes-report.txt` lines 50-60 (console release, interleaved senders, hooks-off
delay, mutation reds for the Symfony pool wait `var/review-g5-mutation-red.log`, `var/review-g9-red.log`),
`/tmp/coroutine-channel-round-2-fixes-report.txt`, `var/r3-report.txt` (`var/r3-red-*.log`), `var/r4-report.txt`.
Tests that passed either way are listed as such by the implementer (conformance: `test_a_failed_sender_starts_no_handler`,
`test_instant_retry_runs_in_the_ordinary_asynchronous_consumer`, the gateway/asynchronous datasets, etc.) and each
covers a behaviour reused from the asynchronous pipeline, not a claim the change adds. Pool wait (a734e9fd6, tests and
code in one commit): red on the base by the diff — the base ignores `connection_pool_wait_seconds` and waits 5 s, so in
`test_coroutine_waits_for_a_connection_for_as_long_as_the_pool_is_configured_to` and the two `..._not_to_wait` tests the
second coroutine gets the connection after 0.6 s and `$refusal` stays null; the message assertions name text the base
does not have.

BH3 — covered — command bus routed (`test_handler_runs_on_a_coroutine_of_the_sending_process`), by class
(`test_class_routed_objects_keep_their_identity_with_service_context_registration`), event bus, gateway, console
command, asynchronous endpoint as sender (Dbal `test_handler_starts_after_the_sender_commits_and_sees_its_data`
datasets event/gateway/console/asynchronous/command_bus, also `_on_postgresql`), failed gateway
(`test_failed_gateway_discards_messages_and_successful_gateway_releases_them`), failed console
(`test_failed_console_command_starts_no_handler`), DLQ replay path (Dbal
`test_failed_message_goes_to_dbal_dead_letter_and_replays_outside_a_coroutine`), consumer start refused
(`test_starting_a_consumer_is_refused`, `test_console_consumer_start_is_refused`). QueryBus carries MessageGateway (in
the pointcut, same path as the gateway test). A direct send to the channel object is handed over at once (spec
decision, no userland entry). Pool wait: DSN array (Dbal `test_coroutine_waits_for_a_connection_for_as_long_as_the_pool_is_configured_to`,
`test_coroutine_is_refused_at_once_when_the_pool_is_configured_not_to_wait`), copies
(`test_coroutine_on_copies_of_an_application_connection_is_refused_at_once_when_they_are_configured_not_to_wait`),
Symfony (`CoroutineConnectionPoolTest::test_symfony_zero_pool_wait_refuses_a_second_coroutine_at_once`). Laravel has
no pool, nothing to configure.

BH4 — covered — the channel stores nothing (in-memory queue, no DBAL storage, explicit). The transaction boundary is
engine-sensitive and runs on MySQL (both runtimes) and PostgreSQL (Swoole; OpenSwoole refuses coroutine PostgreSQL),
in both CI DSN orders; MariaDB was run by the implementers (`var/review2-final-mariadb-*.log`) and is not in the
coroutine CI job (recorded earlier as G1 rest, nit). Pool wait: MySQL in the coroutine tests; the wait is engine-neutral.

BH5 — covered — sender throws (`test_a_failed_sender_starts_no_handler`), console throws
(`test_failed_console_command_starts_no_handler`), rollback with caught inner failure (Dbal
`test_caught_inner_failure_commits_its_rows_and_releases_both_commands_messages_with_the_outer_command`), retried
sender (`test_a_retried_sender_discards_messages_from_its_failed_attempt`,
`test_retried_outer_command_discards_messages_from_its_failed_nested_call`), handler throws: logged and dropped
(`test_failed_messages_are_logged_with_headers_and_dropped_while_others_continue`), error channels
(`test_handler_scoped_error_channel_receives_the_failure_and_headers`,
`test_polling_metadata_error_channel_receives_the_failure_and_headers`), instant and delayed retry
(`test_instant_retry_runs_in_the_ordinary_asynchronous_consumer`, `test_delayed_retry_returns_to_the_coroutine_channel_after_its_delay`),
dead letter (Dbal DLQ test); pool exhausted (the three pool-wait tests).

BH6 — covered — a retried sender's first attempt releases nothing (`test_a_retried_sender_discards_messages_from_its_failed_attempt`);
the same deduplication key handled once (Dbal `test_deduplicated_handler_runs_once_for_the_same_deduplication_key`);
DLQ replay handles the message once (Dbal DLQ test).

BH7 — covered — two senders interleaved (Dbal `test_interleaved_senders_release_only_the_successful_senders_message`),
concurrent handlers own headers and transaction (Dbal `test_concurrent_messages_keep_their_own_headers_and_transaction`),
limit with messages arriving while N run (`test_concurrency_is_limited_and_waiting_messages_start_in_order`, datasets
2/8 and 10/15), tenants (Dbal `test_each_message_uses_its_tenants_connection`). The gap at the consumer's exit is BH17.

BH8 — covered — no delay header (every non-delayed test), a boundary that holds nothing releases nothing
(`test_failed_console_command_starts_no_handler`), `withCoroutines(0)` refused
(`CoroutineMessageChannelRefusedTest::test_a_channel_requires_at_least_one_coroutine`), wait 0
(`test_coroutine_is_refused_at_once_when_the_pool_is_configured_not_to_wait`), a past absolute delay is not delayed
(the `DateTimeImmutable` dataset of `test_delay_header_accepts_durations_and_an_absolute_time` is at the pinned time).

BH9 — covered — licence (`test_channel_is_refused_without_an_enterprise_licence`), extension
(`test_channel_is_refused_without_a_coroutine_extension`), coroutines < 1 (`test_a_channel_requires_at_least_one_coroutine`),
`PollingMetadata::withCoroutines` (`test_polling_concurrency_is_refused_at_bootstrap`), consumer limits
(`test_polling_consumer_limits_are_refused_at_bootstrap`), `#[Priority]`/`#[TimeToLive]`
(`test_unsupported_delivery_attributes_are_refused_at_bootstrap`), outside a coroutine at send
(`test_sending_outside_a_coroutine_is_refused`, `test_a_sender_on_a_fiber_outside_a_coroutine_is_refused`), consumer
start (`test_starting_a_consumer_is_refused`), pool wait negative / not a number / registry
(`InterleavedExecutionsTest::test_negative_connection_pool_wait_is_refused`,
`test_connection_pool_wait_that_is_not_a_number_is_refused`, `SymfonyConnectionCopiesTest::test_negative_connection_pool_wait_is_refused`,
`test_connection_pool_wait_cannot_be_set_for_a_manager_registry_connection`,
`test_create_for_connection_refuses_a_negative_pool_wait`); every one asserts the message.

BH10 — covered — asynchronous on the coroutine channel (every channel test) and the same handlers synchronous / on a
queue channel in tests (`CoroutineMessageChannelRefusedTest::test_a_production_coroutine_channel_can_be_replaced_with_a_queue_in_tests`),
synchronous replay outside a coroutine (Dbal DLQ test).

BH11 — covered — pinned time: `test_delayed_message_waits_on_a_coroutine_timer`,
`test_delay_header_accepts_durations_and_an_absolute_time`, `test_delayed_retry_returns_to_the_coroutine_channel_after_its_delay`,
`test_delays_wait_on_the_coroutine_timer_beside_a_sleep_service_of_the_application`,
`TestClockAtBootstrapTest::test_pinned_time_leaves_the_applications_sleep_service_in_place`; hooks-off ordering
without elapsed-time tolerance (`test_delayed_message_with_hooks_off_does_not_stall_a_sibling_coroutine`). Pool wait
uses real time (0.2 s against a 0.6 s hold, and 0): a timeout of a real coroutine wait, not the clock, with a 3x
margin. Pending maintainer decision recorded here: the unbounded `maximumDelay()` (not counted either way).

BH12 — covered — every new or changed test bootstraps through `EcotoneLite::bootstrapFlowTesting` and asserts handler
state, exception messages and logs; no reflection, no SQL on Ecotone tables (the Dbal test queries its own
`coroutine_channel_orders`), no `getMessageChannelByName` (removed after G16). `ConfigurationException` /
`LicensingException` are the exception types an application catches; the `#[Around]` probe in the Dbal test uses the
public `Ecotone\Api\Attribute\AsynchronousRunningEndpoint` and `Precedence`.

BH15 — covered — held messages and failure endpoint live in `ExecutionScope`
(`CoroutineMessageChannelRelease::state()`, `CoroutineMessageChannelFailureLogger::failure()`), proven by Dbal
`test_interleaved_senders_release_only_the_successful_senders_message` and
`test_failure_logs_keep_each_coroutines_endpoint_and_headers`. `CoroutineMessageChannel::$consumers` and the queue are
per-process by design and hold no message state; the race on `$consumers` is BH17.

BH16 — covered — a consumer coroutine keeps its connection while it consumes (stated in the skill and the exhausted
message); concurrent handlers keep their own connection and transaction in real coroutines (Dbal
`test_concurrent_messages_keep_their_own_headers_and_transaction`, `test_each_message_uses_its_tenants_connection`);
nothing new is stored in a property.

BH18 — covered — the new waits are `Swoole|OpenSwoole\Coroutine\System::usleep/sleep`
(`CoroutineMessageChannelSleep`), `Event::defer` and `Coroutine\Channel::pop($wait)` in `ConnectionPool`: run on both
containers (`test_delayed_message_with_hooks_off_does_not_stall_a_sibling_coroutine`, the delay tests, the three pool
wait tests, `CoroutineConnectionPoolTest`); outside a coroutine the send is refused with the way out
(`test_sending_outside_a_coroutine_is_refused`).

BH19 — covered — the new endpoint is the channel's consumer: a separate start is refused
(`test_starting_a_consumer_is_refused`, `test_console_consumer_start_is_refused`), `PollingMetadata::withCoroutines`
refused (`test_polling_concurrency_is_refused_at_bootstrap`), and it consumes on several coroutines
(`test_concurrency_is_limited_and_waiting_messages_start_in_order`) through `ConsumerCoroutines::consume(drainsAQueue: true, streamsConsumed: [])`.

BH21 — covered — `packages/Ecotone/tests/Messaging/Execution/CoroutineMessageChannelTest.php`,
`packages/Dbal/tests/Integration/CoroutineMessageChannelTest.php`, `packages/Symfony/tests/phpunit/CoroutineConnectionPoolTest.php`
added to `COROUTINE_TESTS` (`.github/workflows/test-monorepo.yml:236-239`); the new method in
`packages/OpenTelemetry/tests/Integration/CoroutineTracingTest.php` runs in that job's own `--fail-on-skipped` tracing step
(line 306-308); the new methods in `packages/Dbal/tests/Integration/CoroutineExecutionsTest.php` are in a listed file.

## Not apply

BH20 — not-apply — the change copies nothing into a child execution: neither `CoroutineMessageChannelReleaseState`
nor `CoroutineMessageChannelFailure` implements `CopiedToChildExecution`, so a coroutine a handler starts has its own
empty hold (consistent with its own transaction, Dbal `CoroutineExecutionsTest::test_coroutine_started_by_a_handler_propagates_its_headers_and_stores_outside_of_its_transaction`).

## Pending maintainer decisions (reported, not counted)

- Default of 10 coroutines per channel (BH7/BH19): equals the default pool size of 10; the skill and the exhausted
  message tell to size the pool for requests plus channel limits.
- Unbounded `maximumDelay()` (BH11): a delay of days keeps a timer and its message in worker memory.
- Public `$messageChannelName` and the two public pool-wait default constants (`ConnectionPool::DEFAULT_WAIT_SECONDS`,
  `SymfonyConnectionReference::DEFAULT_CONNECTION_POOL_WAIT_SECONDS`): no behaviour depends on them being public.

## Runs on this head (worktree root, one at a time)

- swoole, CI command, COROUTINE_TESTS: OK (99 tests, 184 assertions) var/rv6-swoole.log
- openswoole, CI command, COROUTINE_TESTS: OK (90 tests, 173 assertions) var/rv6-openswoole.log
- app, Core tests suite: OK (2413 tests, 4870 assertions, 53 skipped) var/rv6-core.log
- BH17 reproduction: var/rv6-race-swoole.log, var/rv6-race-openswoole.log
- PHP 8.2 (app8_2): php -l of every changed PHP file clean; no runtime run (shared vendor requires PHP >= 8.4.1)
- NOT run (usage limit): full Dbal suite, OpenTelemetry CoroutineTracingTest on both runtimes, the skill eval judges

# Guidelines review: re-check

## Re-check at 082a7e5a6

Missing in round 1, decided again:

GL2 — covered — `CoroutineMessageChannelTest::test_a_failed_asynchronous_sender_starts_no_handler` (an
      `#[Asynchronous('senders')]` handler sends and throws, `run('senders')` in a coroutine, nothing handled)
GL15 — covered — `DbalConnection.php:41` and `CoroutineMessageChannelModule.php:89` name their arguments (dbee23d1c)
GL17 — covered — `CoroutineMessageChannel.php:47,64` hold the service as `$sleep`
GL27 — missing — `CoroutineMessageChannelModule.php:116-143` — every other `PollingMetadata` setting is now refused with
      a data-provider row (`unsupported_polling_limits`), the error channel stays honoured, and the channel listed with
      another channel is refused in both orders (`test_a_handler_chaining_the_channel_with_another_channel_is_refused_at_bootstrap`).
      Judged calls: refusing `withEnabledErrorChannel(false)` is sound (a failure has no other place to go than the drop
      log, so the setting contradicts the channel); refusing both chain orders is sound (rule 1d: when one reading works
      and the other ends the process, refuse rather than support half). Still undecided: `withSignalInterceptors()`.
      The reason given, that an explicit value cannot be told from the default, holds only when the value equals
      `extension_loaded('pcntl')` (`PollingMetadata.php:57`); a differing value is detectable, and nothing proves it is
      honoured either. It is the same question as the memory limit, which the channel already settles because it does
      not own the worker: with ext-pcntl its internal consumer installs Ecotone's termination listener inside the HTTP
      worker (the implementer's untested observation). — decide it: run the channel's internal consumer without signal
      interceptors (as it already runs without a memory limit) and refuse `withSignalInterceptors()` with a value that
      differs from the default, with a row in `unsupported_polling_limits`; or add a test that proves the value is
      honoured — skill: framework-error-handling 1d
GL34 — covered — MariaDB 11.4.13 runs of both Dbal files on Swoole (33 tests) and OpenSwoole (24 tests) listed in the
      spec, "Engine runs"
GL46 — covered — `bin/test-agent-skills ecotone-open-swoole` at a30dd3e64, after the rubric changes of 17855865f and
      66f824ae6 and the skill change: both cases 1.00 over 2 runs, exit 0 (`var/r5-skill.log`, `var/r5-report.txt`)

Requirements the new commits touch:

GL42 — missing — `docs/superpowers/specs/2026-11-21-coroutine-message-channel-spec.md:37` — the new row "A message
      released while the last consumer coroutine ends is still handled" is stated as an approved decision with no test:
      its test was removed in 432edbfbd because no userland seam opens the window any more. Rule D2 makes every stated
      guarantee carry a test named after it — move the sentence out of the decisions table into a note on the
      implementation (what `CoroutineMessageChannel::enqueue()` loops on and why there is no test), or add a test that
      reaches the window through the userland surface — skill: framework-design-and-briefs D2
GL4 — covered — new tests are snake_case and comment-free; aggregate and saga anonymous; the two named events
      `CoroutineChannelOrderWasPlaced`/`WasPaid` are type declarations (exception 1)
GL8 — covered — `CoroutineMessageChannelConsumerStopInterceptor` holds only the channel names from its `Definition`
GL9 — covered — `CoroutineMessageChannel::hasWaitingMessage()` takes the head message and puts it back at the head with
      nothing yielding in between, so the query leaves the queue as it found it
GL14, GL16 — covered — both new `src` files are `final`, `strict_types=1`, full Enterprise notice; their only
      docblocks are `@param string[]`
GL23, GL24, GL26 — covered — the chain refusal and the 12 new polling refusals name the setting, the reason and the
      way out, are asserted in full, and run in `prepare()`
GL37 — covered — consumer stop handlers are skipped by an Enterprise interceptor on the consumer-stop channel; open core
      is untouched
GL38, GL40 — covered — no new module; tests name no module
The other requirements keep their round-1 status.

# Behaviour review: re-check at 082a7e5a6


NOT PASSED: BH14

Count at 082a7e5a6: not-apply 1, covered 19, missing 1. (At 4d95019cc: BH17, BH14 missing.)
## Missing

BH14 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelConsumer.php:44` with
`packages/Ecotone/src/Messaging/Endpoint/Interceptor/SignalInterceptor.php` — combination with the Swoole/OpenSwoole
server's own signal handling when ext-pcntl is loaded (it is, in both containers). The internal consumer is built from
`ExecutionPollingMetadata::createWithFinishWhenNoMessages()`, whose `withSignalInterceptors` defaults to
`extension_loaded('pcntl')`, so every consumer coroutine of the channel installs Ecotone's `PcntlTerminationListener`
(`pcntl_signal` for SIGINT/SIGTERM/SIGQUIT, `pcntl_async_signals(true)`) inside the HTTP worker for the length of a
burst. Probe in the `swoole` container (Swoole HTTP server, `worker_num` 1, `enable_coroutine`, a channel handler that
waits 2 s, `var/rv7-pcntl-swoole.log`): SIGTERM to the worker with no burst running ends it (baseline) and so does a
SIGTERM after a drained burst. A SIGTERM during a burst is swallowed: 4 s later the worker is still alive and answers
`ok`, and Swoole logs `swoole_signalfd_event_callback(): read from signalfd failed`. The termination only sets
Ecotone's flag, so the consumer stops after its message and the loop starts the next one. When the listener is
disabled the flag is reset, and the server's shutdown or reload signal to that worker is lost. Fix: the channel does
not own the process, so its internal consumer runs `withSignalInterceptors(false)`, the same reasoning as the memory
limit set to 0. Test to add in `CoroutineMessageChannelTest.php`:
`test_a_termination_signal_during_a_handler_reaches_the_handler_the_application_installed`. The test installs a
`pcntl_signal(SIGTERM, ...)` handler, sends `posix_kill(getmypid(), SIGTERM)` from a channel handler, and asserts the
application's handler was called. It needs `#[RequiresPhpExtension('pcntl')]` and is listed in `COROUTINE_TESTS`
(pcntl is loaded on both CI runtimes, so `--fail-on-skipped` holds). OnOpenSwoole the probe was not run.
The rest of BH14 from 4d95019cc is covered now: (a) `#[OnConsumerStop]`
(`test_consumer_stop_handlers_are_not_called_when_the_channel_is_drained`,
`test_consumer_stop_handlers_are_still_called_for_a_polling_consumer_beside_the_channel`, red first in
var/r5-red-bh14.log); (b) `test_an_aggregate_command_handler_runs_on_the_channel`,
`test_a_saga_event_handler_runs_on_the_channel`. An event-sourced aggregate as sender is not tested on its own: its
recorded events reach the channel through the event bus inside the command bus boundary, which the Dbal `event`
dataset proves after commit. I accept that, given the coordinator's brief. — skill: `framework-service-design`,
`framework-testing`.

## Runs at 082a7e5a6 (alone on the stack, one at a time)

- swoole, CI command, COROUTINE_TESTS: OK (119 tests, 222 assertions), var/rv7-swoole.log
- openswoole, CI command, COROUTINE_TESTS: OK (110 tests, 211 assertions), var/rv7-openswoole.log
- app, CoroutineMessageChannelRefusedTest: OK (4 tests, 8 assertions), var/rv7-refused-app.log
- app, full Dbal tests suite: 1617 tests, 1 failure, 28 skipped (var/rv7-dbal.log). The failure is
  `ApplicationConnectionLifetimeTest::test_a_discarded_application_releases_its_database_connection` ("1 is identical
  to 2"). It counts every connection on the PostgreSQL server, and one connection open at its start had closed by its
  end. The file passes alone (2 tests, var/rv7-dbal-lifetime.log). It comes from main (#749) and the branch does not
  touch it. This is a timing-sensitive test of its own and needs a separate fix; it is not counted against BH1-BH21.
  The Pulse failures of my two earlier runs are gone, which fits them having overlapped with the implementer's runs.
- pcntl probe, swoole container: var/rv7-pcntl-swoole.log (see BH14).

# Guidelines review: second re-check

## Re-check at 2d1290a08

Missing at 082a7e5a6, decided again:

GL27 — covered — `withSignalInterceptors()` is now decided: the channel's internal consumer is always built without
      Ecotone's signal handling (`CoroutineMessageChannelConsumerRegistration.php:44-47`,
      `CoroutineMessageChannelConsumer::withoutSignalHandling()`), proved by
      `test_a_termination_signal_during_a_handler_reaches_the_handler_the_application_installed` (pcntl, in
      `COROUTINE_TESTS`); a requested `true` that differs from the default is refused
      (`CoroutineMessageChannelModule.php:138`) with its message asserted in
      `CoroutineMessageChannelRefusedTest::test_signal_handling_requested_for_the_channel_is_refused`. That test can only
      run on PHP without pcntl, so it skips in every container and in CI; the implementer ran it red and green by hand
      (`var/r6-red-signal-refusal.log`, `var/r6-green-signal-refusal.log`). A `true` equal to the pcntl default is not
      applied, and nothing can tell it from the default, so it is no second reading
GL42 — covered — the consumer-end loop is an implementation note that claims no guarantee (spec, "Implementation
      notes"); the new signal-handling row names its two tests, both in the tree

Requirements the new commits touch:

GL15 — missing — `packages/Ecotone/src/Messaging/Channel/Coroutine/CoroutineMessageChannelConsumerRegistration.php:46`
      — the new `new Definition(PollingMetadata::class, [$builder->getDefinition($pollingMetadata)], [CoroutineMessageChannelConsumer::class, 'withoutSignalHandling'])`
      passes three positional arguments (`src` already uses `factory:` for this constructor in three places) — name them:
      `className:`, `arguments:`, `factory:` — skill: framework-service-design 18. Deterministic-check candidate (G5),
      as in round 1
GL3, GL4 — covered — the signal test sends a real SIGTERM to its own process, which is the subject and cannot be
      pinned, restores the process's handler and async-signal flag in `finally`, and is snake_case and comment-free
GL9, GL11, GL13 — covered — `withoutSignalHandling()` returns a new `PollingMetadata` and changes nothing; it is a
      `Definition` factory on the compile path
GL23, GL24, GL26 — covered — the new refusal names the setting, the reason and the way out, runs in `prepare()`, and
      its text is asserted (see GL27 for where that test runs)
GL37 — covered — only Enterprise files changed; open core untouched
GL45, GL46 — covered — the skill sentence sits in `ecotone-open-swoole` §7; `bin/test-agent-skills ecotone-open-swoole`
      after it: both cases 1.00 over 2 runs (`var/r6-skill.log`)
The other requirements keep their status of the 082a7e5a6 re-check.

# Breaking changes review: second re-check

## Second re-check at 2d1290a08 (range 082a7e5a6..HEAD, 5 commits)

PASSED. No requirement was `missing`, and none changes status.

- `Api/`, `upgrade-2.0.md`, console commands: untouched (BC3, BC4, BC5 and BC6 stay as decided). The new
  `CoroutineMessageChannelConsumer::withoutSignalHandling()` is a static method on an internal final `src/` class,
  used as a container factory.
- Turning off signal handling for the channel's consumer
  (CoroutineMessageChannelConsumerRegistration.php:44-47) replaces only the `PollingMetadataReference` of a
  coroutine channel's own name. The compiler pass is added only when a coroutine channel is registered
  (CoroutineMessageChannelModule.php:73-74, :227), so signal handling of every other consumer, including
  `ecotone:run` with ext-pcntl, is unchanged (BC7 stays not-apply).
- The `withSignalInterceptors` refusal (CoroutineMessageChannelModule.php:138) checks only a `PollingMetadata` whose
  endpoint id is the channel's name. It is new behaviour of an opt-in feature in a major, not a BC item. Its
  pcntl-dependent condition and the test that CI never runs are for the behaviour and DX reviews.
- BC1, BC2, BC8: nothing persisted or shared changes.
- The maintainer's pending items are unchanged and still not counted.

Counts: not-apply 5 (BC1, BC2, BC5, BC7, BC8) / covered 3 (BC3, BC4, BC6) / missing 0.

# Developer experience review: second re-check

## Second re-check at 2d1290a08 (082a7e5a6..HEAD, 5 commits)

Nothing was missing, and no status changes:

- bf3721e80: the channel's internal consumer is built with `withSignalInterceptors(false)`
  (`CoroutineMessageChannelConsumerRegistration.php:44-47`), so Ecotone no longer installs its termination listener
  inside the HTTP worker. Good for the user: signals stay with the Swoole server that owns the worker, and the skill
  says so (66e6599f1, `SKILL.md:239-240`). No new name or option.
- 06489da8a: `PollingMetadata::withSignalInterceptors(true)` for the channel is refused, with the reason and the way
  out, where it differs from the default (PHP without pcntl) (`CoroutineMessageChannelModule.php:138`). On PHP with
  pcntl the same explicit `true` equals the default and is overridden without a refusal. That is a limitation of
  `PollingMetadata` (a plain bool, the explicit value cannot be told from the default), the case is unlikely
  (nobody asks a channel with no consumer process for signal handling), and the skill sentence states the behaviour,
  so I do not count it under DX7. That the refusal's test is skipped in every container and in CI is a testing
  question for the behaviour and guidelines sessions, not this section.
- 2d1290a08, 6271e4032: docs only (spec note, review list record).

Counts: not-apply 0, covered 10, missing 0.

# Behaviour review: second re-check

## Second re-check at 2d1290a08

BH14 — covered — the channel's internal consumer gets `withSignalInterceptors(false)`: the compiler pass rewrites the
channel's `PollingMetadataReference` through `CoroutineMessageChannelConsumer::withoutSignalHandling()`,
`CoroutineMessageChannelConsumerRegistration.php:44-47`. Test:
`CoroutineMessageChannelTest::test_a_termination_signal_during_a_handler_reaches_the_handler_the_application_installed`
(`#[RequiresPhpExtension('pcntl')]` and `('posix')`), red first on both runtimes (var/r6-red-signal-swoole.log,
var/r6-red-signal-openswoole.log). Its file is in `COROUTINE_TESTS`, so `--fail-on-skipped` makes the CI job fail if
pcntl is missing there. I repeated the probe in a real HTTP worker, where a channel handler waits 2 s and SIGTERM goes
to the worker:
- Swoole (var/rv8-pcntl-swoole.log): the worker now ends during a burst, as it does in the baseline and after a burst.
  At 082a7e5a6 it stayed alive and kept serving (var/rv7-pcntl-swoole.log).
- OpenSwoole (var/rv8-pcntl-openswoole.log): all three cases log `workerStop` and the manager restarts the worker,
  which answers the next request. Not probed before the fix.

Both probes were stopped; no `php /tmp/sigprobe.php` process is left in either container. The rest of BH14 is as
decided at 082a7e5a6.

BH9 — still covered, with a note. `withSignalInterceptors(true)` is refused only on PHP without pcntl
(`CoroutineMessageChannelModule.php:138`), the one case where it differs from the default. Its message test
`CoroutineMessageChannelRefusedTest::test_signal_handling_requested_for_the_channel_is_refused` skips itself wherever
pcntl is loaded, which is every container and CI. Its red and green runs were done by hand with pcntl left out
(var/r6-red-signal-refusal.log, var/r6-green-signal-refusal.log). With pcntl loaded, an explicit `true` cannot be told
from the default and is overridden without a message; the skill says the channel never handles process signals. The
behaviour is right everywhere, and the refusal that CI never exercises only changes a message, so I do not block.
This refusal comes from GL27, so the guidelines review owns whether a test that never runs in CI is acceptable.

BH17 — still covered: the spec now records the consumer-end loop as an implementation note with no claimed test. That
matches my decision at 082a7e5a6 (closed by construction, red first at 69baba3a5, no userland seam).

BH21 — still covered: the new signal test sits in a file already listed in `COROUTINE_TESTS`.

BH13 — still covered: the skill gained one sentence (66e6599f1) and no rule changed; var/r6-skill.log is the
implementer's run.

Runs at 2d1290a08, alone on the stack, one at a time:
- swoole, CI command, COROUTINE_TESTS: OK (120 tests, 224 assertions), var/rv8-swoole.log
- openswoole, CI command, COROUTINE_TESTS: OK (111 tests, 213 assertions), var/rv8-openswoole.log
- app, CoroutineMessageChannelRefusedTest: 5 tests, 8 assertions, 1 skipped (the pcntl-dependent refusal),
  var/rv8-refused-app.log
- I did not repeat the full Dbal suite: the implementer's solo run was green (1617, var/r6-dbal.log).


## Whole range re-reviewed at b0c74d831, after the merge of main at 5ec5f01aa

The four sessions decided the review list as merged (BH22 is new) against `git diff origin/main...HEAD`.

| Session | Not apply | Covered | Missing |
|---|---|---|---|
| Guidelines | 12 | 38 | 0 |
| Breaking changes | 5 | 3 | 0 |
| Developer experience | 0 | 10 | 0 |
| Behaviour | 1 (BH20) | 21 | 0 |

BH22 — covered by its stated reason: the channel refuses to boot without the `swoole` or `openswoole` extension and a
flow scenario runs on plain PHP, so the channel has no scenario and the pull request says so.

Behaviour runs at b0c74d831, one at a time: COROUTINE_TESTS on swoole OK (125 tests) and on openswoole OK (116 tests),
the new framework-tracing test alone on both, `bin/test-agent-skills ecotone-open-swoole` 1.00 / 1.00.

Outside the list: a missing blank line before the new test method in `CoroutineMessageChannelTest.php`, fixed in the
commit that records this re-review.
