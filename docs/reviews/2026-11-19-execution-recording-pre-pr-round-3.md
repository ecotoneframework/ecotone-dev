# Third pre-PR review round: execution recording (reviewed at c15a00786)

Fresh Guidelines, Developer experience and Behaviour sessions re-reviewed the branch: no blocking finding. Behaviour
reproduced the same outcome on 94/94 scenarios, identical acknowledgement behaviour at every level on in-memory,
dynamic and DBAL channels, no recorder leak over 1,800 messages in 18 variants, three clean flow-suite runs, every
skill eval at 1.00.

## Should fix

- T1. The framework level in a running application with an integration's tracer: `recordsExecutionIn` requires a span
  recorder, so with OpenTelemetry through `EcotoneLite::bootstrap` (not flow testing) the level records nothing, while
  the same setup in flow testing exports `ecotone.execution.*`. Record when the level is Framework and a span recorder
  or a tracer reference exists. OpenTelemetry tests through `EcotoneLite::bootstrap`: exported spans carry
  `ecotone.execution.*`, names, kinds, statuses and parents equal the application level, memory flat over many
  consumed messages. `workflow.md`, row 3h and the public skill say what a running application gets in each case.
- T2. Flow testing owns a private recorder: registered only by `EcotoneLite::bootstrapFlowTesting()` at the framework
  level (not whenever the test package is enabled), and always there, also when the application configured its own
  span recorders; `popExecutionRecording()` reads and clears only it. The refusal naming `InMemorySpanRecorder` goes
  away if nothing reaches it; `ecotone:build-flow:record` reads the same way.
- T3. Reword the `ecotone:flow:dump --build` refusal: what it shows, the level it needs, what the level does at
  runtime, and the way out without `--build`.
- T4. `GatheredFlowExecutionRecordingTest`: with deduplication on, the first failing read is likely not the group
  store read. Turn deduplication (and the asynchronous transaction if needed) off and assert in the recorded lines that
  the failing step is the group-store read.
- T5. Named arguments, the rest of the sweep (listed sites), then grep the new code once more.
- T6. Public skill `ecotone-testing`: the sample's imports, the `popExecutionRecording()` row in
  `references/api-reference.md`, `processNumber`/`stepNumber` count from 1 with the one-line lookup, the OpenTelemetry
  bullet after T1.

## Nits

- T7. A handler acknowledging by hand is no longer recorded since V4. Record that the acknowledgement is left to the
  handler, without replacing the object the handler sees.
- T8. Row 3h: `$collectors` and "suspends the open entry" hold for a top-level run only. Row 10:
  `FlowScenarioRecorder.php:62` also fires when a framework change breaks a scenario.
- T9. `bin/test-agent-skills` copies the ignored `api/evals/results/` into the throwaway plugin; exclude it.
- T10. `upgrade-2.0.md` §9a: plain wording; name `#[Priority]`, `#[TimeToLive]` and `#[Presend]` among the unaffected;
  move it out of "Changed defaults" if a fitting section exists.
- T11. At the framework level the `exception-stacktrace` header of an error-channel or dead-letter message holds
  recording wrapper frames. Say so in the skill; do not filter the trace.
- T12. `FrameworkTracingLevelTest` has no test through `EcotoneLite::bootstrap` (covered by T1).
