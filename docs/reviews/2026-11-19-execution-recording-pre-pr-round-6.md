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

Added after Y1 to Y4, maintainer decisions:

- Z1. The `ecotone:build-flow:*` commands (`BuildFlowConsoleCommands`) and whatever exists only for them (scenario
  catalogue runner, snapshot files, comparison and its Markdown presentations, as far as nothing an application uses
  needs them) move out of `src/` into `packages/Ecotone/tests/`, registered only by the framework's own tests and
  `bin/build-flow.php`. What an application uses stays shipped (`ecotone:flow:dump` with `--build`, the recording
  reader, `popExecutionRecording()`); classes needed by both are listed. Stop and report if the move pulls along
  something an application-facing feature depends on or a split package's autoload cannot reach it. Row 10 removed or
  reduced; `workflow.md` and skill texts naming the old path updated; `bin/build-flow.php`, the CI baseline check and
  the #771 files work as before; baselines unchanged.
- Z2. Main keeps the snapshot: a workflow on `push` to `main` regenerates the baseline and commits it when it changed,
  without looping and without losing a snapshot between close merges. Pull requests no longer fail when the baseline
  differs; `workflow.md` says contributors leave it alone. Report the token and repository setting the push needs and
  whether other workflows run on that commit.
- Z3. A pull request gets one comment with `bin/build-flow.php compare` (before-after, GitHub Markdown, Mermaid),
  updated in place on every push by a hidden marker; "no flow changed" updates an existing comment and creates none.
  Report only: fails only when the tooling breaks.
- Z4. Forks: a `pull_request` workflow without write permission uploads the body and pull request number as an
  artifact; a `workflow_run` workflow with `pull-requests: write` only posts it, never runs pull request code, treats
  the artifact as data (number validated and checked against the triggering run), least permissions per job.
- Z5. The comment body is built by a tested PHP script under `bin/`: heading, marker, compare output, the "no flow
  changed" form, GitHub's 65,536 character limit (whole scenarios kept from the top, how many left out, full
  comparison in the uploaded artifact), Mermaid blocks under what GitHub renders; the #771 comment written to
  the #771 comment with its size.

Added after Z5, maintainer decisions (head 0f6673e3e):

- Z6. The pull request comment always shows a diagram: `build-flow-comparison.yml` builds it from
  `php bin/build-flow.php compare --presentation=path`. When a scenario also changes its wiring, the comment still
  shows that wiring change; report what `path` shows for a wiring change and, if the wiring diagram is missing, add it
  collapsed in `<details>` (use `build` if it is the right presentation, no fifth one; say which and why). The Z5 size
  limits hold with the diagram first: a diagram is never the part that is cut. Regenerate the #771 comment.
- Z7. The sequence diagram's Before and After blocks (`rect rgb(255, 235, 233)`, `rect rgb(218, 251, 225)`) stay
  readable in GitHub's dark theme: check and change the fills if needed (translucent `rgba(...)`), with the test that
  pins the output. Baselines store no Markdown and should not change.
