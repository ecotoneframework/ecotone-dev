# Maintainer decisions: execution recording (M1, M2)

Head 3dcfb073a. Still open and not part of this list: whether per-message state in the recording services is
accepted (row 3h of known-deviations stays as it is), and widening the catalogue (a later unit).

- M1. The flow-build compile step is part of the framework-level tracing mode. Maintainer: "that should be enabled
  framework tracing level mode". Today `MessageFlowModule` always adds `MessageFlowBuildCompilerPass` (under the
  Enterprise licence). Add it only when the application asked for the framework-level tracing mode, which today is
  `TracingConfiguration::withExecutionRecording()`. The method is not renamed here; the maintainer is being asked
  whether the mode gets a level name, so the gate sits in one place a rename touches once.
  - Everything that needs what the pass builds (`ecotone:flow:dump --build`, `ecotone:build-flow:record`,
    `bin/build-flow.php`, the baselines) either runs with the mode on or refuses at bootstrap or at the command
    with a message naming the way out (`framework-error-handling` rules 1 and 1a). Never an empty result.
  - A test proves an application without the mode does not run the pass's work (the build part is refused, and
    nothing else about `ecotone:flow:dump` changes), and one with the mode that it does.
  - Report what container build costs with and without the mode if it is cheap to measure (one application of the
    catalogue, compile time).
  - If gating needs more than a condition on the configuration the module already reads, stop and ask.
- M2. A recording is read through a public `Api/` reader. Maintainer: "public api". Today tests and tools read it
  through `Ecotone\Test\Flow\Snapshot\FlowExecution`, a `src/` class (testing rule 10: a test names no class outside
  `Api/`).
  - The reader lives under `packages/Ecotone/Api/` following `framework-public-api` (rules 6, 6a, 6b, 12, 12a):
    immutable value classes with promoted `public readonly` properties, no `src/` type in any signature an
    application calls, one word per concept from `withExecutionRecording()` to the reader (recording, process,
    step, hint, sub-flow).
  - An application gets it from the flow-testing support it already holds, in one call. It exposes what the stored
    snapshot has: processes in run order, for each its title, its steps (depth, label, hints with key, value and
    critical), and the sender (process and step) when it is a sub-flow; plus the default text form. Asking for it
    without the mode is refused with a message naming `withExecutionRecording()`.
  - Every test under `packages/*/tests` that reads a recording moves to the reader; none names `FlowExecution` or
    another `src/` class for it. `FlowExecution` stays internal for snapshots and comparison, built on the same
    data so the two never disagree.
- M3. The tracing level is defined in the public configuration. Maintainer: "yes allow level to be defined, if that
  works for integrations like opentelemetry".
  - `TracingConfiguration` takes a level; the framework level is what `withExecutionRecording()` switches on today
    (the recording and, since M1, the flow-build compile step). `withExecutionRecording()` is replaced by the level
    (unreleased, no alias), one word per concept through configuration, refusal messages, skills and docs
    (`framework-public-api` 6a, 6b). The default level is today's behaviour without the recording.
  - It has to work with an integration that brings its own tracer. Today the recording is refused when the
    application provides a `TracerProviderInterface` (OpenTelemetry). First report what the framework level does and
    should do there: which framework-level steps reach an OpenTelemetry exporter as spans, whether the M2 reader
    still returns the recording, and what stops it today. If making the level work with OpenTelemetry is a contained
    change (the recording observes alongside the provided tracer), do it, with tests in the OpenTelemetry package at
    both levels proving the exported spans of the default level are unchanged. If it needs a redesign of how the
    recorder and a tracer share spans, stop and ask with the options.
  - Every unsupported combination is refused at bootstrap with a message test, never read as the default level.
- M4. Per-message state in tracing services is accepted. Maintainer: "it will be fine to allow for it for this
  context to keep state, but let's review that there is no situation in which this state can leak".
  - Row 3h of `known-deviations.md` states the acceptance and also covers what tracing on `main` already keeps:
    `EcotoneTracer::$activeSpans`, `OpenTelemetryTracer::$activeSpans` and the mutable fields of the two active span
    classes, each with what bounds it.
  - A separate read-only leak review of every mutable field runs elsewhere; its findings follow as their own brief.
