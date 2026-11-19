# Sixth pre-PR round: execution recording, the framework level exports its steps (head 17e7ec368)

The maintainer changed the U5 decision after W2: the level may be set whatever tracer is used, and whoever picks
`TracingLevel::Framework` gets every step as a trace span, in large numbers, by their own choice. This replaces W2
(not recording with a tracer only) and settles W3.

- Y1. At the framework level the recording runs whenever a span recorder or a tracer is configured (W2's condition
  goes), and every recorded step is a real span started and ended through the configured tracer, so an OpenTelemetry
  tracer exports it: step label as the span name, the hints as attributes, a failed step with an error status,
  parent = the open step or span (the round-4 "exporting the steps as child spans" option).
  - The trace at the framework level is the application-level spans plus the step spans between them. An
    application-level span keeps its name, kind, status and own attributes; its parent may now be a step span, and a
    message sent from inside a step carries that step as its trace parent. No `currentSpan()` that skips steps.
  - The application level exports exactly what it does on `main`. Prove it.
  - `ecotone.execution.order` and `process`/`parent`: with steps exported, `parent` always names an exported span.
    Keep what the reader needs; say which of these attributes are still exported and why.
  - Span recorders, `popExecutionRecording()`, `ecotone:build-flow:record`, the baselines and the #771 files read the
    same as today. If a baseline changes, stop and report the difference before regenerating.
  - If this needs a change to the `Tracer` interface, make the smallest one and say exactly what changed and which
    implementations it touched.
- Y2. State stays bounded: the L1 to L8 and V1 guarantees hold with steps on the tracer. Rework the OpenTelemetry
  memory test through `EcotoneLite::bootstrap` (queue drained, each level's growth bounded) for the new behaviour, and
  keep the leak tests green.
- Y3. Tests through userland with OpenTelemetry (in-memory exporter), in flow testing and through
  `EcotoneLite::bootstrap`: at the framework level the exported trace contains the steps as children in the recorded
  order for a synchronous command, an asynchronous handler (one trace across the channel), a failing handler, and a
  distributed hop; no span left open after a failure; the application level is unchanged.
- Y4. Every text says it: the `--build` refusal (the level exports every step Ecotone runs as a span through the
  configured tracer, many more spans per message, and sends them to span recorders), `workflow.md`, row 3h, the public
  skill's OpenTelemetry bullet and `api-reference.md`, and W2's wording removed.
