# Review findings: execution recording, pre-PR reviews P1 to P22 (head 38d75b793)

Five fresh sessions reviewed `main...HEAD`: a Fable 5.2 review of the recording (F), and the pre-PR sessions
Guidelines (G), Breaking changes (B), Behaviour (H), Developer experience (X). The list below is what they found,
deduplicated. Three items wait for the maintainer and are not part of this round: whether the always-on
`MessageFlowBuildCompilerPass` under an Enterprise licence stays (B, G, H all raised it), whether reading a
recording gets a public `Api/` reader or the option leaves `Api/` (X; it also decides G's blocking finding that the
recording tests read through the `src/` class `FlowExecution`), and widening the scenario catalogue. Leave those
three alone.

## Blocking

- P1 (H, F9). Recording changes what a flow throws: a hint value that is not valid UTF-8 raises `JsonException`
  (`Tracing/Execution/ExecutionHint.php:54`, `ExecutionRecordingTracer.php:221`); reproduced with
  `publishEventWithRouting("order.\xC3\x28", 'x')`, fine with recording off. `RecordedAggregateStep.php:51-52` and
  `RecordedAggregateRepository.php:32` pass `json_encode()` without the throw flag into a `string` (TypeError, or an
  empty hint). Make every encode of recorded data unable to throw and unable to lose the hint. Tests: a non-UTF-8
  routing key, a collector keyed by a binary value, a binary aggregate identifier; same outcome on and off.
- P2 (G, rule D4). Commits cite R1 to R6 and no committed file defines them. Commit the lists as said above.
- P3 (F1, H). `RecordedGatheredGroupStore.php:38` records "group waits for its other parts" whenever the release
  callback is not called. Both stores also return silently for a part arriving after the group was released and
  for a duplicate part (`InMemoryGatheredGroupStore.php:137-147`, `DbalGatheredGroupStore.php:123-133`);
  reproduced with the fixture of `GatheringTest::test_duplicate_part_is_not_counted_twice`. Record what happened:
  waiting, late part ignored, duplicate ignored. Never state an outcome the recorder did not observe.

## Should fix: the recording says something wrong or hides the cause

- P4 (F5). `ReplyRecording.php:49,93`: the routing slip hint compares the request's slip, but
  `RequestReplyProducer.php:46` reads the reply's slip. In `orchestrator-gateway-starting-the-flow` the slip the
  orchestrator returned never appears, and an unchanged slip prints as `X → X`.
- P5 (F2). `GatheredPartitionBatchDispatcher.php:69` stamps group id, part number, size and gather channel on each
  batch message; the send records only the routing slip, so the #771 comparison calls the two sends unchanged and
  the later part report has no visible cause. Record the stamp as a critical hint on the send.
- P6 (F3, F4). Critical flags: `by: GatheredPartitionBatchDispatcher` must be critical; `chosen by: licence` names
  no alternative (say what it was chosen over, or make it non-critical); `got 0 events` and `found nothing` decide
  the flow and are hidden (`FlowExecution.php:171` marks an outcome critical only when it starts with "throws");
  `throws X` repeats on every enclosing step: mark where it was thrown and where it was caught, not every layer.
- P7 (F6, X). `ReplyRecording.php:79`: `no hand-over ↳ the handler returned nothing` is also emitted for the bus
  router, where "the handler" is the router, even when the user's handler returned a value (243 occurrences).
  Name the owner of the step.
- P8 (F7). `ChannelSendRecording.php:99`: `put on channel X` for a direct channel reads as queueing while the
  subscriber runs inline under it. Use different wording for a direct channel.
- P9 (F8, X). `ChannelSendRecording.php:65`: the key `stopped` sits on the release step, which is the one that
  is not stopped, and names an internal class.
- P10 (F12). Not recorded yet: projecting an event to its handler (`RecordedProjectorExecutor.php:21` passes
  through, so `route to course_seats.defined.target` appears from nowhere); the instant-retry decision
  (`InstantRetryInterceptor.php:56-77`, the second attempt has no hint that it is a retry); a routing slip set by
  gateway header builders (`DistributedBusWithServiceMapModule.php:142`); why `DistributedOutboundRouter` chose its
  channel; the batched release in `CollectorSenderInterceptor.php:87-90`.
- P11 (F14, F15, F16, X). Hint vocabulary: four values are still sentences (`RecordedGatheredRelease.php:33`,
  "held until the handler ends, the put did not run", "returned string, no output channel, routing slip or reply
  channel", "failed with …, handled by its channel interceptors without failing the flow"); `result` carries six
  kinds of thing; `runs` and `read from` have three meanings each (`runs in` for where); `by` beside `chosen by`;
  roles `presend` and `before send` name one phase; role `step` says nothing; `reply to the reply channel of the
  request` with an identical hint; `chosen by: part report by GatheringPartCompletion` is a tautology.
- P12 (F10, F11, F17, X). Comparison: the opening "Now:" paragraph of the change document is one 70-word sentence
  stating a clause twice, explains the tool ("the span shows only…"), and mentions neither the opened group nor
  the gatherer runs; a hint change prints non-critical hints the default text never shows and drops the step's
  unchanged critical hints (`FlowExecutionChange.php:300-304`); two processes that differ only in a part number
  print in full twice; each new consumer run prints 18 preamble lines before its first distinctive step.
- P13 (F18). Nesting of `before send` implies wrapping while `SendingInterceptorAdapter.php:57-68` is a loop, and
  the receive steps are synthesised, not observed. Either observe them, or say in the recording format's
  description (not in each step) how a loop is drawn.

## Should fix: rules and usage

- P14 (X). `TracingModule.php:82`: `withExecutionRecording()` with no span recorder returns on the "tracing
  disabled" branch: nothing recorded, no error. Refuse at bootstrap naming `withSpanRecorder()`, with a message
  test (or make the option work alone; say which you chose and why).
- P15 (X). `TracingModule.php:99`: the refusal names `OpenTelemetryTracer`, a class the user never registered.
  Name the cause the user controls (a `TracerProviderInterface` was provided) and the way out.
- P16 (X, G9). `compare` defaults to JSON while the workflow reference says Markdown; presentations are named
  `A|B|C|BUILD`. Make readable Markdown the default, give presentations names that say what they are, list all of
  them in the console description, update the reference, and add how to add a catalogue scenario.
- P17 (G3). Recording services hold per-message state (`ExecutionRecordingTracer.php:29-46`,
  `ChannelSendRecording.php:36`, `ReplyRecording.php:26`). Record the deviation where known deviations live and
  add a leak test: a failed process leaves nothing for the next one.
- P18 (G4). `RecordExecutionCompilerPass.php:192` uses `new ReflectionMethod`; take the answer from
  `InterfaceToCallRegistry`, or state in the report why the registry cannot give it.
- P19 (G5, G8). Prose in docblocks (`Configuration.php:49,169`, `FlowExecution.php:90`,
  `InterceptedChainDefinition.php`, and the `key => meaning` tails across `MessageFlow*`, `FlowSnapshot*`,
  `FlowScenarioChange`); two stacked docblocks at `ExecutionRecordingTracer.php:119-125`.
- P20 (G6, G7, G10). Assertion messages in `BuildFlowLedgerComparisonTest.php:182-185`,
  `MessageFlowBuildWiringTest.php:128`, `ExecutionRecordingExplanationTest.php:320`; `expectException` naming a
  `src/` class in `BuildFlowComparisonTest.php:202,214,228` and `BuildFlowSnapshotTest.php:171` (assert the message
  only); positional four-argument calls at `MessageFlowBuildCompilerPass.php:49` and
  `BuildFlowConsoleCommands.php:23`.
- P21 (H). No recorded gathered flow over the DBAL group store, which encodes the original message and calls
  the callback with 3 arguments, not 4. Add one in packages/Dbal.
- P22 (B). `TracingConfiguration.php:20`: give `$executionRecording` the default `false`.

