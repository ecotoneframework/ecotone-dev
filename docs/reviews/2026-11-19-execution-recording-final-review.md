# Final review round: execution recording (reviewed at 8d5f41520)

Fresh Guidelines (G), Breaking changes (B), Developer experience (X) and Behaviour (V) sessions reviewed `main...HEAD`.
Breaking changes: nothing blocking, the application level exports what `main` does. Left as is: `RecordedSender`'s
1-based numbers (decided in X4 of round 2).

## Blocking

- X1. A pull request's comparison must not read the pull request's own baseline: the comparison job takes the "before"
  from the base commit (snapshot `github.event.pull_request.base.sha` in a second directory, `compare --from` it), or
  the baseline directory of the base sha if that is not workable; state the added time. Reword the `check` refusal:
  meant to change → leave the baseline, the comment shows it, main's CI commits it, `compare` shows it locally; not
  meant to change → wiring or traced spans changed by accident.
- G1. `build-flow` is listed as a deterministic check run by `file-licence.yml` and no workflow runs it: remove it from
  `DETERMINISTIC_CHECKS` and the Checks table (it stays a local command), and make `check-agent-skills.php` (with its
  test) check that every listed check's command appears in the workflow it names.
- V1. An Enterprise multi-tenant DBAL application does not boot at the framework level (`MessageFlowChainReader`
  resolves the class from the `Reference` id, an interface without the interceptor method). Resolve the class from the
  definition behind the reference, never fail the boot for a method the build reader cannot describe, test the general
  case beside the multi-tenant one, run `FlowTrackingConformanceTest` at the framework level, and report how no other
  shipped module's wiring breaks the boot at the framework level.

## Should fix

- X2. `bin/build-flow.php snapshot` without `--location` writes under `var/`; the baseline only with the explicit
  location; usage says main's CI regenerates it after a merge.
- X3. The comment says what is compared, names the commit, how to reproduce locally, that the baseline is not
  regenerated in the pull request, and explains the terms a reviewer meets (legend or a glossary in `workflow.md`).
- X4. The no-change form: "As of `<sha>`, this pull request changes the message path of none of the N catalogue
  scenarios."
- X5. The `--build` refusal names the safe way out: the framework level from a `#[ServiceContext]` limited to dev and
  test (`#[Environment]`).
- G2. `currentSpan()` only reads: step spans are created where state already changes.
- G3. `RecordExecutionCompilerPass.php:210` `new ReflectionMethod`: from `InterfaceToCallRegistry`, or a
  known-deviations row.
- B1. A detached span that is never ended stays in `OpenTelemetryTracer::$detachedSpans`: show from userland whether a
  step can stay unended, fix what can, name the bound in row 3h.
- V2. `startRootSpan` stays a root at both levels; the OpenTelemetry test also asserts the trace grouping.
- V3. The "Build: what changes" tree is bounded in the comment, with the same left-out note.
- V4. A replaced over-long message-path diagram does not make the cut land inside `<details>`.
- V5. The comment length is counted in the same (stricter) unit in PHP and the workflow.
- V6. Exported step span names with per-build generated ids: where they come from, a stable name with the id as an
  attribute if contained, else options.
- V7. Workflows: a push refused by protection is told apart from `main` moving and names `BUILD_FLOW_BASELINE_TOKEN`;
  dependencies reinstalled after reset; reproducible installs; a stale comment names its commit;
  `BuildFlowExecutionTest` independent of the committed baseline; what bounds the posted body, `@` mentions
  neutralised outside code if cheap.

## Nits

- B2. `upgrade-2.0.md`: the framework level is `TracingConfiguration::withLevel(TracingLevel::Framework)`; the
  redelivery note may mention a non-idempotent negative-precedence `#[ChannelInterceptor]`.
- G4. Duplicated `@param` in `OpenTelemetryTracer`. G5. Named arguments in `RecordedGatheredGroupStore`.
  G6. `GatheredGroupStore::recordedPartsOf` → `partsOf`. G7. `InMemoryGatheredGroupStore` returns part numbers sorted,
  pinned in the shared suite. G8. `RejectMessageException` in `ExecutionRecordingSameOutcomeTest` recorded as a
  rule 12 gap.
- X6. `ExecutionRecordingNotSwitchedOn` names `popExecutionRecording()` and the flow test; `MessageFlowModule.php:92`
  "returned from a `#[ServiceContext]`". X7. "16 spans instead of 2" pinned or "about eight times as many".
  X8. The skill says what each `ecotone.execution.*` attribute holds.
