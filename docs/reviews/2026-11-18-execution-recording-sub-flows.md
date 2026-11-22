# Review findings: execution recording, a consumer run is a sub-flow of the flow that sent its message (F1 to F4)

The maintainer, reading the #771 recording: "why receive backfill is a separate flow. It comes from messages
created during backfill. Tracing does know correlation so should be able to show related flows and sub flows."

Today the recording is a flat list of processes. The relation exists only as text in two hints (`sent by: process
2, …` on the first step of the receiving process, `taken by: process 3, …` on the send). Make the relation part of
the recording's structure.

- F1. Structure. Each process records which process and which step of it sent the message it handles, taken from
  what tracing already correlates (the trace context carried on the message), not from matching labels or channel
  names. A process with no sender is a flow; a process with one is a sub-flow of it. Report how the link is
  derived, and what happens for: a message sent by a process outside the recording, a delayed or retried
  delivery (received more than once), a message published to several consumers, a distributed bus hop between two
  applications in one test. A link the recorder cannot observe is left out, never guessed.
- F2. Stored snapshot and JSON export. The link is data (`sent by` process and step), not parsed back out of hint
  text. The existing `sent by` / `taken by` hints stay as the readable form and are derived from that data.
- F3. Default text. Flows print in run order; each sub-flow prints under the flow that sent its message, indented
  as a sub-flow, in the order its messages were sent, so #771 reads: process 1; process 2 with sub-flows 3 (with
  sub-flow 5) and 4 (with sub-flow 6). Process numbers keep saying the order in which they ran. Titles stay short.
- F4. Comparison. A sub-flow that is new, gone, or attached to a different sender is a change the Markdown
  comparison states ("2 new sub-flows of `receive backfill, …executeBatch`"), and the before/after presentation
  keeps the nesting.

## Behaviour review of the sub-flow work (head 204212f72)

F1 to F4 verified: the link is read from the trace parent and is right for two channels, two sends to one channel,
delayed messages, instant retry, an unrecorded sender, a distributed hop and a sub-flow of a sub-flow; `check` is
deterministic.

- F5 (should fix). `InMemoryQueueAcknowledgeInterceptor.php:47`: the in-memory acknowledge callback keeps the
  message from before `TracingChannelInterceptor` added its trace context, so a redelivery after a failure
  arrives without it and is recorded as an unrelated flow (baseline
  `failing-handler-whose-error-channel-send-fails.json`, process 3 has no `sentBy`). Over DBAL the same scenario
  records the redelivery as a sub-flow, so the structure differs per channel. Requeue the message as it was put
  on the channel. This changes what an in-memory redelivery carries with recording off too, so: say in the report
  exactly which headers a redelivered message gains, prove with a test that nothing else about redelivery changes
  (payload, delivery count, order, acknowledgement), and prove the redelivery is a sub-flow on in-memory and on
  DBAL. If the fix needs more than the requeued message carrying the headers it was sent with, stop and ask.
- F6 (should fix). `ExecutionRecordingSubFlowTest.php:42`: the "two consumers" test uses one channel. Add an event
  published to asynchronous handlers on two different channels, each sub-flow linked to its own send step, also
  when the channels are consumed in reverse order.
- F7 (nit). `FlowComparisonMarkdown.php:119`: `array_search` on titles nests a sub-flow under the first process
  with the sender's title; when a sender is removed and re-added under the same title, the unchanged sub-flow
  prints under the removed copy. Use the after-side sender.
- F8 (eval). `bin/test-agent-skills framework-dev-environment`: `red-ci-check` scored 0.67 (1 run) and 0.78
  (3 runs), below 0.8; failing grader `rule-e4`, while the judged answer says "fix in code, never add to the
  baseline". The branch changed only `references/workflow.md` of that skill. Run the same case with `RUNS=3` on
  `main` (a separate `git worktree`, removed afterwards) and report both scores. If main scores the same, report
  it as pre-existing and change nothing; if main passes, find what in this branch's change lowers it.
