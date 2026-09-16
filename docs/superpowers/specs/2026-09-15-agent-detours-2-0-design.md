# Agent detours: Ecotone 2.0 design

Source: `agentic-token-benchmark/research/agent-detour.md` (96 benchmark runs against Ecotone 1.311.0), its
reproductions in `research/ecotone-reproductions/`, and the companion `ecotone-mcp.md`.
Branch `dgafka/ecotone-2-0-agent-detours` @ `ab512c44` (2.0 head). All `file:line` citations are against that tree.

## 0. Outcome (Phase 2)

Implemented on `dgafka/ecotone-2-0-agent-detours` (base `ab512c44`), one commit per item, all documented in
`upgrade-2.0.md` §15 "Testing and developer-experience changes" (§15 → planned work renumbered to §16).

| ID | Result on this branch | Commit | Breaking |
|---|---|---|---|
| E7 | `fixedBackOff(0)` / zero `initialDelayInMilliseconds` accepted; negative value named in error | `54b60e26` | no |
| E2 | Retry exhaustion texts: `after 4 failed deliveries (1 initial + 3 retries)` | `20c1c4ca` | log/exception text |
| T1, E6, B3, B5 | Pinned test clock (handlers in `run()` see the set time, clock restored after `run()`), equal instant no-op, precise backwards error, `advanceTimeBy(TimeSpan\|Duration)`, `run()` without time argument, due-order release | `3317f76e` | yes: `advanceTimeTo` renamed, `run()` 3rd arg removed |
| E2 (names) | `maxRetryAttempts()`→`maxRetries()`, `exponentialBackoff*`→`exponentialBackOff*`, delay params `…InMilliseconds`, `#[DelayedRetry(maxRetries:)]` | `c7bdf079`, `7dfcb7b9` | yes (renames) |
| T3 | `getRecorded*`→`popRecorded*` (FlowTestSupport, MessagingTestSupport, WithEvents), `popRecordedEventsOfType()`/`popRecordedCommandsOfType()`, `getRecordedEcotoneMessagesFrom`→`popRecordedMessagesFrom` | `90f5cde3` | yes (renames) |
| T7 | `sendCommandWithRouting()`, `publishEventWithRouting()`, `DeadLetterGateway::replay()/replayAll()` | `7ff7cf55` | yes (renames) |
| T4, T5, E5 | Flow tests provide in-memory delayable channels for unconfigured `#[Asynchronous]` channels (configured channel replaces it; `EcotoneLite::bootstrap()` still fails); routing errors in flow tests name the unregistered handler class | `28ea3f86` | behaviour (tests no longer fail at boot) |
| B1, E3a, E3b | Interface/abstract-typed handlers (async, aggregate, projection) receive the concrete class from `__TypeId__` / stored event name; hint when no concrete class is known | `c4b9863d` | no |
| E1 (T6 message) | `AggregateNotFoundException` appends causation chain (sender handler, triggering messages) | `6d97f40d` | message text |
| E4 | Conversion error for service-typed first parameter suggests `#[Reference]` | `97422016` | message text |
| E10 | Retry exhaustion exception names channel and original exception class (user exceptions untouched per D5) | `d4a8cd6f` | message text |
| T11 | Regression test for cache key with classes from one file | `19469d43` | no |
| A3 | `#[Delayed(hours: 24)]` named durations | `413ddcbe` | no |
| B7 | `WithAggregateVersioning::getVersion()` | `7683aded` | no |
| B8 | Enterprise ES-handler metadata error names the open-source alternative | `f104081e` | message text |
| T10 (names) | `createWithTestingSetup(handledMessageLimit:, executionTimeLimitInMilliseconds:, stopOnError:)`, `run(channelOrEndpointName:)` | `11566548` | yes (named args) |
| skills | ecotone-testing, ecotone-asynchronous, ecotone-resiliency, ecotone-workflow updated | `c807ba58` | — |
| sweep | Monorepo cross-module tests, example app, benchmark | `b18fa1f8` | — |

Left for later (not in this worktree): T2 `runUntilIdle()`, T6 kernel projection testing service, T8 kernel queue/dead-letter
helpers, A1 versioned projections, A2 `#[Scheduled(every:)]`, A6 `ecotone:describe` (D6), A9 moving test/scheduling classes
into `Ecotone\Api`, E9 eager projection initialisation, B6/A4/A5/A7 knowledge (docblocks rejected by D4 — needs names,
messages or tooling), InstantRetry `retryTimes` naming.

## 1. How the status was determined

Every catalogue item was checked in two ways:

1. **Code reading** on this branch, cited below.
2. **Running the reproductions.** All 29 reproduction scenarios were ported to the 2.0 API (`Ecotone\Api\*`,
   channels registered as extension objects instead of `enableAsynchronousProcessing`, `withModulePackages()`,
   `#[Projection]` + `#[FromAggregateStream]`) and run inside the docker `app` container (PHP 8.5.3). Each
   scenario still asserts the 1.311 behaviour, so **green = still reproduces on 2.0, red = 2.0 changed it**.

Run result: 27 scenarios, 22 still reproduce, 5 changed (E8 single failure, T9, T10, T11, A5b). Two extra
probes were added: T2 with a persistent failure, and E10 with a synchronous handler throwing.

Status values: **fixed** (already fixed in 2.0), **partial**, **open**, **works** (behaviour exists, only
discoverability is missing), **n/a** (no longer applicable).

## 2. Status table

| ID | Topic | Status on 2.0 | Evidence |
|---|---|---|---|
| T1 | Time control in Lite | **open** | T1a-T1d all reproduce. Equal instant rejected: `packages/Ecotone/src/Lite/Test/FlowTestSupport.php:206`. `sleep()` moves a set clock: `packages/Ecotone/src/Test/StaticPsrClock.php:34-47`, called from `Messaging/Scheduling/SyncTaskScheduler.php:46`. `run(…, TimeSpan)` compares with each message's delay: `FlowTestSupport.php:153-159` → `Messaging/Channel/DelayableQueueChannel.php:187-211`. Send-order release: `DelayableQueueChannel.php:150-172`. `advanceTimeTo(Duration)` still named like a point in time: `FlowTestSupport.php:221` |
| T2 | Failing async handlers in tests | **partial** | Instant retries on async endpoints are now on by default (`upgrade-2.0.md` §9), so a *single* failure is absorbed inside the first `run()`. A persistent failure (probe: 4 failures) still rethrows the raw `RuntimeException` after 4 calls, the message stays in the channel, and the next `run()` handles it again. No `runUntilIdle()`, no dead-letter helpers on `FlowTestSupport` |
| T3 | Recorded events cleared on read | **open** | T3a-T3c reproduce. `packages/Ecotone/src/Lite/Test/Configuration/MessageCollectorHandler.php:255-274` resets on read; no typed accessor; test-published events recorded (`FlowTestSupport.php:79-84` goes through the Event Bus interceptor) |
| T4 | Handler not registered in Lite bootstrap | **open** | E5 reproduces verbatim: `packages/Ecotone/src/Modelling/Config/Routing/CommandBusRouteSelector.php:31`. Namespace bootstrap already works via `ServiceConfiguration::withNamespaces()` (used in `packages/Ecotone/tests/Lite/Test/MessagingTestSupportFrameworkTest.php`) but nothing points to it |
| T5 | Async handler runs inline without a channel | **fixed** | Bootstrap fails: `Registered asynchronous endpoint \`expireAbandonedBasket\`, however channel configuration for \`async\` was not provided. Register it with SimpleMessageChannelBuilder::createQueueChannel('async') …` (`Messaging/Config/MessagingSystemConfiguration.php` `configureAsynchronousEndpoints`, `upgrade-2.0.md` §1). Auto-provisioning in tests is the separate "Simpler EcotoneLite testing" track (`upgrade-2.0.md` §15) |
| T6 | Projection tests through the kernel | **open** | E1 message unchanged (below). No kernel-level projection testing service; `FlowTestSupport::triggerProjection()` etc. exist only in Lite (`FlowTestSupport.php:298-332`) |
| T7 | Naming inconsistencies | **open** | `sendCommandWithRoutingKey` / `publishEventWithRoutingKey` vs `sendQueryWithRouting` (`FlowTestSupport.php:72,86,98`); `DeadLetterGateway::reply()/replyAll()` (`packages/Dbal/Api/DeadLetterGateway.php:24-26`) vs `ecotone:deadletter:replay` (`packages/Dbal/src/Recoverability/DbalDeadLetterModule.php:36,75`); `advanceTimeTo(Duration)` |
| T8 | Queued / dead-lettered messages in kernel tests | **open** | No `getPendingMessages()` / `releaseDelayedMessages()` / dead-letter helpers anywhere in `packages/*/src` |
| T9 | Retries are delayed messages | **partial** | Instant retries now retry within the same pass (probe: `attempt, attempt, sent` in one `run()`). Delayed retries from `ErrorHandlerConfiguration` still need the clock moved between passes (E2 probe) |
| T10 | `createWithTestingSetup()` handles 1 message | **fixed** | Default 100: `packages/Ecotone/Api/ExecutionPollingMetadata.php:33`; reproduction handles all three |
| T11 | Lite cache key ignores class names | **fixed** | Class name is part of the key: `packages/Ecotone/src/AnnotationFinder/FileSystem/FileSystemAnnotationFinder.php:566-569` (commit `aa71b292`, #691). Reproduction: the second bootstrap knows its handler |
| E1 | `AggregateNotFoundException` without causation | **open** | Message identical to 1.311: `packages/Ecotone/src/Modelling/AggregateFlow/LoadAggregate/LoadAggregateMessageProcessor.php:74` |
| E2 | "retried maximum number of `4` times" | **open, worse** | `packages/Ecotone/src/Messaging/Handler/Recoverability/DelayedRetryErrorHandler.php:67,86`. With 2.0's default instant retries, `maxRetryAttempts(3)` now produces **16** handler calls (4 deliveries × 4 instant tries) and the log still says "retried maximum number of `4` times" |
| E3 (a/b) | Interface-typed async payload | **open** | E3b: `Can't convert from application/json:string to application/x-php:…BasketContentChanged … is an interface` — `Messaging/Handler/Processor/MethodInvoker/Converter/PayloadConverter.php:60-66` converts to the declared type before looking at `__TypeId__` (`:67-79`). E3a: aggregate variant still says `identifier header is missing. Please check your identifier mapping in string` (`LoadAggregateMessageProcessor.php:41`) |
| E4 | Service parameter taken as payload | **open** | `Can't convert from application/x-php:array to …Psr\Clock\ClockInterface … is an interface`, raised from `packages/Ecotone/src/Modelling/AggregateIdentifierRetrevingService.php:55,180`; no mention of the parameter or `#[Reference]` |
| E5 | "No Command Handler defined" | **open** | = T4 |
| E6 | "Cannot move time backwards" | **open** | = T1a/T1b |
| E7 | `fixedBackOff(0)` | **open** | `packages/Ecotone/src/Messaging/Handler/Recoverability/RetryTemplateBuilder.php:29` ("Initial delay must be greater than 0"); same rule on `Api/DelayedRetry.php` |
| E8 | Raw rethrow from `run()` | **partial** | See T2: single failure absorbed by instant retry; persistent failure rethrows `RuntimeException: SMTP connection refused` with no context |
| E9 | Projection table only after first event | **open** | Reproduces with `#[Projection]` v2: `#[ProjectionInitialization]` runs on the first event. `ecotone:projection:init` and `ProjectionDeployment(manualKickOff:)` exist but nothing runs init at setup |
| E10 | Exception buried under vendor frames | **open** | Probe: a synchronous `#[CommandHandler]` throwing `RuntimeException('Shipping provider down')` surfaces as exactly that exception; handler, endpoint and message are not named. Conversion failures already name the handler (`MethodInvocationException: Cannot resolve parameter 'event' while calling …::onChange`) |
| B1 | Interface/abstract payloads (async + projections) | **open** | = E3b; B1b reproduces on the v2 projection runtime (`Can't convert from application/x-php:array<string,string> to …WalletBalanceChanged`). `packages/JmsConverter/src/JMSConverter.php:76-78` claims `array → interface` (`isClassOrInterface()`) |
| B2 | Projection init on first event | **open** | = E9 |
| B3 | Delayed release in send order | **open** | = T1d |
| B4 | Retries are delayed messages | **partial** | = T9 |
| B5 | `StaticPsrClock::sleep()` moves test clock | **open** | = T1b |
| B6 | ORM flush vs event publishing order | **open** (docs) | Nothing on `#[EventHandler]` or `ManagerRegistryRepository` |
| B7 | Aggregate version in async handlers | **works** | Reproduction green: header survives JSON; `WithAggregateVersioning` has no `getVersion()` (`packages/Ecotone/src/Modelling/WithAggregateVersioning.php:10-13`) |
| B8 | Event time in event-sourcing handler | **open** | Message unchanged, no alternative named: `packages/Ecotone/src/Modelling/EventSourcingExecutor/OpenCoreAggregateMethodInvoker.php:19`; `Api/EventSourcingHandler.php` is empty |
| A1 | Projection lifecycle & schema change | **partial** | Fixed: lifecycle attributes now sit next to `#[Projection]` in `Ecotone\Api` (`packages/Ecotone/Api/ProjectionReset.php`, `ProjectionInitialization.php`, `ProjectionDelete.php`); v1 `ecotone:es:*` family removed; the remaining family has descriptions (`packages/Ecotone/src/Projecting/Config/ProjectingConsoleCommands.php:21-57`). Open: no `version:`-driven rebuild, no docblock tying lifecycle attributes to commands |
| A2 | `#[Scheduled]` + `#[Poller]` | **open** | Two attributes, no docblocks: `packages/Ecotone/Api/Scheduled.php`, `Api/Poller.php` |
| A3 | `TimeSpan` forms | **open** | `#[Delayed]` takes `int|TimeSpan|DateTimeInterface` only (`packages/Ecotone/Api/Delayed.php:22`); `TimeSpan` still lives in the internal `Ecotone\Messaging\Scheduling` namespace |
| A4 | Identifier resolution for sagas | **works** | Reproduction green; nothing on `packages/Ecotone/Api/EventHandler.php` says native resolution exists |
| A5 | Serialisation of new messages | **partial** | A5b fixed (JMS enum support on by default, `upgrade-2.0.md` §9; reproduction green); A5a works; nothing on `#[Asynchronous]` says plain classes need no converter |
| A6 | "How is messaging configured here?" | **open** | No `ecotone:describe` (only `ecotone:list`, `MessagingCommandsModule.php:48`) |
| A7 | `#[DbalWrite]` discoverability | **open** (docs) | `packages/Dbal/Api/DbalQuery.php` has no pointer |
| A8 | Per-handler retry / wiring check | **partial** | Endpoint-level `#[DelayedRetry]` exists (Enterprise, `packages/Ecotone/Api/DelayedRetry.php`); wiring check belongs to A6 |
| A9 | Package layout guessed | **partial** | Projection attributes unified (A1). Still outside `Ecotone\Api`: `EcotoneLite`, `FlowTestSupport`, `StaticPsrClock`, `TimeSpan`, `Duration`, `RetryTemplateBuilder`, `MessageHeaders`, `WithEvents`, `WithAggregateVersioning` — the classes agents grep for most |

Summary: fixed 3 (T5, T10, T11), works 2 (A4, B7), partial 8 (T2, T9, E8, B4, A1, A5, A8, A9), open 20.

## 3. Proposals

Format per item: behaviour/API, BREAKING or not, the regression test that proves it (EcotoneLite, user level,
asserting the new behaviour; ported from the named reproduction), size (S ≤ ½ day, M ≈ 1 day, L 2-3 days).

### Wave 1 — remove red runs

#### W1.1 Time model (T1, E6, B3, B5)

1. **Equal instant is a no-op.** `changeTimeTo($t)` with `$t == now()` returns without error; only `$t < now()` throws.
   Not breaking (relaxes a check). Test: `test_changing_time_to_the_current_instant_is_a_no_op` (T1a).
2. **A clock set by the test never moves by itself.** Once `changeTimeTo()`/`advanceTimeBy()` was used, polling
   and idle sleeps inside `run()` do not change what handlers or the test read from the clock. Planned shape:
   `StaticPsrClock` keeps the frozen instant separate from time "slept" by the consumer; `FlowTestSupport::run()`
   restores the frozen instant when the run ends, and in-memory test consumers do not sleep between two executions
   that handled a message. The termination of `run()` (time limit, idle wait) keeps working because it is measured
   during the run. Behaviour change, not an API change; a test that asserted a drifted timestamp breaks (none known).
   Test: `test_run_does_not_move_a_frozen_clock` (T1b) and `test_handlers_see_the_frozen_time_for_every_message_in_one_run`.
3. **`advanceTimeBy(TimeSpan|Duration $span)`** moves the clock cumulatively. `advanceTimeTo(Duration)` is renamed
   (decision D1). Test: `test_advancing_time_by_spans_adds_up_and_releases_delayed_messages` (T1c, 23h + 2h releases a 24h delay).
4. **`run()` loses its `TimeSpan` argument** (decision D1): `run(string $name, ?ExecutionPollingMetadata $metadata = null)`
   releases what is due at the test clock's current time. Moving time is `advanceTimeBy()` / `changeTimeTo()`.
   `DateTimeInterface` is dropped as well (it is `changeTimeTo()` without moving the clock). BREAKING: 12 call sites
   in the monorepo pass a `TimeSpan`/`DateTimeInterface`.
5. **Due delayed messages are released ordered by due time** (then send order for equal due times):
   `DelayableQueueChannel::receive()` picks the earliest due message. Behaviour change. Test:
   `test_due_delayed_messages_are_released_in_due_order` (T1d).
6. **Backwards error names the cause:** `Cannot move time backwards: the test clock is at 2026-03-01 13:00:00.000000 and
   you requested 2026-03-01 12:00:00.000000. Request a later time, or use advanceTimeBy() to move forward relative to
   the current time.` Not breaking. Test asserts the exact text.
7. `FlowTestSupport::getClock()` — **not proposed**: services built before bootstrap cannot use it; the skill documents
   `ClockInterface::class => new StaticPsrClock()` instead.

Size: M (item 2 needs care around `SyncTaskScheduler`/`TimeLimitInterceptor`), others S.

#### W1.2 Error handling in tests (T2, T9, E2, E7, E8)

1. **Zero back-off allowed (E7).** `RetryTemplateBuilder::fixedBackOff(0)`, `exponentialBackoff(0, …)` and
   `#[DelayedRetry(initialDelayMs: 0)]` are accepted; a retry with delay 0 is re-sent without `deliveryDelay`, so one
   `run(…, failAtError: false)` walks a message through its retries into the dead letter. Negative values still fail
   with `RetryTemplateBuilder initialDelay must be 0 or greater, got -1`. Not breaking. Tests:
   `test_zero_back_off_retries_within_one_run_and_ends_in_dead_letter` (T2/T9 scenario, E2 counts).
2. **Dead-letter log states attempts (E2).** `Sending message \`{id}\` to dead letter channel after 4 failed deliveries
   (1 initial + 3 delayed retries). Due to: …` and, without dead letter, `No dead letter channel defined. Message failed
   after 4 deliveries (1 initial + 3 delayed retries). Passing to final failure strategy. Due to: …`. When instant
   retries are active the line adds `each delivery included up to 3 instant retries`. Not breaking (log text; the
   `MessageHandlingException` text `Message handling failed after %d retry attempts` is changed the same way).
   Test: logger assertion in the E2 port.
3. **Rethrow from `run()` explains itself (E8)** — depends on decision D5.
4. `runUntilIdle()` / `useApplicationErrorHandling` — **deferred to Wave 2**: with zero back-off and instant retries
   the red runs are removable without a draining API that also releases unrelated delayed business messages.

Size: S + S + (D5).

#### W1.3 Recorded messages (T3, part of T7)

Decision D2. Recommended shape:

```php
public function getRecordedEvents(): array;                         // non-destructive, everything since bootstrap or last discard
public function takeRecordedEvents(): array;                        // returns and clears (today's getRecordedEvents semantics)
public function getRecordedEventsOfType(string $className): array;  // non-destructive, instanceof filter
public function getRecordedCommands(): array;                       // non-destructive
public function takeRecordedCommands(): array;
public function getRecordedCommandsOfType(string $className): array;
// headers/routing/spied-channel readers follow the same rule: get* non-destructive, discardRecordedMessages() clears all
```

BREAKING (semantics of existing names). Monorepo impact: `getRecordedEvents` 67 hits / 26 files,
`getRecordedEventHeaders` 17, `getRecordedCommands` 15, spied-channel readers 35; only sequences that read twice
without discarding change. Tests: `test_reading_recorded_events_twice_returns_them_twice`,
`test_take_recorded_events_clears_them`, `test_recorded_events_of_type_filters_by_class` (T3a/T3b).
T3c (exclude test-published events): recommended **no** — events the test publishes are documented as recorded;
excluding them needs a marker header and would hide what reached the bus. Size: M (sweep).

#### W1.4 Lite registration (T4, E5)

1. **In test mode, a missing command/query route names the fix.** `CommandBusRouteSelector` (and the query
   counterpart) get a test-mode message:
   `Can't send command to App\Shipping\ReserveShippingSlot. No Command Handler is registered for it in this Ecotone Lite
   bootstrap. The handler App\Shipping\ShippingSlotReservationHandler::handle() exists but is not registered: add it to
   the classesToResolve of EcotoneLite::bootstrapFlowTesting(), or register its namespace with
   ServiceConfiguration::withNamespaces(['App\Shipping']).`
   The handler is found lazily, only when routing fails, by scanning the message's top-level namespace with the
   existing annotation finder. When nothing is found:
   `… No Command Handler is registered for it in this Ecotone Lite bootstrap. Add #[CommandHandler] to a method taking
   App\Shipping\ReserveShippingSlot and add its class to classesToResolve.` For routing keys, the nearest registered
   keys are listed (`Did you mean: basket.add, basket.clear?`). Production (non-test) message only gains the routing-key
   suggestion. Not breaking (exception class unchanged). Test: exact message for class-routed, key-routed and
   handler-exists cases (E5). Size: M.
2. Namespace bootstrap: already possible (`withNamespaces`) — documented in the skill and in the message above
   (decision D3 for a dedicated `namespaces:` parameter).

#### W1.5 Interface, abstract and union payloads (B1, E3a, E3b)

1. `PayloadConverter`: when the parameter type is an interface or abstract class and the message carries `__TypeId__`
   naming a concrete class that satisfies the parameter type, convert into that class (the union path at `:67-79`
   already does this). Same for the aggregate identifier path (`AggregateIdentifierRetrevingService::deserializePayload`),
   which removes the misleading E3a message, and for projections on stored events (event name → class via `EventMapper`).
2. `JMSConverter::matches()` stops claiming `array → interface/abstract` targets (`JMSConverter.php:77`), so a remaining
   failure reports the missing type information instead of an instantiation error.
3. When no concrete class is known: `Can not call App\BasketExpiry::onChange(): payload is application/json and parameter
   $event is typed with the interface App\BasketContentChanged, but the message has no __TypeId__ header naming a concrete
   class. Type the parameter with a concrete class or a union of classes.`
Not breaking (failure → success). Tests: `test_interface_typed_asynchronous_handler_receives_concrete_event_after_serialisation`,
aggregate variant, projection variant (E3b, E3a, B1b). Size: M.

#### W1.6 Causation chain (E1, T6 message part)

`MessageHeadersPropagatorInterceptor::storeHeaders()` already wraps every bus-invoked handler and keeps a stack of
headers. It additionally keeps, per frame, the handled message's type (class or routing key) and the handler
(`Class::method`). When an `AggregateNotFoundException` passes through a frame deeper than the entrypoint, it is
rethrown once, same class, with the chain appended:

`Aggregate App\Wallet for calling chargeFunds was not found using identifiers {"walletId":"wallet-404"}. Command
App\ChargeFunds was sent by App\WalletChargeHandler::onOrderCreated() while handling event App\OrderCreated, which was
recorded while handling command App\CreateOrder.`

Not breaking (class and code unchanged; message longer). Test asserts the exact message (E1 port). Size: M.
Extending the same chain to every exception is decision D5.

#### W1.7 Service parameter taken as payload (E4)

Runtime hint where conversion into the first unannotated parameter fails and its type is an interface/abstract class:
`Can't convert the payload of basket.clear (application/x-php array) into Psr\Clock\ClockInterface for parameter $clock of
App\Basket::clear(). The first parameter without an attribute is the message payload. If $clock is a service, mark it with
#[Reference].` Wrapping keeps `ConversionException`. Not breaking. A boot-time guard was rejected: at boot Ecotone cannot
tell a message interface from a service interface. Test: exact message (E4 port) plus the `#[Reference]` control. Size: S.

#### W1.8 First-line exceptions (E10) and rethrow explanation (E8)

Decision D5. Options:
- **(a) Ecotone-raised exceptions only** (routing, conversion, aggregate not found, retry exhaustion) carry handler,
  endpoint and message in the first line (W1.4-W1.7 already do). User exceptions are rethrown untouched. Not breaking.
- **(b) Wrap user exceptions in tests:** `FlowTestSupport` rethrows `Ecotone\Lite\Test\MessageHandlingFailed:
  Handler App\ShippingSlot::reserve() (endpoint reserveShippingSlot, channel async) failed handling App\OrderPlaced:
  RuntimeException: Shipping provider down. failAtError is true, so run() rethrows and the message stays in async; use
  failAtError: false with an error channel to exercise retries and the dead letter.` with the original as `previous`.
  BREAKING for tests using `expectException(UserException::class)` around `run()`/`send*()` — many in the monorepo and in
  user suites.
- **(c) As (b) but only for `run()`** (the async path, where the rethrow explanation matters), not for `send*()`.
Size: (a) 0 extra, (b) L (sweep), (c) M.

#### W1.9 T11 regression test

Already fixed. Port `CacheKeyTest` as `test_bootstraps_with_different_classes_from_one_file_do_not_share_a_container`
so it cannot regress. Size: S.

### Wave 2 — naming and API shape

| ID | Proposal | Breaking | Test | Size |
|---|---|---|---|---|
| T7 | `sendCommandWithRouting()`, `publishEventWithRouting()` on `FlowTestSupport` (matching `CommandBus::sendWithRouting`, `sendQueryWithRouting`); `DeadLetterGateway::replay()/replayAll()`; `advanceTimeBy()` (W1.1). Hard rename or deprecated aliases: decision D1 | yes if hard | `method_exists`-free: tests call the new names | M (526 + 39 mechanical hits) |
| A3 | `#[Delayed(hours: 24)]`: add named `milliseconds, seconds, minutes, hours, days` after `$time`; same on `#[TimeToLive]`. Move `TimeSpan`/`Duration` to `Ecotone\Api` (see A9) | no (additive); move is breaking | delayed handler declared with `hours:` is released after 24h | S |
| A2 | `#[Scheduled(endpointId:, every: new TimeSpan(minutes: 10) \| cron:)]` without a separate `#[Poller]`; boot error when `#[Poller]` sits on a method with no scheduler/channel adapter | no if `#[Poller]` still accepted | scheduled endpoint runs with `run('basket_expiry')` | M |
| A4, A5, B6, B7(doc), A7, B8(doc) | Per decision D4: short docblocks at the grep hit, or knowledge moved to exception messages + skills only | no | n/a (docs) | S |
| B7 | `WithAggregateVersioning::getVersion(): int` | no | version readable after two commands | S |
| B8 | Boot message names the alternative: `… is part of Enterprise features. Without Enterprise, carry the value in the event (e.g. an occurredAt property).` | no | exact message | S |
| A8 | done (`#[DelayedRetry]`); wiring question → A6 | — | — | — |
| T6 | Kernel projection testing: a `ProjectionTesting` gateway (`givenEventsFor()`, `runProjection()`, `resetProjection()`) registered only in test environments | no | Symfony/Laravel kernel test | L |
| T8 | Kernel test helpers on a test gateway: `getPendingMessages(string $channel)`, `releaseDelayedMessages(string $channel, DateTimeInterface $until)` honoured by DBAL channels in test mode, `getDeadLetterMessages()` | no | DBAL-backed kernel test | L |
| A1 | `#[Projection(name, version: 2)]`: stored version differs → delete + init + backfill; docblocks naming `ecotone:projection:rebuild` (D4) | no | bump version rebuilds read model | L |
| E9 | Eager init: `ecotone:projection:init --all` wired into `ecotone:migration:database:setup` (overlaps §8 database setup CLI); query-side message naming the projection when its table is missing is not feasible generically (the table is user SQL) | no | projection initialised at setup | M |
| T2 | `FlowTestSupport::runUntilIdle(string $channel)`: runs, advances the test clock only to the next retry due time of messages that failed in this call, until the channel holds nothing due | no | retries to dead letter in one call | M |
| A9 | Move the classes tests and handlers reference (`TimeSpan`, `Duration`, `RetryTemplateBuilder`, `MessageHeaders`, `WithEvents`, `WithAggregateVersioning`, `EcotoneLite`, `FlowTestSupport`, `StaticPsrClock`) into `Ecotone\Api` + namespace map rows | yes | existing suites | M (mechanical) |

### Wave 3 — introspection (A6, A4/A5/A8 answers)

`ecotone:describe [section] [name]` and a read-only `MessagingIntrospection` API built at container compile time
(channels with type/delayable/consumer, async endpoints with delay/retry/dead letter, error policy with
attempts = 1 + retries, converters, projections with commands, per-handler identifier resolution and parameter
sources), as specified in `ecotone-mcp.md` §3.1. Not breaking. Size: L-XL. Recommended as its own task (decision D6).

## 4. Decisions (answered by the maintainer, 2026-09-15)

| # | Decision | Answer |
|---|---|---|
| D1 | Breaking renames | **Hard renames, no aliases**: `sendCommandWithRoutingKey`→`sendCommandWithRouting`, `publishEventWithRoutingKey`→`publishEventWithRouting`, `DeadLetterGateway::reply/replyAll`→`replay/replayAll`, `advanceTimeTo(Duration)`→`advanceTimeBy(TimeSpan\|Duration)`, `run()` loses its third time argument. Monorepo swept; old→new rows in `upgrade-2.0.md` |
| D2 | Recorded messages | **Keep destructive semantics, rename so the name says it**: `getRecordedEvents()`→`popRecordedEvents()`, `getRecordedCommands()`→`popRecordedCommands()`, and `pop*` for every other destructive recorded/spied reader (headers, event/command messages, spied channel messages). Add `popRecordedEventsOfType()` / `popRecordedCommandsOfType()`. T3c unchanged. No aliases |
| D3 | Lite channels & registration | **Auto in-memory channels in flow tests**: every `#[Asynchronous]` channel the test did not configure gets a default delayable in-memory queue; a configured channel replaces it; handlers still never run inline (`run()` required). `EcotoneLite::bootstrap()` and framework boot keep "missing channel fails at bootstrap". Plus the E5 message. Update `upgrade-2.0.md` §1 and the ecotone-testing skill |
| D4 | Docblocks | **No descriptive docblocks** in the API. Semantics come from names and exception messages; rename where a name does not convey meaning. Allowed: array-shape/type docblocks and `@link https://docs.ecotone.tech/...` on `Ecotone\Api` classes. P3 list dropped |
| D5 | Handler exceptions | **(a)** user exceptions are never wrapped; only Ecotone-raised exceptions (routing, conversion, aggregate not found with causation chain, retry exhaustion) name handler/endpoint/message in the first line |
| D6 | `ecotone:describe` | Out of scope; the coordinator adds it as a TODO to the changelog |
| D7 | Scope | Wave 1 + small Wave 2: T7 renames, A3 `#[Delayed(hours:)]`, B7 `getVersion()`, B8 boot message, skills. Other public names a coding agent would understand better may be renamed too (no aliases), **verified empirically with fresh agents** (section 7) |

Consequences for §3: W1.3 follows D2 (pop*), W1.8 follows D5 (a), docblock rows of Wave 2 are dropped, T6/T8/A1/A2/A9/runUntilIdle stay for later.

## 4a. Original options put to the maintainer

| # | Decision | Options | Recommendation |
|---|---|---|---|
| D1 | Breaking renames: `sendCommandWithRoutingKey`→`sendCommandWithRouting`, `publishEventWithRoutingKey`→`publishEventWithRouting`, `DeadLetterGateway::reply/replyAll`→`replay/replayAll`, `advanceTimeTo(Duration)`→`advanceTimeBy(TimeSpan\|Duration)`, and dropping the `TimeSpan\|DateTimeInterface` argument of `run()` | (A) hard rename, no aliases, sweep the monorepo, upgrade-guide rows; (B) new names + old names as `@deprecated` aliases removed in 3.0; (C) only add new names | **A** — consistent with 2.0 removing deprecated API elsewhere (§7) and one name for agents to find |
| D2 | Recorded messages | (A) `get*` non-destructive + `take*` + `get*OfType`; (B) keep destructive `get*`, add `peek*` + `get*OfType`; (C) only add `get*OfType` and docs | **A**, T3c unchanged (test-published events stay recorded) |
| D3 | Lite registration & async channels vs "missing channel fails at bootstrap" | (A) this worktree: directive E5 message + skill docs; auto in-memory channels stay in the "Simpler EcotoneLite testing" track; (B) also add auto in-memory channels here; (C) also add a `namespaces:` parameter to `bootstrapFlowTesting()` | **A** — T5 is fixed by the boot error; auto-provisioning is designed in the research report and should land with its package-loading change |
| D4 | P3 docblocks vs "no comments in code" | (A) allow ≤3-line docblocks on public `Ecotone\Api` attributes/builders (`EventHandler`, `Projection`, `RetryTemplateBuilder::maxRetryAttempts`, `MessageHeaders` constants, `Asynchronous`, `DbalQuery`); (B) no docblocks — put the facts into exception messages, names and the skills; (C) docblocks only on attribute constructors | **A** — the benchmark shows agents read the grep hit line; `Api` is the documented public surface, not implementation code |
| D5 | Exceptions from handlers in tests (E8/E10) | (a) only Ecotone-raised exceptions get context; (b) wrap user exceptions from `run()` and `send*()`; (c) wrap only from `run()` | **c** — explains the rethrow on the async path where the red runs happened, keeps `expectException` on synchronous sends |
| D6 | Wave 3 `ecotone:describe` | (A) separate later task; (B) in this worktree after Waves 1-2 | **A** |

## 5. Test plan

- Each Wave 1 item: EcotoneLite test first in `packages/Ecotone/tests/Lite/` or the owning module's test folder,
  inline classes, snake_case, no comments, no reflection; red for the stated reason, then the fix.
- Error-message items assert the exact text.
- Package suites run sequentially in docker; before handing back: root phpstan, php-cs-fixer on touched files, full
  root phpunit after `packages/DataProtection/tests/before-tests.sh`.

## 6. upgrade-2.0.md

New section **"Testing and developer-experience changes"** (between §14 and §15): one entry per shipped item with
Before / Now / How to adapt, plus rows in the §7 table for renamed test API and the upgrade checklist.

## 7. Name verification

Method: fresh general-purpose Claude subagents (same model as the benchmark), told not to use any tools, given only
public signatures and a small Ecotone Lite task, and asked (1) to write the code, (2) to state the semantics of each
name, (3) to list names they had to guess. Three rounds.

**Round 1 — current API** (`getRecordedEvents`, `advanceTimeTo(Duration)`, `run(…, $releaseAwaitingFor)`,
`sendCommandWithRoutingKey`, `DeadLetterGateway::reply`, `maxRetryAttempts`, `fixedBackOff(int $initialDelay)`):
- Assumed `getRecordedEvents()` does **not** clear and wrote `assertCount(2, …)` after the second command (wrong).
- Read `advanceTimeTo` as "sets the clock TO" and preferred `changeTimeTo`; unsure whether `run()`'s third argument moves the clock.
- Read `reply()` as a typo for `replay`; hesitated over `…WithRoutingKey` vs `sendQueryWithRouting`.
- Unsure whether `maxRetryAttempts(3)` means 3 or 4 calls; expected `fixedBackOff(0)` to be valid.
- Flagged: `getRecordedEcotoneMessagesFrom` ("Ecotone" adds nothing), `exponentialBackoff` vs `fixedBackOff` casing,
  `amountOfMessagesToHandle` (cap or exact count?), unit of delays.

**Round 2 — proposed API** (`popRecorded*`, `advanceTimeBy`, `replay`, `…WithRouting`, `#[Delayed(hours:)]`):
- `popRecordedEvents()` read as clearing; second assertion correctly expects only new events.
- `advanceTimeBy()` read as relative and cumulative; `replay()` and `sendCommandWithRouting()` used without hesitation.
- Still flagged: `maxRetryAttempts` ("with `maxRetries(3)` I'd be confident it's 4"), unitless `$initialDelay`,
  `exponentialBackoff` casing, `amountOfMessagesToHandle` (→ `maxMessagesToHandle`/`handledMessageLimit`),
  `failAtError` (→ `stopOnError`), `run(string $name)` (channel or endpoint?), `popRecordedEventsOfType` scope.

**Decisions taken from rounds 1-2:** `maxRetries()`, `exponentialBackOff*()`, `…InMilliseconds` delay parameters,
`popRecordedMessagesFrom`, `createWithTestingSetup(handledMessageLimit:, executionTimeLimitInMilliseconds:, stopOnError:)`
(matching the existing `withHandledMessageLimit()`/`withStopOnError()` setters and `ecotone:run` options),
`run(string $channelOrEndpointName)`. `popRecordedEventsOfType` keeps its name and removes only that type, which is what
both agents guessed.

**Round 3 — final API:**
- Wrote the delayed-handler test with `#[Delayed(hours: 24)]`, `changeTimeTo()`, `advanceTimeBy(TimeSpan::withHours(24))`, `run('async')` — correct.
- `popRecordedEvents()` twice → `[]` (correct); `popRecordedEventsOfType()` → only that type removed (correct, still "unclear from signature").
- `maxRetries(3)` → 4 calls; `#[DelayedRetry(initialDelayMs: 0, maxRetries: 2)]` → 3 calls (correct).
- `createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)` used correctly.
- Expected the handler to see the frozen 12:00 during `run()` (now true) and `changeTimeTo` same instant to be a no-op (now true).
- Flagged: `DelayedRetry::initialDelayMs` vs builder `delayInMilliseconds` → renamed `#[DelayedRetry(initialDelayInMilliseconds:, maxDelayInMilliseconds:)]`;
  expected to register the `async` channel itself (answered by the provided channels + skills, no API change);
  unit of `#[Delayed(int $time)]` (left; named durations cover it).
