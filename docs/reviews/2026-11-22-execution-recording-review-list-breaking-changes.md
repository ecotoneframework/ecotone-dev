# Breaking changes review: dgafka/implement-execution-recording @ c74807b4e (range origin/main...HEAD)

PASSED

Release (B1): the latest tag is 2.0.0-beta.10 and the branch alias is dev-main => 2.0.x-dev (packages/Ecotone/composer.json:86). The change ships in a 2.0 pre-release, which is part of the 1.x -> 2.0 major. The default tracing level stays TracingLevel::Application, and at that level the code paths behave as before (EcotoneTracer::currentApplicationSpan and OpenTelemetryTracer::currentApplicationSpan both return currentSpan()).

No requirement is missing.

BC3 — covered — The Api/ signature changes are all safe:
- `TracingConfiguration` (packages/Ecotone/Api/Tracing/TracingConfiguration.php:20) gains `level` at the end of a *private* constructor. Applications build it only through `createWithDefaults()`, which now defaults to `TracingLevel::Application`.
- New method `withLevel()` on a final class.
- New enum `TracingLevel`.
- New final read-model classes `ExecutionRecording`, `RecordedProcess`, `RecordedStep`, `RecordedHint` and `RecordedSender`.
- New constants on the final class `SpanAttribute`.
- New method `FlowTestSupport::popExecutionRecording()` on a final class.
- `MessageFlowGraph::toMermaid()` (packages/Ecotone/Api/Messaging/Flow/MessageFlowGraph.php:240) adds marks only for nodes with the `build` attribute, which only the --build graph has.
- `EcotoneLite::bootstrapFlowTesting` adds an internal `FlowTestingBootstrap` extension object; its signature is unchanged.
- No class, method or parameter is removed, renamed or narrowed.
- The interfaces that gain methods (`Ecotone\Tracing\Tracer`, `GatheredGroupStore`, `Messaging\Config\Configuration`) live under src/ and are internal, not part of the public surface. Their in-repo implementations (OpenTelemetryTracer, DbalGatheredGroupStore, InMemoryGatheredGroupStore, MessagingSystemConfiguration) are updated in the same change.
BC4 — covered — No default changes. `TracingConfiguration::createWithDefaults()` keeps the recorders empty, `spanAttributesFromAllHeaders` true and the attribute headers empty, and adds `level: TracingLevel::Application`, the level that traces as before. `ecotone:flow:dump` keeps `--format=mermaid` as its default. Framework recording switches on only when asked for (FrameworkTracing::recordsExecutionIn, packages/Ecotone/src/Tracing/Config/FrameworkTracing.php:27).
BC5 — covered — `ecotone:flow:dump` keeps its name and `--format` option, which already existed in 2.0.0-beta.10. The change only adds the optional `--build` flag, defaulting to false (packages/Ecotone/src/Messaging/Config/Flow/MessageFlowConsoleCommands.php:18). Without the framework level, the dump runs as before, and `--build` is refused with a message that names the way out (MessageFlowConsoleCommandsWithoutBuild.php:21).
BC6 — covered — One behaviour change is observable at the default level: a failed message put back on an in-memory queue now comes back with the headers a negative-precedence #[ChannelInterceptor] added, and stays in its trace. It has a Before / Now / How to adapt entry in upgrade-2.0.md §14 ("A failed message put back on an in-memory queue comes back exactly as it was sent to the channel"). The proof is InMemoryQueueRedeliveryTest::test_a_message_redelivered_after_a_failure_keeps_a_header_added_before_it_was_put_on_the_channel and the tests next to it. The replacement for 1.x's OpenTelemetry tracing level is noted in the upgrade-2.0.md OpenTelemetry section (TracingConfiguration::withLevel(TracingLevel::Framework)).
BC1 — not-apply — No table, column, metadata key, persisted header name or payload shape changes:
- DbalGatheredGroupStore::partsOf (packages/Dbal/src/Gathering/DbalGatheredGroupStore.php:146) only reads existing columns.
- StoringSpanRecorder::asApplicationSpan (packages/Dbal/src/Tracing/StoringSpanRecorder.php:272) leaves application-level spans untouched. At the framework level it drops step spans and strips the `ecotone.execution.*` attributes, so stored traces keep the application form. PulseTracesTest::test_pulse_shows_the_same_flow_types_traces_and_failed_steps_at_both_tracing_levels and FlowStepCompletedTracingLevelTest::test_the_flow_step_completed_notification_starts_its_own_trace_at_both_tracing_levels prove this.
BC2 — not-apply — No stored form changes (see BC1), so there is no migration step to note.
BC7 — not-apply — The release is a major (B1). Even so, the new recording is opt-in: TracingLevel::Framework is never the default.
BC8 — not-apply — Nothing new is written to a shared queue or table:
- No new header name or payload shape.
- At the default level, `traceparent` and `tracestate` carry the same values as before.
- At the opt-in framework level, `traceparent` holds an ordinary W3C span id, the id of a step span. Any reader parses it, and the trace-tracking flags are taken from the application span (TracingInterceptor::carrierOf, packages/Ecotone/src/Tracing/TracingInterceptor.php:145).
- The in-memory queue redelivery change touches a process-local channel that no other version shares.

Verdict: PASS (0 missing)
