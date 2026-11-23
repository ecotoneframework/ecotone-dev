# Pre-PR review: dgafka/duration-time-span at 434e285d9

Four review sessions (guidelines, breaking changes, behaviour, developer experience) decided their section of the
review list on `main...HEAD`. Each ran as its own Orca session on Claude Opus 5.5. The lists are recorded as
reported, before any fix.

## Guidelines

```
NOT PASSED: GL1, GL15, GL43, GL46, GL49

Guidelines review of dgafka/duration-time-span @ 434e285d9 (git diff main...HEAD), 71 files.
Purpose: Ecotone 2.0 had two public length-of-time types; TimeSpan is removed and Duration takes its
constructor units (milliseconds first, microseconds last), hours()/days()/fromDateInterval()/getDefinition(),
and every Api signature, attribute, channel builder, test-support method, test, skill, doc and quickstart moves to it.

Inventory (all read in the diff):
- Api: Ecotone/Api/Scheduling/Duration.php (rewritten), TimeSpan.php (deleted), Attribute/{Collected,Delayed,Gathered,TimeToLive}.php,
  ExtensionObject/{DelayedMessageChannelBuilder,GatheringConfiguration,SimpleMessageChannelBuilder}.php, Lite/Test/{FlowTestSupport,MessagingTestSupport}.php,
  Amqp/Dbal/Kafka/Laravel/Redis/Sqs/Symfony channel builders (maximumDelay(): ?Duration)
- src: DelayedMessageReleaseHandler, DelayableQueueChannel, OutboundMessage, OutboundMessageConverter, CollectingModule, EndpointHeadersInterceptor,
  GatheringModule, GatheringHandlerCompiler, RecoverabilityFlowScenarios, EventSourcingProjectingModule, SymfonyMessageConverter
- tests: new Unit/Scheduling/DurationTest.php; TimeSpan->Duration swaps in 26 test files (Ecotone, Dbal conformance, Amqp/Kafka/Laravel/Redis/Sqs/Symfony
  GatheringTimeoutTest, PdoEventSourcing, Pulse GatheringPageTest)
- api/skills ecotone-asynchronous/-testing/-workflow (+references), api/evals ecotone-testing/ticket-lifecycle/graders/rule-4.md
- docs/message-collecting.md, docs/message-gathering.md, quickstart-examples Testing + Workflows/Saga, upgrade-2.0.md (§13c trimmed, new §13l), upgrade/namespace-map-2.0.csv
Skills applied: framework-testing (tests changed/added), framework-service-design (17, 18), framework-public-api (Api/ classes, names),
framework-error-handling (new refusal in fromDateInterval, edited messages), framework-design-and-briefs 19 (rename + upgrade entry),
framework-agent-tooling (public skill + grader changed), framework-dev-environment E4, framework-orientation O2, framework-pull-request P1.

GL1 — missing — packages/Ecotone/tests/Messaging/Unit/Scheduling/DurationTest.php:9,38 — the new test imports and names the src class
      Ecotone\Messaging\Support\InvalidArgumentException in expectException() — drop the expectException(class) line and keep
      expectExceptionMessage(), which already proves the refusal through the Api type alone — skill: framework-testing 10
      (deterministic-check candidate: a new tests/ file importing a non-Ecotone\Api Ecotone class)
GL15 — missing — packages/Ecotone/Api/Attribute/Delayed.php:33 — the rewritten call new Duration($milliseconds, $seconds, $minutes, $hours, $days)
      passes five positional arguments to a constructor whose positional order is exactly what §13l warns about — name them
      (milliseconds: $milliseconds, seconds: ..., days: $days) — skill: framework-service-design 18 (deterministic-check candidate: phpstan/cs rule on ≥3 positional args)
GL43 — missing — docs/superpowers/specs/message-collecting.md:21, docs/superpowers/specs/message-gathering.md:121,137,227 — the rename leaves the
      specs naming ?TimeSpan $maxWait, new TimeSpan(minutes: 30), withRetention(new TimeSpan(days: 14)), maximumDelay(): ?TimeSpan — update the four
      lines to Duration in the same commit (the upgrade-2.0.md §13l entry itself is present) — skill: framework-design-and-briefs 19
      (deterministic-check candidate: grep for class names absent from the tree in docs/superpowers/specs)
GL46 — missing — api/skills/ecotone-testing/SKILL.md:316 (rule 4 text changed), ecotone-asynchronous, ecotone-workflow — the changed rule's grader
      is updated (api/evals/ecotone-testing/ticket-lifecycle/graders/rule-4.md) but no eval scores are reported — run
      bin/test-agent-skills ecotone-testing ecotone-asynchronous ecotone-workflow (behaviour session per P5) and report the scores — skill: framework-agent-tooling A4
GL49 — missing — api/skills/ecotone-testing/references/api-reference.md:126 — lists advanceTimeBy(Duration $span), but the parameter is now
      $duration (FlowTestSupport.php:219); a named call advanceTimeBy(span: ...) fails — write advanceTimeBy(Duration $duration) and
      "Move clock forward by the duration" — skill: framework-orientation O2

GL4 — covered — DurationTest: snake_case behaviour names, no comments/assertion messages, only the licence/@internal docblock; swapped tests add no comments
GL14 — covered — DurationTest.php:3,15 declare(strict_types=1) and final class; Duration.php keeps both
GL16 — covered — no inline comment or prose docblock added; the @param prose on Delayed.php:21 and TimeToLive.php:19 and Duration::toFloat() docblock are pre-existing (G4; toFloat is the 6b-allowed fact)
GL17 — covered — one word "Duration" from attribute arguments through channel builders, test support (advanceTimeBy $span renamed $duration), headers validation and messages; the remaining TimeSpan names are the specs (GL43) and the build-flow baseline, which T4 leaves to main's CI
GL18 — covered — Duration has no writable property, no plain getter added, no with*() (so the promoted-copy pattern has nothing to copy); its one property is private readonly, no longer promoted because the constructor sums units; TimeSpan's writable public properties are gone
GL20 — covered — every changed public signature names Duration (Api); Duration's DefinedObject/getDefinition() is framework-called and its Assert/Definition imports are the 12a-allowed ones
GL23 (Duration::fromDateInterval) — covered — Duration.php:39 names the months/years, why (calendar-dependent) and two ways out; DurationTest::test_date_interval_with_months_or_years_is_refused asserts the text
GL23 (CollectingModule maxWait) — covered — CollectingModule.php:145 edited to "Set a positive Duration"; CollectingTest.php:398 and :543 assert it
GL23 (EndpointHeadersInterceptor:84,105) — covered — the change only swaps TimeSpan->Duration in the messages; their missing way out, missing message test and the TTL message saying "Delivery delay" are pre-existing (G4)
GL27 — covered — each attribute whose parameter type changed (#[Delayed], #[TimeToLive], #[Collected], #[Gathered]) keeps its supported/refused tests with Duration: DelayedAttributeDurationTest, EndpointHeadersInterceptorTest, CollectingTest (maxWait 0 / -1 refusals), GatheringTest + GatheringTimeoutTest per transport (maximum-delay refusal)
GL48 — covered — phpstan-baseline.neon is not in the diff
GL50 — covered — single commit "refactor(scheduling): Duration is the only type for a length of time, TimeSpan is removed"; the body states both types, the attribute limitation, the fromDateInterval change and the positional-meaning change

GL2 — not-apply — no guarantee reachable through several entry points is added
GL3, GL5, GL6 — not-apply — the only new test is a value-object test with no consumer, channel or clock; changed tests only swap the type passed to advanceTimeBy()/attributes on an already-pinned clock
GL7 to GL13 — not-apply — no service constructor, service property, service method, interface, configuration registration, reflection or orchestrating method is added (Duration is a value object)
GL19 — not-apply — no new class an application uses (Duration already in Api/Scheduling)
GL21, GL22 — not-apply — no console option or header added
GL24 to GL26 — not-apply — no new configuration guard, runtime error context or compile-time check; the fromDateInterval refusal is a value-object argument check
GL28 to GL36 — not-apply — the change reads and writes no storage
GL37 — not-apply — no Enterprise feature and no new file under src/ or Api/ (Duration keeps its Apache-2.0 header)
GL38 to GL40 — not-apply — no module or package added, no module test added
GL41, GL42 — not-apply — no design or spec added or changed
GL44, GL45 — not-apply — no rule or convention added
GL47 — not-apply — only a grader changed; the eval prompt is untouched

Not on the list
- upgrade-2.0.md §13l "Every row but the last fails loudly": the `$timeSpan->seconds`/`->minutes`/... row does not fail loudly on Duration — reading an undefined property is a Warning that yields null, then 0 in arithmetic (for Breaking changes / Behaviour).
- .claude/skills/framework-review-guidelines/references/known-deviations.md:491 still says the gap timeout is `Duration(60 * 1_000_000)` "which Duration's constructor reads as microseconds"; after this change the code is Duration::seconds(60) and the constructor's first unit is milliseconds — the audit row is now stale.
```

## Breaking changes

```
NOT PASSED: BC6

BC6 (fromDateInterval invert) — missing — packages/Ecotone/Api/Scheduling/Duration.php:50 — a 1.x app that passes $deliverAt->diff($now) (invert=1) to fromDateInterval got a positive TimeSpan, and so a real delay. In 2.0 it gets a negative Duration, which OutboundMessageConverter::normalizeDeliveryDelay turns into no delay: the message is delivered at once, with no error. The fromDateInterval row of upgrade-2.0.md:2613 (§13l) names only the months/years refusal. Fix: add the inverted-interval change to that row, with How to adapt (take the absolute value with ->absolute(), or diff in the other order), and add a DurationTest case for an inverted interval — skill: framework-design-and-briefs, framework-testing.
BC6 (Duration's first constructor parameter, microseconds to milliseconds, silent for positional calls) — covered — upgrade-2.0.md §13l, last table row plus How to adapt (search for new Duration( and name the unit).
BC6 (fromDateInterval refuses months/years) — covered — upgrade-2.0.md:2613, and DurationTest at line 53 (P1M).
BC3 (TimeSpan removed, 1.x Ecotone\Messaging\Scheduling\TimeSpan) — covered — major. upgrade-2.0.md §13l maps every constructor, factory, toMilliseconds/toDuration and public property, and upgrade/namespace-map-2.0.csv:187 now maps TimeSpan to Duration. §13c no longer lists TimeSpan, and the advanceTimeBy row is updated.
BC3 (Duration constructor reshaped from (int $microseconds) to (milliseconds, seconds, minutes, hours, days, microseconds)) — covered — major, §13l last row.
BC3 (#[Delayed] $time, #[TimeToLive] $time, MessagingTestSupport::releaseMessagesAwaitingFor take Duration instead of TimeSpan) — covered — major. §13l Before names these three, and the import row covers them.
BC3 (#[Collected] maxWait, #[Gathered] timeout, GatheringConfiguration retention/withRetention, DelayedMessageChannelBuilder::maximumDelay and the transport builders' maximumDelay) — covered — none of them exists on 1.x (git grep 1.x finds none), so only beta states change and no upgrade entry is owed.
BC3 (FlowTestSupport::advanceTimeBy narrowed from TimeSpan|Duration $span to Duration $duration, a parameter rename) — covered — advanceTimeBy is new in 2.0 (1.x has advanceTimeTo(Duration)), and the 1.x row at upgrade-2.0.md §14 test support now shows advanceTimeBy(Duration::hours(1)).
BC3 (Duration gains hours(), days(), fromDateInterval() and implements DefinedObject) — covered — additive, on a final class.
BC1, BC2, BC8 — not-apply — the stored form does not change. A Duration delay or TTL header is reduced to integer milliseconds before it is written: OutboundMessageConverter::normalizeDeliveryDelay, OutboundMessage, SymfonyMessageConverter, and TimeToLive converts in its attribute. FrameworkHeaders::unsetEnqueueMetadata strips the object header, and gathering retention is still passed as an int of milliseconds. Queues, outbox, dead letter and gathering tables get the same integers as before, so old and new versions read each other.
BC4 — not-apply — no default changes: GatheringConfiguration retention is still 7 days, and #[Delayed] named units are still zero by default.
BC5 — not-apply — no console command is touched.
BC7 — not-apply — the release is a major.

Not on the list: packages/Ecotone/tests/BuildFlow/baseline/shared.json:482 still names Ecotone\Api\Scheduling\TimeSpan in the DelayedMessageReleaseHandler signature, so the build-flow baseline is stale for this commit. fromDateInterval now keeps microseconds and rounds to milliseconds, where 1.x truncated, so a value can differ by at most 1 ms.
```

## Developer experience

```
NOT PASSED: DX1

Usage, most common case:
  use Ecotone\Api\Scheduling\Duration;
  #[Delayed(new Duration(hours: 24))]  #[TimeToLive(new Duration(minutes: 5))]
  #[Collected(maxSize: 500, maxWait: new Duration(seconds: 10))]  #[Gathered(timeout: new Duration(minutes: 30))]
  GatheringConfiguration::createWithDefaults()->withRetention(new Duration(days: 14))
  $ecotone->advanceTimeBy(Duration::hours(24))->run('async');
It needs one import and one class, named units in an attribute, static factories outside one. Before this change the user had to know two classes and which API took which.

DX1 — missing — api/skills/ecotone-asynchronous/references/api-reference.md:58 and api/skills/ecotone-asynchronous/references/usage-examples.md:115 — the diff rewrites TimeSpan::withMinutes(5) to #[TimeToLive(time: Duration::minutes(5))]. A static method call is not a constant expression, so a user (or an agent) who copies it gets "Constant expression contains invalid operations" when the attribute is read. The line at api-reference.md:78 in the same file says new Duration(...) is the form an attribute takes, so the reference contradicts itself. The simpler form is #[TimeToLive(new Duration(minutes: 5))], the form upgrade-2.0.md §13l and ecotone-workflow already use. Also api/skills/ecotone-testing/references/api-reference.md lists advanceTimeBy(Duration $span), but the parameter is now $duration (FlowTestSupport.php:219), so a named span: argument fails — skill: framework-public-api, framework-agent-tooling
DX4 — covered — the changed options keep their defaults: Collected maxWait and Gathered timeout are still nullable/optional, GatheringConfiguration retention is still 7 days (GatheringConfiguration.php:22), and each Duration unit defaults to 0 as TimeSpan's did (DurationTest::test_duration_without_units_is_zero)
DX5 — covered — the one new option, the microseconds constructor unit, has a use: it replaces the old positional new Duration($microseconds) (upgrade-2.0.md §13l last row) and carries sub-millisecond values from fromDateInterval
DX6 — covered — the neighbouring defaults agree: TimeToLive/Delayed ints stay milliseconds, matching the first positional Duration unit (DurationTest::test_first_positional_unit_is_milliseconds); SQS maximumDelay stays 15 minutes; Gathering retention now takes the same type as FlowTrackingConfiguration::withRetention
DX7 fromDateInterval — covered — an interval with months or years is now refused with a message naming the way out, where TimeSpan dropped them silently (Duration.php:37-40, DurationTest::test_date_interval_with_months_or_years_is_refused); no delivery, retry or transaction default changes
DX9 Duration constructor units (milliseconds, seconds, minutes, hours, days, microseconds) — covered — plain unit names, the same ones #[Delayed] takes as named durations
DX9 Duration::hours(), Duration::days(), fromDateInterval() — covered — they follow the existing seconds()/minutes() factory names
DX9 advanceTimeBy(Duration $duration) — covered — the parameter is named after its type, with no union to explain
DX10 — covered — Duration is the name the clock, DatePoint, advanceTimeBy and FlowTrackingConfiguration already used; ecotone-workflow and ecotone-asynchronous were searched and say Duration; no TimeSpan remains outside upgrade-2.0.md, the namespace map and the historical known-deviations rows
DX2, DX3 — not-apply — the change adds no way to switch a feature on and no configuration, only the type of existing arguments
DX8 — not-apply — no less safe behaviour is offered

Not on the list:
- A positional new Duration(500) does not show its unit. Named units are the readable form, so the docs could prefer them over the positional one (upgrade §13l already tells readers to name the unit).
- EndpointHeadersInterceptor.php:105: the #[TimeToLive] type guard says "Delivery delay should be either integer or Duration". It should say time to live. The line predates this change, but the diff touches it — framework-error-handling.
- MessagingTestSupport::releaseMessagesAwaitingFor names its parameter $timeInMillisecondsOrDateTime, but it now also takes a Duration (this predates the change).
- Duration in Api/ implements the internal DefinedObject and exposes getDefinition() to users. It inherits this from TimeSpan; it is a framework-public-api boundary question for the guidelines review.
```

## Behaviour

```
NOT PASSED: BH2, BH12, BH13

Behaviour review of dgafka/duration-time-span at 434e285d9 (git diff main...HEAD).

Problem (maintainer): Ecotone 2.0 had two public types for a length of time, `Ecotone\Api\Scheduling\TimeSpan` and
`Ecotone\Api\Scheduling\Duration`; keep one. Observable outcome that proves it solved: `TimeSpan` no longer exists,
and everywhere an application passed a `TimeSpan` (`#[Delayed]`, `#[TimeToLive]`, `#[Collected(maxWait:)]`,
`#[Gathered(timeout:)]`, `GatheringConfiguration::withRetention()`, `advanceTimeBy()`, `releaseMessagesAwaitingFor()`,
a `DELIVERY_DELAY`/`TIME_TO_LIVE` header, a channel builder's `maximumDelay()`) it now passes a `Duration`, which
can be written inside an attribute (`new Duration(hours: 24)`) and gives the same delay/TTL as before. Trace:
`Duration::__construct` takes TimeSpan's units in TimeSpan's order (Duration.php:21-35) and is a `DefinedObject`
(Duration.php:191), so a `Duration` inside an attribute compiles into the container; `Delayed` builds a `Duration`
from named units (Delayed.php:33); `TimeToLive` converts it to ms (TimeToLive.php:26); `EndpointHeadersInterceptor`
accepts `Duration` for delay and TTL (:78-105); `OutboundMessageConverter` / `SymfonyMessageConverter` already had a
`Duration` branch, the `TimeSpan` branch is removed; Collecting/Gathering modules read `inMilliseconds()`;
`git grep TimeSpan` finds no PHP reference left. The change reaches the outcome.

Tests run by this session (in the app container, one at a time):
- packages/Ecotone/tests/Messaging/Unit/Scheduling/DurationTest.php: OK (6 tests, 8 assertions)
- --filter DelayedAttributeDurationTest|TestClockTest|EndpointHeadersInterceptorTest|AsynchronousChannelsInFlowTestsTest|DelayedMessageReleaseTest|MessagingTestSupportFrameworkTest|CollectingTest|GatheringTest: OK (115 tests, 221 assertions)

bin/test-agent-skills ecotone-asynchronous ecotone-testing ecotone-workflow (RUNS=1, THRESHOLD=0.8, judge opus):
| case | score | failing grader |
|---|---|---|
| ecotone-asynchronous/background-notifications | 0.83 | rule-5 FAIL FAIL FAIL — the answer's test relied on the auto in-memory channel instead of registering it through `extensionObjects:`; its Duration usage (`advanceTimeBy(Duration::hours(23))`) is correct, so the failure is not caused by this diff |
| ecotone-testing/execution-recording | 1.00 | — |
| ecotone-testing/ticket-lifecycle | 1.00 | — (rule-4, the grader this diff edits, PASS) |
| ecotone-workflow/order-process | 1.00 | — |
All four cases are at or above the 0.8 threshold.

BH2 — missing — packages/Ecotone/Api/Attribute/TimeToLive.php:26 — `#[TimeToLive(new Duration(minutes: 5))]`
      (the form upgrade-2.0.md §13l shows) is tested nowhere; no test passes a Duration to `#[TimeToLive]`, so
      the changed `inMilliseconds()` conversion is unproven (on main the same attribute is a TypeError, so the test
      is red-first) — add a flow test: async handler with `#[TimeToLive(new Duration(minutes: 5))]`, spy the channel,
      assert the recorded TIME_TO_LIVE header is 300000 — skill: framework-testing
BH2 — missing — packages/Ecotone/Api/Scheduling/Duration.php:46 — `fromDateInterval()` now turns an inverted
      interval (`invert === 1`) into a negative Duration (TimeSpan ignored the sign) and rounds `f` to microseconds;
      neither is tested — add `test_inverted_date_interval_gives_a_negative_duration` (e.g. `(new DateTime('+1 hour'))->diff(new DateTime())`
      → `inSeconds() === -3600`) and one with fractional seconds — skill: framework-testing
BH2 — missing — packages/Ecotone/src/Messaging/Channel/DelayableQueueChannel.php:130 (via
      MessagingTestSupport::releaseMessagesAwaitingFor, Api/Lite/Test/MessagingTestSupport.php) — the public test
      method now takes `int|Duration|DateTimeInterface`; no test calls it with a Duration — add a test releasing a
      delayed message with `releaseMessagesAwaitingFor('async', Duration::hours(1))` — skill: framework-testing
BH12 — missing — packages/Ecotone/tests/Messaging/Unit/Scheduling/DurationTest.php:10,50 — the new test names the
      `src/` class `Ecotone\Messaging\Support\InvalidArgumentException`; rewriting Assert's exception type would
      break it — assert only the message (`expectExceptionMessage(...)`, or `expectException(\Exception::class)`)
      — skill: framework-testing
BH13 — missing — api/skills/ecotone-asynchronous/references/api-reference.md:58 and
      api/skills/ecotone-asynchronous/references/usage-examples.md:115 — the rewritten lines teach
      `#[TimeToLive(time: Duration::minutes(5))]`; a static call is not a constant expression, so PHP refuses the
      file at compile time (`php -l`: "Constant expression contains invalid operations", checked in the container).
      The pattern came from the old `TimeSpan::withMinutes(5)`, but §13l of this diff says static factories cannot
      be written in an attribute. No eval grader covers TTL guidance, so the evals above do not catch it — write
      `#[TimeToLive(new Duration(minutes: 5))]` and add a grader (e.g. in background-notifications) failing a
      static call inside an attribute — skill: framework-agent-tooling

BH1 — covered — trace above; DelayedAttributeDurationTest::test_delay_can_be_declared_with_named_durations,
      TestClockTest (`#[Delayed(new Duration(hours: 24))]`), CollectingTest/GatheringTest with Duration attributes
BH2 — covered — per claim, red on base 5ec5f01aa per var/red-first.txt: named units sum
      (DurationTest::test_duration_is_the_sum_of_its_named_units), first positional unit is milliseconds
      (test_first_positional_unit_is_milliseconds), hours/days factories (test_duration_is_created_from_hours_and_days),
      zero by default (test_duration_without_units_is_zero), fromDateInterval
      (test_duration_is_created_from_a_date_interval), months/years refused
      (test_date_interval_with_months_or_years_is_refused), Delayed both $time and named refused
      (DelayedAttributeDurationTest::test_delay_given_both_as_time_and_named_durations_is_rejected). The renamed
      call sites in existing tests (TestClockTest, AsynchronousChannelsInFlowTestsTest, Collecting/Gathering
      conformance, GatheringTimeoutTest per transport) would not compile on base (`new Duration(hours:)` unknown
      named parameter), so they are red-first too.
BH3 — covered — async consumer: DelayedAttributeDurationTest, TestClockTest; header sent with routing:
      MessagingTestSupportFrameworkTest (`DELIVERY_DELAY => Duration::hours(1)`), EndpointHeadersInterceptorTest::
      test_delivery_delay_and_time_to_live_without_existing_headers_replace (TTL header as Duration); expression path:
      EndpointHeadersInterceptorTest; gateway and console command cannot reach the change differently (the delay is
      a header read on the channel)
BH4 — covered — in-memory: tests above; DBAL: DbalChannelClockAfterTransactionTest (`#[Delayed(seconds: 2)]` now
      a Duration header through OutboundMessageConverter), CollectingConformanceTest, GatheringConformanceTest
      (`withRetention(new Duration(days: 2))`); brokers: GatheringTimeoutTest on Amqp, Sqs, Redis, Kafka, Laravel,
      Symfony; Symfony converter Duration branch: MessengerIntegrationTest::test_sending_with_delay_using_datetime.
      Engine-insensitive: storage receives the same integer milliseconds as before (implementer ran Dbal 1627 green)
BH8 — covered — DurationTest::test_duration_without_units_is_zero
BH9 — covered — DurationTest::test_date_interval_with_months_or_years_is_refused (message asserted),
      DelayedAttributeDurationTest::test_delay_given_both_as_time_and_named_durations_is_rejected (message asserted)
BH11 — covered — pinned clock at the boundary: DelayedAttributeDurationTest (89 min nothing, 90 min handled),
      TestClockTest (23 h nothing, 24 h handled), DelayedMessageReleaseTest
BH14 — covered — per feature the change meets: asynchronous channels + #[Delayed] (TestClockTest,
      DelayedAttributeDurationTest, DbalChannelClockAfterTransactionTest); #[Collected] maxWait (CollectingTest,
      CollectingConformanceTest, CollectingConcurrencyTest); #[Gathered] timeout / GatheringConfiguration retention
      (GatheringTest, GatheringConformanceTest, GatheringTimeoutTest per transport); projections' gathering
      (AsynchronousRunGatheringTest); workflows/sagas (quickstart OrderProcessTest, Verification tests); test clock
      (TestClockTest). #[TimeToLive] is filed under BH2. Aggregates, event sourcing, interceptors, distribution,
      identifier mapping, metadata, business interfaces: unrelated (they take no length of time)
BH22 — not-apply — no flow changes shape; only the type in DelayedMessageReleaseHandler's signature text changes,
      which packages/Ecotone/tests/BuildFlow/baseline/shared.json:482 still shows as TimeSpan — that baseline is
      left to main's CI
BH5 to BH7 — not-apply — a value-object swap: no transaction, no part-way failure, no message handled twice, no
      shared state or storage write added
BH10 — not-apply — a delay or TTL only takes effect on an asynchronous channel; sync handling has nothing to delay
BH15 to BH21 — not-apply — the change has no service state, no wait, no I/O, no consumer and no child execution;
      `Duration` is an immutable value object

Notes (not decided requirements): the TTL type-check message at EndpointHeadersInterceptor.php:105 still says
"Delivery delay should be either integer or Duration" for a TTL and names no way out (pre-existing, edited line —
for the guidelines review). docs/superpowers/specs/message-collecting.md:21 and message-gathering.md:121,137,227
still name TimeSpan. PHP 8.2 suite not run (constructor uses only 8.1-compatible syntax).
```
