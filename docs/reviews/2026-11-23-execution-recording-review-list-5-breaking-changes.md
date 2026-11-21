# Breaking changes review: dgafka/implement-execution-recording

## Re-check round 5 at cd6f8a2fb, reading 71dff8a1d..cd6f8a2fb (cleanup U1 to U9)

PASSED

The range removes code; it adds no new surface, stored form or behaviour. `git diff --stat 71dff8a1d..HEAD -- upgrade-2.0.md 'packages/*/Api'` is empty.

BC3 — covered:
- **Removed from internal src/ (U4, U5):** `MessageFlowFrameworkReader`, `RecordingMessageFlowFrameworkReader`, `MessageFlowFrameworkNotRead`, `ExecutionRecordingReader::popSpans()`, `Configuration::getRegisteredBeforeSendInterceptors()` and `ExecutionHint::KEYS`. `RecordedExecution::hintLine()` became private.
- **Why it breaks nothing:** all of these were added on this branch after 2.0.0-beta.10, none sits under `Api/`, and no `Api/` signature names them.
- **Unchanged:** the public path `FlowTestSupport::popExecutionRecording()` still goes through `ExecutionRecordingReader::pop()`. Its refusal without the framework level keeps the same message (ExecutionRecordingNotSwitchedOn.php:18).
- **No reference left:** `git grep` for `build-flow:record`, `MessageFlowFrameworkReader`, `MessageFlowFrameworkNotRead`, `popSpans`, `getRegisteredBeforeSendInterceptors`, `ExecutionHint::KEYS`, `check-build-flow` and `ofRegistration` has 0 hits outside docs/reviews.
BC5 — covered:
- `ecotone:flow:dump` keeps its name and its `--format` and `--framework` options; MessageFlowModule loses only the reader registration.
- `ecotone:build-flow:record` is gone, but it was never shipped. It lived in packages/Ecotone/tests/BuildFlow and was registered only by bin/build-flow.php (known-deviations row 10), and it was added on this branch.
- The removed `compare --presentation` values and bin/check-build-flow.php are the repository's own CI tooling, not commands an application runs.
BC4 — covered — No default an application relies on changes. The default presentation of the internal `build-flow compare` tool becomes `build`, but that is CI tooling.
BC6 — covered — No behaviour an application observes changes. The upgrade-2.0.md entries of the earlier rounds stay: §14 in-memory redelivery, and the OpenTelemetry level → `TracingConfiguration::withLevel()`.
- **U9 reasoning, checked against tag 1.328.3:**
  - 1.x has no `packages/Ecotone/Api/Tracing/TracingConfiguration.php` and no `Ecotone\Tracing` in Core.
  - Its only `TracingConfiguration` is `packages/OpenTelemetry/src/Configuration/TracingConfiguration.php`, whose move and lost level upgrade-2.0.md already describes.
  - 1.x has no file with Gather in its name.
  - So the private constructor's new `level`, the two `Tracer` methods, `GatheredGroupStore::partsOf()` and refusing two `TracingConfiguration`s are all changes between unreleased 2.0 states. Under the beta rule they need no upgrade entry.
BC1, BC2 — not-apply — Nothing stored by a deployment changes. The deleted `BuildFlow/baseline` is a repository test fixture, which main's CI now commits after the merge (U1), not storage an application holds.
BC7 — not-apply — The release is a major (2.0.0-beta.10 → 2.0.x-dev), and the framework level is opt-in.
BC8 — not-apply — Nothing in this range is written to a shared queue or table.

Outside this section, for the behaviour and guidelines sessions: whether the deleted tests' coverage is kept, the U4 span expectation, the comment for a base without bin/build-flow.php, the baseline workflow's first run and the skill evals. No breaking-change requirement depends on any of them.

Verdict: PASS (0 missing)

---

## Re-check round 4 at 26b620eb0, reading 45f4a66e7..26b620eb0

PASSED

The range has two commits and touches no shipped code. a2cae2ba0 adds the review lists under docs/reviews. 26b620eb0 adds one test, EncryptedMessagesAtFrameworkTracingLevelTest, which runs an encrypted message at the framework tracing level. Neither changes an Api/ or src/ file, a stored form, a header, a console command, a default or the upgrade notes, so every decision of round 3 at 45f4a66e7 stands:
- BC3, BC4, BC5, BC6 — covered — The evidence is unchanged; see round 3.
- BC1, BC2, BC7, BC8 — not-apply — The new test reads only what an application sees. It writes nothing new to storage or a queue: the payload staying encrypted on its channel is existing behaviour, which the test asserts.

Verdict: PASS (0 missing)

---

## Re-check round 3 at 45f4a66e7, reading 13753bc8b..45f4a66e7

PASSED

BC3 — covered:
- **The fix:** packages/Ecotone/src/Tracing/Execution/RecordedStreamSource.php:29 calls `$this->streamSource->load($projectionName, $lastPosition, $count, $partitionKey)` positionally again (0a7202aa1). `RecordedConversionService::canConvert()` is positional too.
- **The proof:** ExecutionRecordingStreamSourceTest::test_a_stream_source_of_the_application_naming_its_parameters_its_own_way_is_loaded_and_recorded. Its `#[StreamSource]` declares `load(string $name, ?string $from, int $limit, ?string $partition = null)` at TracingLevel::Framework. I ran it in the app container: OK (1 test, 2 assertions).
- **The other named calls:** I checked them against the report's table, and they all go to interfaces only Ecotone implements: `Tracer`, `ProjectorExecutor`, `PartitionBatchDispatcher`, `GatheredGroupStore` and `AggregateMethodInvoker`, plus the parent classes. known-deviations row 18 records the positional-call rule.
- **The rename (e0d9e0cc6):** `MessageFlowBuild*` became `MessageFlowFramework*` (`MessageFlowBuild` is `MessageFlowFrameworkLedger`; `RecordingMessageFlowBuildReader` is `RecordingMessageFlowFrameworkReader`). All of them are internal classes under `src/` that this branch added after 2.0.0-beta.10, and no `Api/` file names them. No old name remains in packages or bin, apart from an untracked stale Laravel test container cache under packages/Laravel/tests/Application/storage. The `MessageFlowConsoleCommands::dump()` service parameters were renamed too, but the container injects them, so they are not console options.
BC5 — covered — `ecotone:flow:dump` keeps its name and its `--format` and `--framework` options. The JSON key `framework` is unchanged in this range. 45f4a66e7 changes only the tooling's text. The build-flow commands and `bin/build-flow.php` are test tooling, which the package does not ship (known-deviations row 10). Keeping the snapshot key `build` and the tool's name (row 6a) changes nothing an application reads.
BC4 — covered — No default changes in this range.
BC6 — covered — The new behaviour (834e5d52e: the PR comment shows the two most changed scenarios and every new one, and counts what it does not show) lives in CI tooling: bin/build-flow-comment.php and the workflows. No application can observe it. Unchanged from the previous rounds: upgrade-2.0.md §14 has the in-memory redelivery entry.
BC1, BC2 — not-apply — Nothing stored changes. The snapshot key `build` is kept, so the committed baselines keep their format.
BC7 — not-apply — The release is a major (2.0.0-beta.10 → 2.0.x-dev), and the framework level is opt-in.
BC8 — not-apply — Nothing in this range is written to a shared queue or table.

Verdict: PASS (0 missing)

---

## Re-check (rule P3a) at 13753bc8b, reading c74807b4e..HEAD

NOT PASSED: BC3

1. The first round had no missing requirements, so there was nothing to re-cover.
2. One requirement changes status: BC3 goes from covered to missing, because of 5681626e7 (GL15, named arguments).

BC3 — missing — packages/Ecotone/src/Tracing/Execution/RecordedStreamSource.php:29.
- **What breaks:** at the framework tracing level, the recording now calls the wrapped stream source with named arguments: `$this->streamSource->load(projectionName: …, lastPosition: …, count: …, partitionKey: …)`. A stream source is something an application implements itself: a class marked with the Enterprise `#[StreamSource]` attribute (packages/Ecotone/Api/Projecting/StreamSource.php, documented under "custom extensions") that implements `Ecotone\Projecting\StreamSource::load()`. PHP resolves named arguments against the implementing class. So an application whose stream source names its parameters differently (for example `load(string $name, ?string $from, int $limit, ?string $partition = null)`, which PHP allows) now fails every projection load with `Error: Unknown named parameter $projectionName` as soon as it switches on `TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)`. I reproduced this in the app container with that signature. At c74807b4e the call was positional and worked.
- **The fix:** call extension points that applications implement with positional arguments, as `$this->streamSource->load($projectionName, $lastPosition, $count, $partitionKey)`. Record this as the exception to GL15 in known-deviations. Add a test: a custom `#[StreamSource]` whose parameter names differ from the interface, run at `TracingLevel::Framework`, still projects and is recorded.
- **Not affected:** the other named-argument calls this commit added go to internal interfaces that only Ecotone implements: the tracer, `ProjectorExecutor`, `PartitionBatchDispatcher`, `GatheredGroupStore`, the parent `InstantRetryInterceptor` and `MessagingEntrypointService` classes, and the conversion service.
- **Skills:** framework-public-api, framework-testing.

BC4 — covered — Unchanged from the first round. No new commit changes a default.
BC5 — covered — `ecotone:flow:dump` keeps its name and `--format`. The `--build` flag became `--framework` (5eedf5844), and with it the JSON key `build` became `framework` and the Mermaid class became `framework` (packages/Ecotone/Api/Messaging/Flow/MessageFlowGraph.php:240). All three appeared on this branch after 2.0.0-beta.10, which has only `--format` (MessageFlowConsoleCommands::dump at beta.10). No released name changes. Without the framework level, `--framework` is refused with the way out (MessageFlowConsoleCommandsWithoutFramework.php:21).
BC6 — covered — Unchanged: upgrade-2.0.md §14 has the in-memory redelivery entry, backed by InMemoryQueueRedeliveryTest. The new commits add no other behaviour change an application observes: they change the scenario catalogue, the CI check, the PR comment rendering and the build presentation, and add a scenario.
BC1, BC2 — not-apply — The new commits touch no table, column, metadata key, persisted header or payload shape. The regenerated baselines are committed test fixtures, not storage that a deployment holds.
BC7 — not-apply — The release is a major (2.0.0-beta.10 → 2.0.x-dev), and the framework level stays opt-in.
BC8 — not-apply — The new commits change nothing that is written to a shared queue or table.

Verdict: NOT PASS (1 missing: BC3)

---

## First round at c74807b4e (range origin/main...HEAD)

PASSED

Release (B1): the latest tag is 2.0.0-beta.10 and the branch alias is dev-main => 2.0.x-dev (packages/Ecotone/composer.json:86). The change ships in a 2.0 pre-release, which is part of the 1.x -> 2.0 major. The default tracing level stays TracingLevel::Application, and at that level the code paths behave as before (EcotoneTracer::currentApplicationSpan and OpenTelemetryTracer::currentApplicationSpan both return currentSpan()).

BC3 — covered — The Api/ signature changes are all safe:
- `TracingConfiguration` gains `level` at the end of a private constructor; `createWithDefaults()` defaults it to Application.
- New `withLevel()` method, new enum `TracingLevel`, new final Recorded* read models, new `SpanAttribute` constants and new `FlowTestSupport::popExecutionRecording()`.
- The `MessageFlowGraph::toMermaid()` marks appear only for framework-level nodes.
- The interfaces that gained methods are internal to src/.
BC4 — covered — No default changes. The level defaults to Application and `--format` to mermaid.
BC5 — covered — `ecotone:flow:dump` keeps its name and `--format`; only an optional flag is added.
BC6 — covered — upgrade-2.0.md §14 has the in-memory redelivery entry, backed by InMemoryQueueRedeliveryTest. The OpenTelemetry level note points to TracingConfiguration::withLevel().
BC1 — not-apply — Nothing stored changes. DbalGatheredGroupStore::partsOf only reads. StoringSpanRecorder keeps the application form at both levels, proved by PulseTracesTest::test_pulse_shows_the_same_flow_types_traces_and_failed_steps_at_both_tracing_levels.
BC2 — not-apply — No stored form changes.
BC7 — not-apply — The release is a major, and the framework level is opt-in.
BC8 — not-apply — No new header names or payload shapes go to a shared queue. At the framework level `traceparent` is still a valid W3C span id, and the in-memory queue is process-local.
