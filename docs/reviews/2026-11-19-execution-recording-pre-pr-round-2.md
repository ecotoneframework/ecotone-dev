# Second pre-PR review round: execution recording (reviewed at 1342bdf1e)

Fresh Guidelines (G), Breaking changes (B), Developer experience (X) and Behaviour (V) sessions reviewed
`main...HEAD`. Behaviour reproduced the same outcome at both levels over all 94 scenarios, identical OpenTelemetry
exports and constant recorder state over 3000 consumed messages, and found one defect (V1). An item that does not
reproduce is reported, not changed. Not part of this list: commit subjects and bodies (G7, at publication), the
presentation rename `build` and hiding tracing's own steps from `lines` (left for the maintainer).

## Blocking

- G1. `packages/Dbal/tests/Integration/GatheredFlowExecutionRecordingTest.php:72` (import `:22`): the test drops an
  Ecotone table and names `DbalGatheredGroupStore`. Produce the failing read through a seam the application owns (the
  connection it hands Ecotone starts failing once a flag is set); no SQL on Ecotone tables, no `src/` class. Row 3h
  cites this test; keep the citation true.
- X1. The framework level must not push an application towards unbounded memory. `FrameworkTracing.php:17`
  (`SWITCH_ON`), `MessageFlowConsoleCommandsWithoutBuild.php:21`, `MessageFlowBuildNotRead.php:28`,
  `TracingModule.php:84-86`: the `--build` refusal tells a user to add `InMemorySpanRecorder` to a
  `#[ServiceContext]`, which grows forever in a consumer. The level alone switches the build view on; reading the
  wiring needs no span recorder. The "no span recorder" refusal moves to where a recording is read. Every message
  naming `InMemorySpanRecorder` says it is for tests and that it keeps every span until cleared.

## Should fix

- X2. In flow testing the framework level works with one line:
  `TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)`. Flow testing registers its own
  in-memory recorder for the reader when the application configured none. Outside flow testing nothing changes.
  Simplify the tests and the skill text that set the recorder up only for this.
- X3. The recording is read and cleared, like `popRecordedEvents()`: `popExecutionRecording()` replaces
  `getExecutionRecording()`, returning what was recorded since the last pop (unreleased, no alias). Tests that cleared
  the recorder by hand use it. This settles L3: reading is a flow-testing operation, bounded by pop.
- X4. `Api/Tracing/RecordedSender.php:13-14`: `process` is 1-based and `step` 0-based. Make both the numbers the text
  prints or both array indexes, named so; the JSON export and snapshots follow, baselines regenerated.
- X5. `Api/Tracing/RecordedHint.php:15`: `critical` reads as severity. Name it for what it means across the reader,
  JSON export, snapshots and skill text (one word, rule 6a).
- X6. Refusal wording: `ExecutionRecordingNotSwitchedOn.php:18` says an application without tracing "traces at
  TracingLevel::Application" and omits that a recorder must be a service. `TracingModule.php:85` likewise.
- X7. `FlowComparisonMarkdown.php:313,316,333,345`, `BuildFlowConsoleCommands.php:59`: "Traced steps" lists spans; say
  "traced spans". Also `:49` grammar ("1 of 1 scenario changes", "2 of 5 scenarios change").
- X8. `api/skills/ecotone-testing/SKILL.md` gains a short section on reading what Ecotone ran for a message (userland
  only, the one-line setup, pop, `lines` vs `linesWithEveryHint`, the `ecotone.execution.*` attributes on exported
  OpenTelemetry spans), with its eval grader (`framework-agent-tooling` A3, A4).
- B1. In-memory redelivery (F5) keeps everything interceptors below precedence 0 did (`#[AddHeader]`,
  `#[RemoveHeader]`, `#[Delayed]`, `#[Priority]`, `#[TimeToLive]`, negative-precedence `#[Presend]` /
  `#[ChannelInterceptor]`), as on DBAL and AMQP; 1.x dropped them. Add the `upgrade-2.0.md` entry and tests in
  `InMemoryQueueRedeliveryTest` for `#[AddHeader]`, `#[RemoveHeader]` and `#[Delayed]` (not delayed a second time).
- B2. The recording reader with the tracing module disabled, and `ecotone:build-flow:record` without an Enterprise
  licence, end in "service not found". Refuse with the explanatory message in both.
- G2. `BuildFlowBaselineTest.php:144`, `BuildFlowPresentationTest.php:165`: `expectException` with a `src/` exception
  class; keep `expectExceptionMessage` only.
- G3. Eight test files construct `Ecotone\Test\Flow\Snapshot\BuildFlowConsoleCommands`. Report first what registering
  the `ecotone:build-flow:*` commands from the framework level would mean; if that puts them into every application
  at the framework level, stop and ask.
- G4. Five refusals without a message test: `FlowSnapshots.php:60`, `FlowScenarioRecorder.php:32,59`,
  `FlowSnapshot.php:110,250`. One test each through the commands, or remove an unreachable one.
- G5. `InMemoryExecutionRecordingReader.php:11,37` builds the public recording through
  `Ecotone\Test\Flow\Snapshot\FlowExecution`. Move the span-to-recording construction into
  `Ecotone\Tracing\Execution`; `Test\Flow\Snapshot` calls it.
- G6. Named arguments on new calls with three or more arguments (listed sites), and a sweep of the rest of the new
  code.
- V1. `RecordedEndpointRunner.php:22`, `ExecutionRecordingTracer::suspendOpenEntry()`: a consumer started inside a
  handler is recorded detached and out of order. Expected: the run nested in the handler step between the two sends.
  Suggested: suspend only for a top-level run. Test in `ExecutionRecordingStateLeakTest`; name the case in row 3h.

## Nits

- V2. Say in the L list that L4, L5 and L6 are defensive and not reachable from userland; give the red run on the base
  commit for the L1, L2 and F5 tests in the report.
- V3. `MessageFlowBuildTracingLevelTest`: add the unlicensed path. `FrameworkTracingLevelTest`: compare the
  non-execution attributes, events and order too, on more than one flow.
- V4. At the framework level a handler reading `#[Headers]` sees the acknowledgement header as
  `RecordedAcknowledgementCallback`. Report whether the recording can observe the acknowledgement without replacing
  the object an application sees; fix if contained.
- G8. `Ecotone\Tracing\Execution\RecordedStep` shares its name with `Ecotone\Api\Tracing\RecordedStep`; rename the
  helper.
- G9. `TracingConfiguration.php:20`: drop the constructor default, pass the level in `createWithDefaults()`.
- G10. `references/workflow.md:221` rewrap at 120.
- B3. `MessageFlowModule.php:59` resolves `TracingConfiguration` also when the tracing package is disabled; read the
  level only when it is enabled.
- X9. `BuildFlowConsoleCommands.php:59`: the suggested `snapshot` command repeats the `--location` given.
- X10. Wording of three labels, changed only if the baselines stay readable: "no hand-over", `chosen by: licence,
  over …`, `sub-flow process N` vs `sentBy`.
