# Behaviour review — dgafka/implement-execution-recording at c74807b4e (range origin/main...HEAD)

NOT PASSED: BH11

Problem, in the reporter's terms: a pull request to Ecotone should show how a message flow changed, before and after.
What would prove it solved: a flow test at `TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)`
reads every step a message took with `FlowTestSupport::popExecutionRecording()`, per process, across asynchronous
channels, distributed applications and coroutines. At that level every step is also exported as a span through the
configured tracer, and the build-flow tooling snapshots the catalogue into a baseline and compares two snapshots.
Trace: `EcotoneLite::bootstrapFlowTesting()` adds `FlowTestingBootstrap`. `TracingModule::prepare()` →
`FrameworkTracing::readsTheRecordingIn()` registers its own `InMemorySpanRecorder` and `InMemoryExecutionRecordingReader`,
or `ExecutionRecordingNotSwitchedOn`, which refuses with a message. `ExecutionRecordingTracer` wraps the configured
tracer, `RecordExecutionCompilerPass` wraps handlers, interceptors, channels, gateways and stores, and
`RecordedExecution::of()->toRecording()` builds the processes, steps, hints and sub-flows that `popExecutionRecording()`
returns. The change reaches the outcome. The one defect found: the order of one scenario's processes depends on the
wall clock (BH11).

## Missing

- BH11 — missing — `packages/Ecotone/tests/Tracing/ExecutionRecordingExplanationTest.php:759` (`executionOf()`, used by
  the test at :86) — the failing scenario: `test_the_step_that_put_a_message_on_the_channel_points_to_the_later_consumer_run_that_took_it`
  failed once in the full `packages/Ecotone/tests/Tracing` run (var/review/ecotone-tracing.out). It expected the
  `taken by` hints 3, 4, 5, 6 and got 3, 5, 4, 6, so the part report of batch 1 was consumed before batch 2 and became
  process 4. It passed alone and with the Coverage class, and 200 standalone recordings of the scenario all gave
  3, 4, 5, 6 (var/review/taken-by-order-200.out). `executionOf()` boots the catalogue scenario
  `tag-partitioned projection backfilled asynchronously in two batches` without `initialTime`, so the clock is not
  pinned. `DelayableQueueChannel::receive()` takes the earliest message by `timestamp (seconds) * 1000 + delay`. Messages
  queued on either side of a wall-clock second change their consumption order, and with it the process numbers and the
  sub-flow tree the recording prints. The likely cause is this second boundary; I did not provoke it.
  `FlowScenarioRecorder` pins `RECORDED_AT` for the baseline, which is why `BuildFlowBaselineTest` stays stable.
  The fix: pass a fixed `initialTime:` to `bootstrapFlowTesting()` in `executionOf()`, as `FlowScenarioRecorder` does.
  Check the other unpinned helpers of the new Tracing tests that consume several queued messages for the same thing.
  Then prove it red-first with the clock set just before a second boundary between the console backfill and the run
  (`changeTimeTo()` or `whenWaiting()`), and green with the pinned time. — skill: `framework-testing`
  (pinned time), `framework-dev-environment`.

## Decided

- BH1 — covered — the trace above. Entry `bootstrapFlowTesting()` + `withLevel(TracingLevel::Framework)` →
  `popExecutionRecording()`, asserted by `ExecutionRecordingReaderTest::test_the_flow_testing_support_reads_the_recording_as_processes_in_run_order_with_their_steps_hints_and_sender`.
  The span export is `FrameworkTracingLevelTest::test_at_the_framework_level_every_step_is_exported_in_one_trace_under_the_open_step_or_span_in_the_order_it_ran`.
  Baseline and comparison are `BuildFlowBaselineTest`, `BuildFlowComparisonTest` and `BuildFlowExecutionTest`, plus the
  comment script's `bin/test-build-flow-comment.php`. All are green here except the BH11 test.
- BH2 — covered — the feature's API (`TracingLevel`, `withLevel()`, `popExecutionRecording()`, `ExecutionRecording`) does
  not exist on origin/main, so every test that calls it is red there (from the diff). Later changes inside the branch
  have red output against d4e49b017 in var/review-evidence (n3-q16, n-step-tree, q2-q9-q16, q4-q6-q7-q8-q12, q5, q8,
  q11). The Q14 1 ms run limit has 12 failures before and 0 after. The behaviour change to main code is the in-memory
  redelivery (`InMemoryQueueAcknowledgeInterceptor::postSend`, faad6a7b6). It is proven by
  `InMemoryQueueRedeliveryTest::…keeps_the_headers_a_negative_precedence_channel_interceptor_added…`, reported "red
  with F5 reversed" in var/771/pre-pr-round-2-report.md (B1). The `#[AddHeader]`/`#[RemoveHeader]`/`#[Delayed]` cases
  there are characterisation tests, green either way and stated as such.
- BH3 — covered — bus by class: `ExecutionRecordingTest::test_before_around_and_after_interceptors…`. Routed send:
  `ExecutionRecordingReaderTest` (`sendCommandWithRouting`). Gateway / business interface:
  `ExecutionRecordingExplanationTest::test_an_instant_retry_declared_on_a_business_interface_is_recorded_as_a_retry` and
  `ExecutionRecordingCoverageTest::test_a_send_through_the_messaging_entrypoint…`. Console command:
  `ExecutionRecordingStateLeakTest::test_each_message_a_consumer_started_by_a_console_command…`. Asynchronous consumer:
  `ExecutionRecordingTest::test_a_command_sent_to_an_asynchronous_channel…`. Backfill:
  `ExecutionRecordingTest::test_an_asynchronous_backfill_in_two_batches…` and `ExecutionRecordingCoverageTest::test_a_projection_run_records…`.
- BH4 — covered — gathered group store in memory (`ExecutionRecordingCoverageTest::test_the_gathering_store_calls…`) and
  on DBAL (`GatheredFlowExecutionRecordingTest`, `GatheredPartOutcomeRecordingTest`). DBAL channel:
  `RedeliveredFlowExecutionRecordingTest`. `StoringSpanRecorder` at both levels: `PulseTracesTest`,
  `PulseMultiTenantTest`, `FlowTrackingTest`. The 218 filtered Dbal tests were run here on PostgreSQL, MySQL (primary)
  and MariaDB (primary), with PostgreSQL secondary. All green, 1 skipped (the coroutine test, run below).
- BH5 — covered — `ExecutionRecordingStateLeakTest` (failing async handler, around interceptor, conversion, collected
  release, error channel, final instant retry, inline send), `ExecutionRecordingSameOutcomeTest::test_a_process_that_failed_leaves_nothing…`,
  `ExecutionRecordingTest::test_a_send_that_succeeds_on_retry…`, `ExecutionRecordingCoverageTest::test_a_delayed_retry_and_the_dead_letter_decision…`,
  `GatheredFlowExecutionRecordingTest::test_a_group_store_call_that_fails…`, and
  `FrameworkTracingLevelTest::test_a_failing_handler_at_the_framework_level_exports_its_failed_steps_with_an_error_status…`.
- BH6 — covered — `ExecutionRecordingSubFlowTest::test_a_message_the_in_memory_queue_delivers_again_after_a_failure…`,
  `RedeliveredFlowExecutionRecordingTest`, `GatheredPartOutcomeRecordingTest::test_a_part_delivered_twice…`,
  `InMemoryQueueRedeliveryTest`.
- BH7 — covered — `ExecutionRecordingInterleavingTest` (5 tests, `Fiber::suspend()`),
  `FrameworkTracingLevelTest::test_two_commands_handled_interleaved…`, and the real-coroutine consumers on several
  coroutines (BH16).
- BH8 — covered — `ExecutionRecordingReaderTest::test_popping_the_recording_returns_what_ran_since_the_last_pop` (an
  empty pop returns `[]`), `ExecutionRecordingTest::test_polls_that_receive_nothing…`, and `BuildFlowComparisonTest`
  (no groups for identical snapshots).
- BH9 — covered — `ExecutionRecordingReaderTest::test_reading_the_recording_at_the_application_tracing_level_is_refused`
  and `…of_an_application_without_tracing_is_refused`. Also `ExecutionRecordingSameOutcomeTest::test_two_tracing_configurations_are_refused_at_bootstrap…`
  and `MessageFlowBuildTracingLevelTest` (build part and build-flow record refused at the application level and without
  a licence). Each asserts the message.
- BH10 — covered — sync: `ExecutionRecordingTest::test_before_around_and_after_interceptors…`. Async:
  `…test_a_command_sent_to_an_asynchronous_channel…` and `ExecutionRecordingSubFlowTest`.
- BH12 — covered — the new tests assert the recording through `popExecutionRecording()`, exported spans, Pulse
  queries and handler results. No SQL on Ecotone tables, no reflection. The only `src/` imports are framework exception
  classes in `expectException` and `RejectMessageException`, which an application throws (known-deviations row 12, no
  `Api/` counterpart). `tests/BuildFlow/*` is the test tooling itself (row 10).
- BH13 — covered — `RUNS=3 bin/test-agent-skills` on the tree as it is. ecotone-testing: execution-recording 1.00,
  ticket-lifecycle 1.00 (previously 0.72 on this branch and 0.78 on main's copy). framework-dev-environment:
  red-ci-check 1.00, run-tests 1.00. framework-review-guidelines: review-diff-guidelines 1.00, review-related-skills 1.00.
  Threshold 0.8, no failing grader (var/review/skills-*.txt). The only new rule, ecotone-testing rule 6, has grader
  `api/evals/ecotone-testing/execution-recording/graders/rule-6.md`. The other two skills change only references.
- BH14 — covered — handlers, interceptors (before/around/after/presend, channel interceptors): `ExecutionRecordingTest`.
  Aggregates, event-sourced aggregates, sagas, identifier resolution, fetched aggregate: `ExecutionRecordingCoverageTest`.
  Projections and backfill: `…test_a_projection_run_records…` and the catalogue baseline. Orchestrator, routing slip,
  splitter, router: `ExecutionRecordingExplanationTest`. Gathering and collector/outbox: Explanation and the Dbal
  gathered tests. Resiliency (instant/delayed retry, dead letter, error channel): Explanation, Coverage, StateLeak.
  Distribution: `ExecutionRecordingSubFlowTest` distributed tests. Metadata: `…messaging_entrypoint…headers it propagated`
  and `InMemoryQueueRedeliveryTest`. Multi-tenancy: `MultiTenantFrameworkTracingLevelTest` and `PulseMultiTenantTest`.
  OpenTelemetry: `FrameworkTracingLevelTest`. Pulse and flow tracking: `PulseTracesTest`, `FlowTrackingTest`,
  `FlowStepCompletedTracingLevelTest`. Licence choice: `…test_a_step_run_by_a_service_the_licence_chose…`.
  AMQP/Kafka/SQS/Redis channels go through the same channel-interceptor wrapping as the DBAL channel tested here, so they
  are unrelated to anything channel-specific. Laravel/Symfony share the one dumped container (#675).
- BH15 — covered — per-message state lives in `ExecutionRecordingState` / `ExecutionList` through
  `ExecutionScope::stateOf()`: `ExecutionRecordingTracer.php`, `StepSpanCapture.php:51`,
  `ChannelSendRecording.php:347`, `TracingChannelInterceptor.php:229`. `ExecutionRecordingTracer::$order` (:32) is a
  process-wide sort counter holding nothing of a message (known-deviations 3h). Proven by `ExecutionRecordingInterleavingTest`
  (incl. `test_interleaved_commands_release_their_recording_state_when_each_ends`).
- BH16 — covered — the change keeps no connection, channel, transaction or lock. `RecordedGatheredGroupStore` and
  `RecordedTransaction` wrap calls and hold no resource. Real coroutines: `packages/Ecotone/tests/Tracing/CoroutineExecutionRecordingTest.php`
  (3 tests) and `packages/Dbal/tests/Integration/CoroutineExecutionRecordingTest.php` (1 test, PostgreSQL). Both are
  green in the `openswoole` and the `swoole` containers with `--fail-on-skipped`, as is
  `packages/OpenTelemetry/tests/Integration/CoroutineTracingTest.php` (4) in both.
- BH17 — covered — no read-wait-write on shared state. The tracer reads and writes only its execution's state between
  waits, and `$order` is incremented without a wait in between. Proven by `ExecutionRecordingInterleavingTest`.
- BH18 — not-apply — the change introduces no I/O call through an extension or client. The DBAL group store query added
  to `DbalGatheredGroupStore` goes through the existing DBAL connection.
- BH19 — not-apply — no new endpoint or inbound adapter. `RecordedEndpointRunner` wraps existing consumer runs.
- BH20 — covered — `ExecutionRecordingState::copyForChildExecution()` copies only the integer order of the step that
  started the coroutine, into a fresh state. Proven by `CoroutineExecutionRecordingTest::test_an_event_published_from_a_coroutine_a_handler_started…`
  and `…test_a_coroutine_that_outlives_its_handler…` (both green on both extensions).
- BH21 — covered — both new coroutine test files are in `COROUTINE_TESTS` of `.github/workflows/test-monorepo.yml`
  (lines 236 and 238).

Runs (this worktree's containers, one at a time, junit under var/review/):
- Ecotone Tracing: 222 tests, 1 failure (BH11), 3 skipped (coroutine, no extension in `app`).
- Ecotone Flow unit: 479 tests, green, 1 skipped.
- Dbal recording/Pulse/tracking (filtered): 218 tests on PostgreSQL, MySQL and MariaDB, green.
- OpenTelemetry: 143 tests, green, 4 skipped.
- Coroutine files on openswoole and swoole: green.
- `php -l` of the 174 changed PHP files under PHP 8.2.29: clean.

Not run: the PHP 8.2 suite (the root vendor is 8.5), per-package `composer tests:ci`, and the downstream packages'
suites.
- My first MySQL run had 13 PulseMultiTenantTest failures. That was my own invocation error: `sh` expanded
  `SECONDARY_DATABASE_DSN="$DATABASE_DSN"` after it had already reassigned `DATABASE_DSN`, so both tenants were on MySQL.
  The rerun with literal DSNs is green.

Verdict: NOT PASS (1 missing: BH11)
