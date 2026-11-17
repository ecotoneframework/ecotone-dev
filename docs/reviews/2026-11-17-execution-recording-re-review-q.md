# Review findings: execution recording, Guidelines and Behaviour re-reviews Q1 to Q11 (head 2a4be268d)

Two fresh sessions re-reviewed `main...HEAD` after P1 to P22: Guidelines (G) and Behaviour (H). Both confirm the
earlier findings they checked are fixed, and G confirms the P18 reasoning holds. The list below is what they found
new. Still waiting for the maintainer and not part of this round: the always-on `MessageFlowBuildCompilerPass`, a public `Api/`
reader for a recording, widening the catalogue, and whether per-message state in the recording services is
accepted as a deviation (so do not move row 3h or redesign the state; Q6 only makes the row true).

## Should fix: the recording says something wrong

- Q1 (H). `Tracing/Execution/RecordedGatheredGroupStore.php:38,73`: `recordedPartsOf()` is called after
  `startStep()` but outside the try. When it throws, the "record part" step never closes, and
  `ExecutionRecordingTracer::finish`/`closeEntry` (around line 249) remove only the entry, so later processes nest
  under the stale step. Reproduced over DBAL by dropping `ecotone_gathering_parts` before `run()`: the exception is
  the same on and off, but with recording on the acknowledge becomes its own process and the next
  `sendCommandWithRouting` process loses its top steps. Put both reads inside the failure handling, make closing
  an entry drop every step still open under it, and add a test with a store read that fails (through a seam the
  application owns).
- Q2 (G). `RecordedGatheredGroupStore.php:68`: `recordedPartsOf` returns only `open: bool`, so a part arriving at
  a discarded group (`InMemoryGatheredGroupStore.php:213`, `DbalGatheredGroupStore.php:206`) is recorded as "group
  already released". Return the state itself and record what it was.
- Q3 (H). `Test/Flow/Snapshot/FlowExecution.php:266`: a retry is guessed from a sibling step with the same label
  after one that failed. Reproduced: a handler catches the failure of `sendWithRouting('stock.reserve', a)` and
  then sends to warehouse b; recorded as `retry: attempt 2 after RuntimeException`. Record a retry where it
  happens (the instant-retry interceptor or its header), never by matching labels.
- Q4 (H). `FlowScenarioChange.php:696` with the critical-only filter in `withProcessesNamedByTitle` (~719): the
  before/after equality runs on critical hints only. Reproduced: a handler with an output channel returning array
  before and string after; baselines differ, compare says nothing changed. Detect a change on all hints; display
  the critical ones and say that detail hints changed (how many, and how to print them).

## Should fix: proof

- Q5 (G, H). The duplicate, late, discarded and not-stored outcomes are proved in memory only, and "late part
  ignored" and "part not stored" have no test at all. Prove every outcome, and prove that the in-memory and DBAL
  `recordedPartsOf` agree with one shared suite (storage rule 9).
- Q6 (G). `known-deviations.md` row 3h is incomplete: it omits `ExecutionRecordingTracer::$order` (never reset,
  so not bounded by the call that opens it), `$collectors`, `$flowTestingFailures`, `$awaitedReceive`,
  `$receiveEntry` and `$hintsOfOpenSteps` (`ExecutionRecordingTracer.php:31-48`). List every field with what
  bounds it, reset `$order` per process or state why it runs across processes, and extend the leak test beyond a
  failing handler (a failing send, a failing store read from Q1).
- Q7 (H). `bin/test-agent-skills` scored `framework-dev-environment/red-ci-check` 0.67 (grader rule-e4, votes
  PASS FAIL FAIL), below the threshold; the other four cases scored 1.00. Rerun that case with `RUNS=3`. If it
  still fails, find what in the changed `references/workflow.md` causes it and fix that; report the scores.

## Nits

- Q8 (G). `TracerReference.php:12`, `TracingModule.php:102`: two prose fields travel through the extension object
  and the refusal rewrites one with `preg_replace`. Pass a type and let the module word the message.
- Q9 (H). `FlowScenarioChange.php` ~529: the notes that a statement was read from the wiring, not observed, were
  removed, so the summary states it as observed. Keep the summary short but do not claim an observation.
- Q10 (H). `withoutRepeats` (~545) makes "sends X" once and three times read the same in the Before/Now summary;
  keep the count. `alikeOf` prints "except:" followed by nothing when the lines are identical.
- Q11 (H). `FlowSnapshot.php:285`: `ecotone:build-flow:record` throws `JsonException` on a span name that is not
  valid UTF-8 (also with recording off). Encode it the way P1 does.

## Second Behaviour re-review (head 58fc9ef0b)

Q1 to Q5 verified as fixed (Q3 and Q4 reproduced by the session, the shared suite run on PostgreSQL and MySQL);
"part not stored" confirmed unreachable. No blocking item.

- Q12 (should fix). `FlowScenarioChange.php:438`, `FlowComparisonMarkdown.php:47-48`: since Q4, a change in detail
  hints only (a handler returning string instead of array) makes the summary say "1 of 1 scenarios changes its
  message path" and "Now: 1 process runs other steps", while the section below says "runs the same steps, 2 of
  them with other hints". When no step was added, removed or moved, word the summary the way
  `FlowWording::ofChangedProcess` does and do not call it a message path change. Assert `summary['now']` in the
  detail-hint test of `BuildFlowExecutionTest`.
- Q13 (nit). Only the command bus retry has a committed test. Add one for an asynchronous endpoint retry (attempts
  2 and 3 recorded) and one for `#[InstantRetry]` on a business interface.
- Q14 (nit). `TracingModule.php:103`: since Q8 the refusal says "spans go to the tracer it provides" and no longer
  says which kind of tracer. Keep naming what the user provided (the `TracerProviderInterface` service) and say
  where the spans go in terms the user recognises, still without naming a class they never registered.
- Q15 (nit). `RecordedGatheredGroupStore.php:40`: the recording's own `recordedPartsOf` read after a successful
  `completePart` can throw where the flow would not. A failed read of the recorder must not fail the flow: record
  the outcome as not observed and let the flow go on. Test it through a seam the application owns.
  Not done: no userland seam separates the recorder's `recordedPartsOf` read from the flow's own reads of the same
  tables (no application code runs between the part write and the read), and a test matched on Ecotone's SQL text
  would break framework-testing rule 10, so the code is left as it is.
