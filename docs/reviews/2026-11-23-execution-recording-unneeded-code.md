# What the pull request carries without needing it (head 71dff8a1d)

The maintainer asked what the pull request carries without needing it. An audit of `git diff origin/main...HEAD`
found 12 removable items; a check of the branch with the framework tracing level off found the contract changes
an application or integrator meets whatever the level. The list below is what is removed or checked. A removal is
a refactor: the tests that stay prove what the removed tests proved about code that stays.

## Decided by the maintainer

- U1. (audit #1 and #6) Remove the committed baseline `packages/Ecotone/tests/BuildFlow/baseline/` (95 files) and
  the comparison's fallback to it (`build-flow-comparison.yml` else-branch and `compared-with` output,
  `bin/build-flow-comment.php` `--compared-with=committed-baseline` and its wording, its two test cases). Main's CI
  creates the baseline after merge. A pull request whose base has no `bin/build-flow.php` gets no comparison and no
  comment, the job green, with a log line saying to merge main. Local `compare` / `check` with no baseline present
  refuse with a message naming the way out (`snapshot --location=…`), tested. The baseline workflow's first run on
  main works from an absent directory. Skill and workflow.md text follow.
- U2. (audit #2) Delete review-list rounds 1 to 3 (12 files); round 4 stays.
- U3. (audit #3) Remove the `before-after`, `marked` and `path` presentations and the diff-style "What ran" with
  everything only they reach; `build` is the only Markdown view and the default, `json` stays. Tests, usage text,
  command description and the workflow.md table follow.
- U4. (audit #5) Remove `ecotone:build-flow:record`, `MessageFlowFrameworkReader`,
  `RecordingMessageFlowFrameworkReader`, `MessageFlowFrameworkNotRead`, their registrations,
  `ExecutionRecordingReader::popSpans()` and its implementations, `FlowSnapshots::store()` and
  `FlowSnapshot::toJson()` if nothing else needs them; tests move to `FlowScenarioRecorder::snapshotOf()`.
  Known-deviations row 10 and workflow.md are reworded.
- Not to do: audit #4 (`FlowExecutionChange`) stays as it is. What U3 leaves unreachable in it is listed, not
  removed.

## Decided by the coordinator

- U5. (audit #7, #9, #10) Delete `FlowWording::ofRegistration()`, `getRegisteredBeforeSendInterceptors()` (both
  declarations) and `ExecutionHint::KEYS`.
- U6. (audit #8) Delete `bin/check-build-flow.php`; workflow.md names `php bin/build-flow.php check`; every other
  mention (skills, checklist, `DETERMINISTIC_CHECKS`) follows.
- U7. (audit #12) Delete the two `sequenceDiagram` assertions.
- U8. (audit #11) In the `docs/reviews` files that stay, replace each reference to a file under `var/` with the
  figure it stood for (score, test count, command), or drop the reference when the sentence stands without it.
- U9. (disabled-effect A1, A2, B2) Check `upgrade-2.0.md` against what a 1.x application or integrator meets
  whatever the level: `TracingConfiguration`'s public constructor gaining a required level, a custom tracer
  registered through the public tracer reference having to implement `startDetachedSpan()` /
  `currentApplicationSpan()`, `GatheredGroupStore::partsOf()`. For each: an upgrade entry (Before / Now / How to
  adapt) if a 1.x application can meet it, or the reason it cannot. No entries for changes between unreleased 2.0
  states.
