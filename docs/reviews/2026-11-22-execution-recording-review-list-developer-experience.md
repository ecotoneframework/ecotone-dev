# Developer experience review: execution recording (head c74807b4e, range origin/main...HEAD)

NOT PASSED: DX9

## Usage for the most common case (rule X1)

```php
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;

$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderService::class],
    [new OrderService()],
    [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
);
$ecotone->sendCommand(new PlaceOrder('123'));
$recording = $ecotone->popExecutionRecording();   // processes[] (title, steps[depth, label, hints], sentBy), lines, linesWithEveryHint
```

Required of the user: two imports (`TracingConfiguration`, `TracingLevel`), one configuration object with one option, one read. No span recorder, no service registered. Second surface: `ecotone:flow:dump --build` (Enterprise), which needs the same level, returned from a `#[ServiceContext]` limited with `#[Environment]` to dev/test. Maintainer surface: the pull request comment (`var/771/pr-comment.md`) and `bin/build-flow.php`.

## Decisions

- DX9 — missing — packages/Ecotone/src/Messaging/Config/Flow/MessageFlowConsoleCommands.php:18 (and MessageFlowConsoleCommandsWithoutBuild.php:19) — the application-facing option `ecotone:flow:dump --build` uses an implementation word: an application developer reads `--build` as an action ("build, then dump") or as the container build, and cannot tell from the name that it adds the buses, interceptors, routing, channels and consumers Ecotone registers itself; the help text has to explain it. Simpler form: `ecotone:flow:dump --framework` (`bool $framework = false`), the same word as `TracingLevel::Framework`, which it requires, so the pair reads "the framework level shows the framework's part": `ecotone:flow:dump --framework --format=json`. Renamed with it to keep one word per concept on the application surface: the JSON key `build` of the dump (MessageFlowConsoleCommands.php:32) → `framework`; the node/edge attribute `build` and the Mermaid `classDef build` (Api/Messaging/Flow/MessageFlowGraph.php:240-243, MessageFlowBuildGraphBuilder.php:83, MessageFlowBuildWiringDiscovery.php:126) → `framework`; "build wiring" in the refusal texts (MessageFlowBuildNotRead.php, MessageFlowConsoleCommandsWithoutBuild.php) → "framework wiring"; the `--build` description and refusal wording, the tests calling `['build' => true]` (MessageFlowBuildWiringTest, MessageFlowBuildTracingLevelTest). The framework-development tooling (`bin/build-flow.php`, `compare --presentation=build`, the build-flow workflows, the baseline, `MessageFlowBuild*` src classes) is maintainer vocabulary, not application surface, and may keep "build"; renaming it too is the maintainer's choice. — skill: framework-public-api
- DX1 — covered — the usage above: two imports and one call are each needed (configuration object, the level enum, the reader on `FlowTestSupport` next to `popRecordedEvents()`); the flow test registers its own span recorder (TracingModule.php `readsTheRecording` branch), so no `InMemorySpanRecorder` is wired by hand; api/skills/ecotone-testing/SKILL.md "What Ecotone Ran for a Message" writes the same code.
- DX2 — covered — switched on by a configuration object passed as an extension object or returned from `#[ServiceContext]` (`TracingConfiguration::withLevel()`, Api/Tracing/TracingConfiguration.php:34), the shape of the existing `withSpanRecorder()`/`withSpanAttributesFromHeaders()`; read with one `FlowTestSupport` method (FlowTestSupport.php:553). No registry, base class or interface.
- DX3 — covered — the level is set once in Core `TracingConfiguration`; the OpenTelemetry level that could never be set is gone (upgrade-2.0.md §OpenTelemetry), and a second `TracingConfiguration` is refused with a message naming the fix (FrameworkTracing.php `configurationOf`).
- DX4 — covered — `createWithDefaults()` sets `TracingLevel::Application`; the recording needs only the level, no recorder or tracer; asking without it is refused naming the switch (ExecutionRecordingNotSwitchedOn.php). No required option.
- DX5 — covered — the one new option `withLevel()` has two meaningful values; `lines` vs `linesWithEveryHint` and `RecordedHint::shownInLines` each serve a reader (short text vs every hint); the `SpanAttribute::EXECUTION_*` constants are read by span consumers and documented one by one in the testing skill.
- DX6 — covered — neighbours: tracing default stays the application level (as before), Pulse and flow tracking show the same at both levels (skill text), `popExecutionRecording()` pops-and-clears like `popRecordedEvents()`; the recording is opt-in where recorded events are on by default, justified by the 8–10× span cost stated in the skill and the `--build` refusal.
- DX7 — covered — no default affecting delivery, retries, transactions or data is added; the in-memory requeue change (upgrade-2.0.md §14) makes a retried message keep its headers and trace, the safer side, matching DBAL/AMQP; the PR comparison workflow is report-only but fails red when its tooling breaks and the comment is posted only on success (build-flow-comparison.yml, build-flow-comment.yml), so no failure is hidden.
- DX8 — not-apply — no less safe behaviour is offered: `TracingLevel::Framework` costs more spans, it does not trade message or data safety, and its cost is stated where it is switched on.
- DX10 — covered — `TracingLevel::Framework` keeps 1.x's "framework level" name (upgrade-2.0.md), `pop*` follows `popRecordedEvents()`/`popRecordedCommands()`, "process", "step", "hint", "sub-flow" are used the same in the Api classes, the skill and the PR comment; the comment's terms (`hand-over`, `chosen by: licence, over X`, `{generated#1}`, gathering group, part report) are defined in the linked glossary (framework-dev-environment/references/workflow.md "Reading the flow comparison").

Verdict: NOT PASS (1 missing: DX9)
